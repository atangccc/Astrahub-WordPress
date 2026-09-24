<?php
/**
 * AstraHub 多节点健康探针与自动选择器。
 *
 * 完整移植 Halo 端 AstraHubNodeSelector 的核心机制：
 *   - 内置 5 个公共 Hub 节点（默认 https://astra.aobp.cn 等）。
 *   - 每 1 小时（SELECTION_TTL_SECONDS）自动对所有节点 GET /healthz。
 *   - 选第一个 healthy 且 EWMA 延迟最低的节点作为当前节点。
 *   - Hub 请求遇到 408/502/503/504 或网络错误时自动 failover 到下一个健康节点。
 *   - 暴露 status_snapshot() / refresh_nodes() / select_node() 三个接口供 REST 路由调用。
 *
 * 节点状态通过 wp_options 持久化（option_key = wp_astrahub_node_state），
 * 避免跨 PHP 进程丢失。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Node_Selector {

    /**
     * 内置公共 Hub 节点（与 Halo AstraHubNodeSelector.DEFAULT_NODES 完全一致）。
     *
     * @var string[]
     */
    const DEFAULT_NODES = array(
        'https://astra.zzrbk.xyz',
        'https://astra.rinty.xyz',
        'https://astra.fryfries13.cn',
        'https://astra.lygalaxy.cn',
        'https://astra.aobp.cn',
    );

    /**
     * 节点状态持久化 option key。
     */
    const OPTION_KEY = 'wp_astrahub_node_state';

    /**
     * 健康检查路径。
     */
    const PROBE_PATH = '/healthz';

    /**
     * 探针超时（秒）。
     */
    const PROBE_TIMEOUT = 2;

    /**
     * 节点选择有效期（秒）——60 分钟。
     */
    const SELECTION_TTL = 3600;

    /**
     * EWMA 平滑系数（越小越稳定，越大越敏感）。
     */
    const EWMA_ALPHA = 0.25;

    /**
     * Hub 客户端（用于 send() 带 failover 发请求）。
     *
     * @var WP_AstraHub_Hub_Client|null
     */
    private $hub_client;

    /**
     * 构造。
     *
     * @param WP_AstraHub_Hub_Client|null $hub_client Hub 客户端（可选，延迟注入）。
     */
    public function __construct( $hub_client = null ) {
        $this->hub_client = $hub_client;
    }

    /**
     * 延迟注入 Hub 客户端（解决循环依赖）。
     *
     * @param WP_AstraHub_Hub_Client $hub_client Hub 客户端。
     */
    public function set_hub_client( WP_AstraHub_Hub_Client $hub_client ) {
        $this->hub_client = $hub_client;
    }

    // ========== 对外 API ==========

    /**
     * 当前选中节点（可能触发探针）。
     *
     * @return string 节点基础 URL（已去尾斜杠），空串表示无健康节点。
     */
    public function current_node() {
        $snapshot = $this->read_state();
        $now      = time();

        // 如果当前节点在 TTL 内，且存在，直接返回。
        if ( ! empty( $snapshot['currentNode'] )
            && ! empty( $snapshot['selectedAt'] )
            && ( $now - (int) $snapshot['selectedAt'] ) < self::SELECTION_TTL ) {
            return (string) $snapshot['currentNode'];
        }

        // 需要刷新：同步跑一轮探针。
        $this->refresh_nodes();

        $snapshot = $this->read_state();
        return ! empty( $snapshot['currentNode'] ) ? (string) $snapshot['currentNode'] : '';
    }

    /**
     * 节点状态快照（供 admin UI 展示）。
     *
     * @return array{currentNode:string,selectedAt:string,expiresAt:string,checking:bool,nodes:array}
     */
    public function status_snapshot() {
        $snapshot = $this->read_state();
        $now      = time();

        $current    = (string) ( $snapshot['currentNode'] ?? '' );
        $selected   = (int) ( $snapshot['selectedAt'] ?? 0 );
        $expires_at = $selected > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $selected + self::SELECTION_TTL ) : '';
        $selected_at = $selected > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $selected ) : '';

        $nodes = array();
        foreach ( $this->nodes() as $node ) {
            $state = isset( $snapshot['nodes'][ $node ] ) && is_array( $snapshot['nodes'][ $node ] )
                ? $snapshot['nodes'][ $node ]
                : array();
            $latency = -1;
            if ( isset( $state['lastProbeMs'] ) && $state['lastProbeMs'] >= 0 ) {
                $latency = (int) $state['lastProbeMs'];
            } elseif ( isset( $state['ewmaMs'] ) && $state['ewmaMs'] >= 0 ) {
                $latency = (int) round( (float) $state['ewmaMs'] );
            }
            $nodes[] = array(
                'url'          => $node,
                'status'       => (string) ( $state['probeStatus'] ?? 'unchecked' ),
                'latencyMs'    => $latency,
                'httpStatus'   => (int) ( $state['httpStatus'] ?? 0 ),
                'lastCheckedAt' => ! empty( $state['lastCheckedAt'] )
                    ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $state['lastCheckedAt'] ) : '',
                'lastError'    => (string) ( $state['lastError'] ?? '' ),
                'selected'     => $node === $current,
            );
        }

        return array(
            'currentNode' => $current,
            'selectedAt'  => $selected_at,
            'expiresAt'   => $expires_at,
            'checking'    => ! empty( $snapshot['checking'] ),
            'nodes'       => $nodes,
        );
    }

    /**
     * 对所有内置节点跑一轮 /healthz 探针。
     *
     * @return array 新的状态快照。
     */
    public function refresh_nodes() {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // 单次 admin-AJAX 请求的 max_execution_time 通常 30-60s，
            // 5 个节点 × 2s = 最多 10s，完全跑得下。
        }

        $snapshot = $this->read_state();
        $snapshot['checking'] = true;
        $this->write_state( $snapshot );

        $nodes = $this->nodes();
        $probe_results = array();

        foreach ( $nodes as $node ) {
            $probe_results[ $node ] = $this->probe_node( $node );
        }

        $this->select_best_node( $probe_results );

        $snapshot               = $this->read_state();
        $snapshot['checking']   = false;
        $snapshot['nodes']      = array_merge( $snapshot['nodes'], $probe_results );
        $this->write_state( $snapshot );

        return $this->status_snapshot();
    }

    /**
     * 手动锁定某个节点（必须是内置列表中的，且最近一次探针结果 healthy）。
     *
     * @param string $raw_node 节点 URL。
     * @return array 新的状态快照。
     * @throws RuntimeException 节点不在内置列表或最近未通过探针。
     */
    public function select_node( $raw_node ) {
        $node = $this->normalize_base_url( $raw_node );
        if ( null === $node || ! in_array( $node, $this->nodes(), true ) ) {
            throw new RuntimeException( '节点不在内置列表中' );
        }

        $snapshot = $this->read_state();
        $state    = isset( $snapshot['nodes'][ $node ] ) ? $snapshot['nodes'][ $node ] : array();
        $now      = time();

        if ( ! isset( $state['probeStatus'] ) || 'healthy' !== $state['probeStatus']
            || empty( $state['lastCheckedAt'] )
            || ( $now - (int) $state['lastCheckedAt'] ) >= self::SELECTION_TTL ) {
            throw new RuntimeException( '该节点未通过最近一次本地检测，请先重新检测' );
        }

        $snapshot['currentNode'] = $node;
        $snapshot['selectedAt']  = $now;
        $this->write_state( $snapshot );

        return $this->status_snapshot();
    }

    /**
     * 记录一次成功请求（更新 EWMA）。
     *
     * @param string $node        节点 URL。
     * @param float  $duration_ms 耗时（毫秒）。
     */
    public function record_success( $node, $duration_ms ) {
        $node = $this->normalize_base_url( $node );
        if ( null === $node ) {
            return;
        }
        $snapshot = $this->read_state();
        $state    = isset( $snapshot['nodes'][ $node ] ) ? $snapshot['nodes'][ $node ] : array();
        $sample   = max( 0, (float) $duration_ms );
        $ewma     = isset( $state['ewmaMs'] ) ? (float) $state['ewmaMs'] : -1;
        $state['ewmaMs'] = $ewma < 0
            ? $sample
            : self::EWMA_ALPHA * $sample + ( 1 - self::EWMA_ALPHA ) * $ewma;
        $snapshot['nodes'][ $node ] = $state;
        $this->write_state( $snapshot );
    }

    /**
     * 记录一次失败请求（仅日志，不自动切换）。
     *
     * @param string $node   节点 URL。
     * @param string $reason 失败原因。
     */
    public function record_failure( $node, $reason = '' ) {
        $node = $this->normalize_base_url( $node );
        if ( null === $node ) {
            return;
        }
        // 仅日志；和 Halo 端一致，不做自动切换。
        error_log( '[AstraHub] node request failed node=' . $node . ' reason=' . self::safe_error( $reason ) );
    }

    /**
     * 判断状态码是否属于应该自动 failover 的类型。
     *
     * @param int $status_code HTTP 状态码。
     * @return bool
     */
    public static function should_failover( $status_code ) {
        $s = (int) $status_code;
        return ( $s >= 300 && $s < 400 )
            || 408 === $s
            || 502 === $s
            || 503 === $s
            || 504 === $s;
    }

    /**
     * 获取所有候选节点（含 fallback 顺序）。
     *
     * @return string[]
     */
    public function ordered_candidates() {
        $snapshot = $this->read_state();
        $current  = ! empty( $snapshot['currentNode'] ) ? (string) $snapshot['currentNode'] : '';

        $nodes = $this->nodes();
        usort( $nodes, function ( $a, $b ) use ( $current, $snapshot ) {
            // 当前节点放最后（优先探测其他），再按 EWMA 升序。
            $a_current = ( $a === $current ) ? 1 : 0;
            $b_current = ( $b === $current ) ? 1 : 0;
            if ( $a_current !== $b_current ) {
                return $b_current <=> $a_current; // 非当前节点先。
            }
            $ewma_a = isset( $snapshot['nodes'][ $a ]['ewmaMs'] ) ? (float) $snapshot['nodes'][ $a ]['ewmaMs'] : INF;
            $ewma_b = isset( $snapshot['nodes'][ $b ]['ewmaMs'] ) ? (float) $snapshot['nodes'][ $b ]['ewmaMs'] : INF;
            return $ewma_a <=> $ewma_b;
        } );
        return $nodes;
    }

    // ========== 内部实现 ==========

    /**
     * 返回内置节点列表（已去重、规范化）。
     *
     * @return string[]
     */
    private function nodes() {
        $normalized = array();
        foreach ( self::DEFAULT_NODES as $node ) {
            $n = $this->normalize_base_url( $node );
            if ( null !== $n && ! in_array( $n, $normalized, true ) ) {
                $normalized[] = $n;
            }
        }
        return $normalized;
    }

    /**
     * 探测单个节点的 /healthz。
     *
     * @param string $node 节点 URL。
     * @return array 节点状态条目。
     */
    private function probe_node( $node ) {
        $started = microtime( true );
        $url     = $node . self::PROBE_PATH;

        $args = array(
            'timeout'     => self::PROBE_TIMEOUT,
            'redirection' => 0, // 不跟随重定向（和 Halo 一致）。
            'headers'     => array( 'Cache-Control' => 'no-store' ),
            'sslverify'   => true,
        );

        $response = wp_remote_get( $url, $args );
        $duration = (int) round( ( microtime( true ) - $started ) * 1000 );

        if ( is_wp_error( $response ) ) {
            $msg = self::safe_error( $response->get_error_message() );
            error_log( '[AstraHub] node probe failed node=' . $node . ' reason=' . $msg );
            return array(
                'probeStatus'   => 'unhealthy',
                'httpStatus'    => 0,
                'lastProbeMs'   => $duration,
                'lastCheckedAt' => time(),
                'lastError'     => $msg,
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 === $status ) {
            return array(
                'probeStatus'   => 'healthy',
                'httpStatus'    => 200,
                'lastProbeMs'   => $duration,
                'lastCheckedAt' => time(),
                'lastError'     => '',
            );
        }

        return array(
            'probeStatus'   => 'unhealthy',
            'httpStatus'    => $status,
            'lastProbeMs'   => $duration,
            'lastCheckedAt' => time(),
            'lastError'     => 'HTTP ' . $status,
        );
    }

    /**
     * 根据探针结果选出最佳节点。
     *
     * @param array $probe_results node => state array。
     */
    private function select_best_node( array $probe_results ) {
        $snapshot = $this->read_state();
        $merged   = array_merge( isset( $snapshot['nodes'] ) ? $snapshot['nodes'] : array(), $probe_results );

        // 先看 healthy 节点，取 latency 最低的。
        $best      = '';
        $best_lat  = PHP_INT_MAX;
        foreach ( $this->nodes() as $node ) {
            $state = isset( $probe_results[ $node ] ) ? $probe_results[ $node ] : ( $merged[ $node ] ?? array() );
            if ( empty( $state['probeStatus'] ) || 'healthy' !== $state['probeStatus'] ) {
                continue;
            }
            $lat = isset( $state['lastProbeMs'] ) && $state['lastProbeMs'] >= 0
                ? (int) $state['lastProbeMs']
                : PHP_INT_MAX;
            if ( $lat < $best_lat ) {
                $best_lat = $lat;
                $best     = $node;
            }
        }

        if ( '' !== $best ) {
            $snapshot['currentNode'] = $best;
            $snapshot['selectedAt']  = time();
            error_log( '[AstraHub] node selected node=' . $best . ' ttlSeconds=' . self::SELECTION_TTL );
        } else {
            $previous = ! empty( $snapshot['currentNode'] ) ? $snapshot['currentNode'] : '';
            if ( '' !== $previous && in_array( $previous, $this->nodes(), true ) ) {
                // 没有新的 healthy 节点，保持上次选中的那个（除非也变 unhealthy）。
                $prev_state = $probe_results[ $previous ] ?? array();
                if ( empty( $prev_state['probeStatus'] ) || 'unhealthy' === $prev_state['probeStatus'] ) {
                    $snapshot['currentNode'] = '';
                    $snapshot['selectedAt']  = 0;
                    error_log( '[AstraHub] no healthy node selected candidates=' . implode( ',', $this->nodes() ) );
                } else {
                    error_log( '[AstraHub] node probe found no replacement; keeping last verified node=' . $previous );
                }
            } else {
                $snapshot['currentNode'] = '';
                $snapshot['selectedAt']  = 0;
                error_log( '[AstraHub] no healthy node selected candidates=' . implode( ',', $this->nodes() ) );
            }
        }

        $snapshot['nodes'] = $merged;
        $this->write_state( $snapshot );
    }

    /**
     * 读持久化状态。
     *
     * @return array
     */
    private function read_state() {
        $raw = get_option( self::OPTION_KEY );
        if ( is_array( $raw ) ) {
            return $raw;
        }
        return array(
            'currentNode' => '',
            'selectedAt'  => 0,
            'nodes'       => array(),
            'checking'    => false,
        );
    }

    /**
     * 写持久化状态。
     *
     * @param array $state 状态。
     */
    private function write_state( array $state ) {
        update_option( self::OPTION_KEY, $state, false ); // autoload = false，避免每次 wp-load 都读。
    }

    /**
     * 规范化节点 URL（对齐 Halo normalizeBaseUrl）。
     *
     * @param string $raw 原始 URL。
     * @return string|null 规范化后的 URL，无效则 null。
     */
    public static function normalize_base_url( $raw ) {
        $value = is_string( $raw ) ? rtrim( $raw, " \t\n\r\0\x0B/" ) : '';
        if ( '' === $value ) {
            return null;
        }
        $parts = wp_parse_url( $value );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return null;
        }
        $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
        if ( 'https' !== $scheme && 'http' !== $scheme ) {
            return null;
        }
        // 不允许 query / fragment。
        if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return null;
        }
        return $value;
    }

    /**
     * 截断错误信息至 240 字符以内。
     *
     * @param string $value 错误信息。
     * @return string
     */
    private static function safe_error( $value ) {
        $msg = is_string( $value ) ? trim( $value ) : '连接失败';
        if ( '' === $msg ) {
            return '连接失败';
        }
        if ( strlen( $msg ) > 240 ) {
            return substr( $msg, 0, 240 );
        }
        return $msg;
    }
}
