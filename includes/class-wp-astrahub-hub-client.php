<?php
/**
 * AstraHub Hub HTTP 客户端。
 *
 * 负责：
 *   - 拼接 Hub 基础地址（WP_ASTRAHUB_HUB_BASE_URL）与请求路径。
 *   - 对需要鉴权的请求用 apiKey 进行 HMAC 签名并附加 X-BP-* 头。
 *   - 用 wp_remote_* 发起请求，统一返回结构。
 *
 * 签名所用 PATH 为「解码后路径」（与 Go r.URL.Path 对齐）；带 query 的 GET 请求，
 * query 不参与签名，仅拼接到实际请求 URL。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Hub_Client {

    /**
     * 凭据存储。
     *
     * @var WP_AstraHub_Credential_Store
     */
    private $credentials;

    /**
     * 多节点选择器（可选，有则自动 failover）。
     *
     * @var WP_AstraHub_Node_Selector|null
     */
    private $node_selector;

    /**
     * 请求超时（秒）。
     *
     * @var int
     */
    private $timeout = 15;

    /**
     * 构造。
     *
     * @param WP_AstraHub_Credential_Store  $credentials   凭据存储。
     * @param WP_AstraHub_Node_Selector|null $node_selector 多节点选择器（可选）。
     */
    public function __construct(
        WP_AstraHub_Credential_Store $credentials,
        $node_selector = null
    ) {
        $this->credentials   = $credentials;
        $this->node_selector = $node_selector;
    }

    /**
     * 设置 / 切换节点选择器（主要用于依赖注入时机晚于 Hub_Client 构造的场景）。
     *
     * @param WP_AstraHub_Node_Selector $node_selector 节点选择器。
     */
    public function set_node_selector( WP_AstraHub_Node_Selector $node_selector ) {
        $this->node_selector = $node_selector;
    }

    /**
     * Hub 基础地址（去尾部斜杠）。
     *
     * 优先用 NodeSelector 选中的节点，fallback 到编译期常量
     * WP_ASTRAHUB_HUB_BASE_URL（为了向后兼容 + 极端情况下的兜底）。
     *
     * @return string
     */
    public function base_url() {
        if ( $this->node_selector ) {
            $current = $this->node_selector->current_node();
            if ( '' !== $current ) {
                return $current;
            }
        }
        return rtrim( WP_ASTRAHUB_HUB_BASE_URL, '/' );
    }

    /**
     * 发起一个「无需站点签名」的请求（注册、申请签发码等）。
     *
     * @param string     $method  HTTP 方法。
     * @param string     $path    路径（以 / 开头）。
     * @param array|null $body    请求体（数组，将编码为 JSON）；GET 传 null。
     * @param array      $headers 额外头（如 X-BP-Register-Token / X-BP-Invitation-Code）。
     * @param array      $query   query 参数。
     * @return array{success:bool,status:int,body:array,raw:string,contentType:string,message:string}
     */
    public function request_public( $method, $path, $body = null, array $headers = array(), array $query = array() ) {
        return $this->dispatch( $method, $path, $body, $headers, $query, false );
    }

    /**
     * 发起一个「需要站点签名」的请求（推送、友链邀请、读取代理等）。
     *
     * @param string     $method  HTTP 方法。
     * @param string     $path    路径（以 / 开头）。
     * @param array|null $body    请求体（数组，将编码为 JSON）；GET 传 null。
     * @param array      $headers 额外头。
     * @param array      $query   query 参数。
     * @return array{success:bool,status:int,body:array,raw:string,contentType:string,message:string}
     */
    public function request_signed( $method, $path, $body = null, array $headers = array(), array $query = array() ) {
        return $this->dispatch( $method, $path, $body, $headers, $query, true );
    }

    /**
     * 实际派发请求（带多节点 failover）。
     *
     * @param string     $method HTTP 方法。
     * @param string     $path   路径。
     * @param array|null $body   请求体。
     * @param array      $headers 额外头。
     * @param array      $query  query。
     * @param bool       $signed 是否签名。
     * @return array
     */
    private function dispatch( $method, $path, $body, array $headers, array $query, $signed ) {
        $method = strtoupper( $method );

        // body 序列化：仅当传入数组时编码为 JSON；GET 等无 body 用空串参与签名。
        $body_string = '';
        if ( null !== $body ) {
            $json_body = is_array( $body ) && empty( $body ) ? (object) array() : $body;
            $body_string = wp_json_encode( $json_body );
            if ( false === $body_string ) {
                return $this->fail( 400, '请求体编码失败' );
            }
        }

        $request_headers = array(
            'Accept' => 'application/json',
        );
        if ( null !== $body ) {
            $request_headers['Content-Type'] = 'application/json';
        }

        if ( $signed ) {
            $creds = $this->credentials->get_credentials();
            $site_id = trim( $creds['siteId'] );
            $api_key = trim( $creds['apiKey'] );
            if ( '' === $site_id || '' === $api_key ) {
                return $this->fail( 400, '缺少凭据（siteId/apiKey），请先注册站点' );
            }
            // 签名 PATH 用解码后的路径，不含 query。
            $signed_fields   = WP_AstraHub_Hub_Signer::sign_request( $method, $path, $body_string, $site_id, $api_key );
            $request_headers = array_merge( $request_headers, WP_AstraHub_Hub_Signer::to_headers( $signed_fields ) );
        }

        // 调用方额外头优先级最高（覆盖）。
        $request_headers = array_merge( $request_headers, $headers );

        // 确定候选节点列表（用于 failover）。
        $candidates = $this->candidate_nodes();
        $last_result = null;

        foreach ( $candidates as $node ) {
            $url = $node . $path;
            if ( ! empty( $query ) ) {
                $url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
            }

            $args = array(
                'method'  => $method,
                'headers' => $request_headers,
                'timeout' => $this->timeout,
            );
            if ( null !== $body ) {
                $args['body'] = $body_string;
            }

            $started = microtime( true );
            $response = wp_remote_request( $url, $args );
            $duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

            if ( is_wp_error( $response ) ) {
                $last_result = $this->fail( 502, '网络请求错误：' . $response->get_error_message() );
                if ( $this->node_selector ) {
                    $this->node_selector->record_failure( $node, $response->get_error_message() );
                }
                // 继续下一个候选。
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $raw    = (string) wp_remote_retrieve_body( $response );
            $content_type = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
            if ( false !== strpos( $content_type, ';' ) ) {
                $content_type = trim( strtok( $content_type, ';' ) );
            }
            $parsed = json_decode( $raw, true );
            if ( ! is_array( $parsed ) ) {
                $parsed = array();
            }

            if ( $status >= 200 && $status < 300 ) {
                // 成功：记录 EWMA 并返回。
                if ( $this->node_selector ) {
                    $this->node_selector->record_success( $node, $duration_ms );
                }
                return array(
                    'success' => true,
                    'status'  => $status,
                    'body'    => $parsed,
                    'raw'     => $raw,
                    'contentType' => $content_type,
                    'message' => '',
                );
            }

            // 需要 failover 的状态码：切下一个候选。
            // 注意：内联判断（不调 Node_Selector::should_failover 静态方法），避免类缺失时崩。
            $s = (int) $status;
            $should_failover = ( $s >= 300 && $s < 400 ) || 408 === $s || 502 === $s || 503 === $s || 504 === $s;
            if ( $this->node_selector && $should_failover ) {
                $this->node_selector->record_failure( $node, 'HTTP ' . $status );
                $last_result = array(
                    'success' => false,
                    'status'  => $status,
                    'body'    => $parsed,
                    'raw'     => $raw,
                    'contentType' => $content_type,
                    'message' => 'Hub 节点临时不可用（HTTP ' . $status . '），已尝试下一个节点',
                );
                continue;
            }

            // 非 failover 的错误（4xx 等业务错误）：直接返回，不切节点。
            return array(
                'success' => false,
                'status'  => $status,
                'body'    => $parsed,
                'raw'     => $raw,
                'contentType' => $content_type,
                'message' => $this->extract_error_message( $parsed, $status ),
            );
        }

        // 所有候选都失败了——返回最后一个错误。
        return null !== $last_result
            ? $last_result
            : $this->fail( 502, '所有 Hub 节点均不可达' );
    }

    /**
     * 本次请求可尝试的节点列表（failover 顺序）。
     *
     * @return string[]
     */
    private function candidate_nodes() {
        if ( $this->node_selector ) {
            return $this->node_selector->ordered_candidates();
        }
        return array( $this->base_url() );
    }

    /**
     * 从响应体提取错误信息（含中文错误码映射）。
     *
     * @param array $body   解析后的响应体。
     * @param int   $status 状态码。
     * @return string
     */
    private function extract_error_message( array $body, $status ) {
        $code    = '';
        $message = '';

        // Hub 标准格式：{ "error": { "code": "...", "message": "..." } }
        if ( isset( $body['error'] ) ) {
            if ( is_string( $body['error'] ) ) {
                $message = $body['error'];
            } elseif ( is_array( $body['error'] ) ) {
                $code    = isset( $body['error']['code'] ) ? (string) $body['error']['code'] : '';
                $message = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';
            }
        }

        // 优先用错误码映射；无映射则用原始 message 或状态码兜底
        if ( '' !== $code ) {
            return WP_AstraHub_Error_Codes::describe( $code, $message );
        }

        if ( '' !== $message ) {
            return $message;
        }

        // 兜底：body 顶层 message
        if ( isset( $body['message'] ) && is_string( $body['message'] ) && '' !== trim( $body['message'] ) ) {
            return $body['message'];
        }

        return '请求失败（HTTP ' . $status . '），请检查 Hub 地址配置或网络连接';
    }

    /**
     * 构造失败结果。
     *
     * @param int    $status  状态码。
     * @param string $message 信息。
     * @return array
     */
    private function fail( $status, $message ) {
        return array(
            'success' => false,
            'status'  => $status,
            'body'    => array(),
            'raw'     => '',
            'contentType' => '',
            'message' => $message,
        );
    }
}
