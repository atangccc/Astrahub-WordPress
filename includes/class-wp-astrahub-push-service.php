<?php
/**
 * 友链快照推送服务。
 *
 * 把采集器构造的 bp.site-links.v1 payload 签名后 POST 到 Hub 的
 * /v1/site-link-edges/push（与 Typecho 端 AstraHub_PushService 完全对齐）。
 * 结果（状态/时间/消息）写入 wp_options 供"最近同步"展示。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Push_Service {

    const GRAPH_PUSH_PATH = '/v1/site-link-edges/push';
    const OPTION_REPORT   = 'wp_astrahub_report_status';

    /**
     * Hub 客户端。
     *
     * @var WP_AstraHub_Hub_Client
     */
    private $hub_client;

    /**
     * 凭据存储。
     *
     * @var WP_AstraHub_Credential_Store
     */
    private $credentials;

    /**
     * 采集器。
     *
     * @var WP_AstraHub_Graph_Collector
     */
    private $collector;

    /**
     * 构造。
     *
     * @param WP_AstraHub_Hub_Client       $hub_client  Hub 客户端。
     * @param WP_AstraHub_Credential_Store $credentials 凭据存储。
     * @param WP_AstraHub_Graph_Collector  $collector   采集器。
     */
    public function __construct(
        WP_AstraHub_Hub_Client $hub_client,
        WP_AstraHub_Credential_Store $credentials,
        WP_AstraHub_Graph_Collector $collector
    ) {
        $this->hub_client  = $hub_client;
        $this->credentials = $credentials;
        $this->collector   = $collector;
    }

    /**
     * 立即推送图谱。
     *
     * @param string $reason 同步原因。
     * @return array{success:bool,status:int,message:string,pushedAt:string}
     */
    public function push_graph( $reason = 'manual' ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->record( false, 400, 'not registered yet', $reason );
        }

        $payload  = $this->collector->build_payload( $reason );
        $response = $this->hub_client->request_signed( 'POST', self::GRAPH_PUSH_PATH, $payload );

        // edges 端点：HTTP 2xx 且响应体 accepted=true 才算成功（对齐 Typecho 端）。
        $body     = is_array( $response['body'] ) ? $response['body'] : array();
        $accepted = ! empty( $body['accepted'] );
        $success  = $response['success'] && $accepted;

        return $this->record(
            $success,
            (int) $response['status'],
            $success ? 'pushed' : ( $response['message'] ?: 'push rejected' ),
            $reason
        );
    }

    /**
     * 读取最近同步状态。
     *
     * @return array
     */
    public function get_report_status() {
        $stored = get_option( self::OPTION_REPORT, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return wp_parse_args(
            $stored,
            array(
                'success'  => false,
                'status'   => 0,
                'message'  => '',
                'trigger'  => '',
                'pushedAt' => '',
                'updatedAt' => '',
            )
        );
    }

    /**
     * 记录一次推送结果并返回标准结构。
     *
     * @param bool   $success 是否成功。
     * @param int    $status  状态码。
     * @param string $message 信息。
     * @param string $reason  原因。
     * @return array
     */
    private function record( $success, $status, $message, $reason ) {
        $now = gmdate( 'Y-m-d\TH:i:s\Z' );
        $report = array(
            'success'   => $success,
            'status'    => $status,
            'message'   => $message,
            'trigger'   => $reason,
            'pushedAt'  => $success ? $now : $this->get_report_status()['pushedAt'],
            'updatedAt' => $now,
        );
        update_option( self::OPTION_REPORT, $report );
        return array(
            'success'   => $success,
            'status'    => $status,
            'message'   => $message,
            'pushedAt'  => $report['pushedAt'],
            'updatedAt' => $report['updatedAt'],
            'trigger'   => $report['trigger'],
        );
    }
}
