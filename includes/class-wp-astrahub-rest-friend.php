<?php
/**
 * 友链管理 REST 路由（命名空间 wp-astrahub/v1）。
 *
 * 收发件箱、审核/拒绝、撤回、删除、解除关系，全部签名后转发 Hub 的
 * /v1/friend-invitations/* 与 /v1/friend-relations/*，对应 Halo 端
 * useFriendInvitations.ts 的端点。审核通过后在本地用 wp_insert_link 建链。
 *
 * 端点：
 *   GET  /friend-invitations            ?box=inbox|outbox&status=
 *   POST /friend-invitations            { toSiteId, message, linkGroupName }
 *   POST /friend-invitations/{id}/review  { approved, reason, linkGroupName }
 *   POST /friend-invitations/{id}/cancel
 *   POST /friend-invitations/{id}/delete
 *   POST /friend-invitations/{id}/reconcile { peer..., linkGroupName }
 *   POST /friend-relations/{peerSiteId}/remove { reason }
 *   GET  /friend-invitations/link-groups
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Rest_Friend {

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
     * 本地建链。
     *
     * @var WP_AstraHub_Link_Reconcile
     */
    private $reconcile;

    /**
     * 构造。
     *
     * @param WP_AstraHub_Hub_Client       $hub_client  Hub 客户端。
     * @param WP_AstraHub_Credential_Store $credentials 凭据存储。
     * @param WP_AstraHub_Link_Reconcile   $reconcile   本地建链。
     */
    public function __construct(
        WP_AstraHub_Hub_Client $hub_client,
        WP_AstraHub_Credential_Store $credentials,
        WP_AstraHub_Link_Reconcile $reconcile
    ) {
        $this->hub_client  = $hub_client;
        $this->credentials = $credentials;
        $this->reconcile   = $reconcile;
    }

    /**
     * 注册路由。
     */
    public function register_routes() {
        $ns         = WP_AstraHub_Rest_Register::NAMESPACE;
        $permission = array( $this, 'check_permission' );

        register_rest_route( $ns, '/friend-invitations', array(
            'methods'             => 'GET',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_list' ),
        ) );
        register_rest_route( $ns, '/friend-invitations', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_create' ),
        ) );
        register_rest_route( $ns, '/friend-invitations/(?P<id>[^/]+)/review', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_review' ),
        ) );
        register_rest_route( $ns, '/friend-invitations/(?P<id>[^/]+)/cancel', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_cancel' ),
        ) );
        register_rest_route( $ns, '/friend-invitations/(?P<id>[^/]+)/delete', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_delete' ),
        ) );
        register_rest_route( $ns, '/friend-invitations/(?P<id>[^/]+)/reconcile', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_reconcile' ),
        ) );
        register_rest_route( $ns, '/friend-invitations/(?P<id>[^/]+)/ack', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_ack' ),
        ) );
        register_rest_route( $ns, '/friend-relations/(?P<peerSiteId>[^/]+)/remove', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_remove_relation' ),
        ) );
        register_rest_route( $ns, '/friend-invitations/link-groups', array(
            'methods'             => 'GET',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_link_groups' ),
        ) );
    }

    /**
     * 权限校验。
     *
     * @return bool
     */
    public function check_permission() {
        return current_user_can( 'manage_options' );
    }

    /**
     * 收发件箱列表。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_list( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $box    = $request->get_param( 'box' ) === 'outbox' ? 'outbox' : 'inbox';
        $status = trim( (string) $request->get_param( 'status' ) );
        $query  = array();
        if ( '' !== $status ) {
            $query['status'] = $status;
        }
        $path     = 'inbox' === $box ? '/v1/friend-invitations/inbox' : '/v1/friend-invitations/outbox';
        $response = $this->hub_client->request_signed( 'GET', $path, null, array(), $query );
        if ( ! $response['success'] ) {
            return $this->fail( $response['status'], $response['message'] );
        }
        $body  = $response['body'];
        $items = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array();
        return new WP_REST_Response(
            array(
                'success' => true,
                'data'    => array(
                    'generatedAt' => isset( $body['generatedAt'] ) ? $body['generatedAt'] : '',
                    'total'       => isset( $body['total'] ) ? (int) $body['total'] : count( $items ),
                    'items'       => $items,
                ),
            ),
            200
        );
    }

    /**
     * 发起邀请。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_create( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $input   = (array) $request->get_json_params();
        $payload = array(
            'toSiteId'      => trim( (string) ( $input['toSiteId'] ?? '' ) ),
            'message'       => (string) ( $input['message'] ?? '' ),
            'linkGroupName' => (string) ( $input['linkGroupName'] ?? '' ),
        );
        if ( '' === $payload['toSiteId'] ) {
            return $this->fail( 400, 'toSiteId is required' );
        }
        $response = $this->hub_client->request_signed( 'POST', '/v1/friend-invitations', $payload );
        return $this->forward( $response, 'invitation' );
    }

    /**
     * 审核（通过/拒绝）。通过时在本地建链。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_review( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $invite_id = $this->invite_id( $request );
        $input     = (array) $request->get_json_params();
        $approved  = ! empty( $input['approved'] );
        $payload   = array(
            'approved'      => $approved,
            'reason'        => (string) ( $input['reason'] ?? '' ),
            'linkGroupName' => (string) ( $input['linkGroupName'] ?? '' ),
        );
        $path     = '/v1/friend-invitations/' . rawurlencode( $invite_id ) . '/review';
        $response = $this->hub_client->request_signed( 'POST', $path, $payload );
        if ( ! $response['success'] ) {
            return $this->fail( $response['status'], $response['message'] );
        }

        $invitation = isset( $response['body']['invitation'] ) && is_array( $response['body']['invitation'] )
            ? $response['body']['invitation'] : array();

        // 审核通过：把对端写进本地友链（审核方视角，对端是 fromSite）。
        $reconcile_result = null;
        if ( $approved && ! empty( $invitation ) ) {
            $peer = $this->resolve_peer( $invitation );
            if ( ! empty( $peer ) ) {
                $reconcile_result = $this->reconcile->reconcile_peer( $peer, $payload['linkGroupName'] );
            }
        }

        return new WP_REST_Response(
            array(
                'success'    => true,
                'invitation' => $invitation,
                'reconcile'  => $reconcile_result,
            ),
            200
        );
    }

    /**
     * 撤回邀请。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_cancel( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $path     = '/v1/friend-invitations/' . rawurlencode( $this->invite_id( $request ) ) . '/cancel';
        $response = $this->hub_client->request_signed( 'POST', $path, array() );
        return $this->forward( $response, 'invitation' );
    }

    /**
     * 删除记录。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_delete( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $path     = '/v1/friend-invitations/' . rawurlencode( $this->invite_id( $request ) ) . '/delete';
        $response = $this->hub_client->request_signed( 'POST', $path, array() );
        return $this->forward( $response, null );
    }

    /**
     * 邀请方侧：通过后把对方写进本地友链（前端在收到 reviewed=accepted 后调用）。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_reconcile( WP_REST_Request $request ) {
        $input = (array) $request->get_json_params();
        $peer  = array(
            'siteId'      => trim( (string) ( $input['fromSiteId'] ?? '' ) ),
            'siteName'    => trim( (string) ( $input['fromSiteName'] ?? '' ) ),
            'siteUrl'     => trim( (string) ( $input['fromSiteUrl'] ?? '' ) ),
            'description' => (string) ( $input['fromDescription'] ?? '' ),
            'avatarUrl'   => (string) ( $input['fromAvatarUrl'] ?? '' ),
            'rssUrl'      => (string) ( $input['fromRssUrl'] ?? '' ),
        );
        // 若当前站点就是 fromSite，则对端是 toSite。
        $current_site_id = trim( (string) ( $input['currentSiteId'] ?? '' ) );
        if ( $current_site_id !== '' && $current_site_id === trim( (string) ( $input['fromSiteId'] ?? '' ) ) ) {
            $peer = array(
                'siteId'      => trim( (string) ( $input['toSiteId'] ?? '' ) ),
                'siteName'    => trim( (string) ( $input['toSiteName'] ?? '' ) ),
                'siteUrl'     => trim( (string) ( $input['toSiteUrl'] ?? '' ) ),
                'description' => (string) ( $input['toDescription'] ?? '' ),
                'avatarUrl'   => (string) ( $input['toAvatarUrl'] ?? '' ),
                'rssUrl'      => (string) ( $input['toRssUrl'] ?? '' ),
            );
        }
        $result = $this->reconcile->reconcile_peer( $peer, (string) ( $input['linkGroupName'] ?? '' ) );
        $http   = $result['success'] ? 200 : 400;
        return new WP_REST_Response(
            array(
                'success' => $result['success'],
                'data'    => array(
                    'created'   => $result['created'],
                    'duplicate' => $result['duplicate'],
                    'message'   => $result['message'],
                ),
                'message' => $result['message'],
            ),
            $http
        );
    }

    /**
     * 邀请方侧：把已接受邀请的本地建链结果回执给 Hub（写 ackedAt / lastError）。
     * 对齐 Halo AstraHubFriendManagementService.ackInvitation：签名 POST
     * /v1/friend-invitations/{id}/ack，请求体 { lastError }。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_ack( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $input   = (array) $request->get_json_params();
        $payload = array( 'lastError' => trim( (string) ( $input['lastError'] ?? '' ) ) );
        $path    = '/v1/friend-invitations/' . rawurlencode( $this->invite_id( $request ) ) . '/ack';
        $response = $this->hub_client->request_signed( 'POST', $path, $payload );
        return $this->forward( $response, 'invitation' );
    }

    /**
     * 解除友链关系：Hub 删边 + 本地删链。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_remove_relation( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $peer_site_id = (string) $request->get_param( 'peerSiteId' );
        $input        = (array) $request->get_json_params();
        $reason       = trim( (string) ( $input['reason'] ?? '' ) );
        $payload      = $reason !== '' ? array( 'reason' => $reason ) : array();
        $path         = '/v1/friend-relations/' . rawurlencode( $peer_site_id ) . '/remove';
        $response     = $this->hub_client->request_signed( 'POST', $path, $payload );
        if ( ! $response['success'] ) {
            return $this->fail( $response['status'], $response['message'] );
        }
        $body     = $response['body'];
        $peer_url = isset( $body['peerSiteUrl'] ) ? (string) $body['peerSiteUrl'] : '';
        $local    = $peer_url !== '' ? $this->reconcile->delete_by_peer_url( $peer_url ) : array( 'deleted' => 0, 'message' => '' );
        return new WP_REST_Response(
            array(
                'success' => true,
                'data'    => array(
                    'removed'          => isset( $body['removed'] ) ? (bool) $body['removed'] : true,
                    'peerSiteId'       => $peer_site_id,
                    'peerSiteUrl'      => $peer_url,
                    'localLinkDeleted' => isset( $local['deleted'] ) ? (int) $local['deleted'] : 0,
                    'localLinkMessage' => isset( $local['message'] ) ? $local['message'] : '',
                ),
            ),
            200
        );
    }

    /**
     * 本地友链分组选项（供审核时选择分组）。
     *
     * @return WP_REST_Response
     */
    public function handle_link_groups() {
        $items = array();
        $terms = get_terms( array( 'taxonomy' => 'link_category', 'hide_empty' => false ) );
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $items[] = array( 'name' => $term->name, 'displayName' => $term->name );
            }
        }
        return new WP_REST_Response( array( 'success' => true, 'data' => array( 'items' => $items ) ), 200 );
    }

    /**
     * 从邀请记录解析对端（当前站点之外的一方）。
     *
     * @param array $invitation 邀请记录。
     * @return array
     */
    private function resolve_peer( array $invitation ) {
        $my_site_id = trim( $this->credentials->get_credentials()['siteId'] );
        $from = isset( $invitation['fromSite'] ) && is_array( $invitation['fromSite'] ) ? $invitation['fromSite'] : array();
        $to   = isset( $invitation['toSite'] ) && is_array( $invitation['toSite'] ) ? $invitation['toSite'] : array();
        // 审核方通常是 toSite，对端即 fromSite。
        $peer = ( isset( $to['siteId'] ) && trim( (string) $to['siteId'] ) === $my_site_id ) ? $from : $to;
        if ( empty( $peer ) ) {
            return array();
        }
        return array(
            'siteId'      => trim( (string) ( $peer['siteId'] ?? '' ) ),
            'siteName'    => trim( (string) ( $peer['siteName'] ?? '' ) ),
            'siteUrl'     => trim( (string) ( $peer['siteUrl'] ?? '' ) ),
            'description' => (string) ( $peer['description'] ?? '' ),
            'avatarUrl'   => (string) ( $peer['avatarUrl'] ?? '' ),
            'rssUrl'      => (string) ( $peer['rssUrl'] ?? '' ),
        );
    }

    /**
     * 取路径中的 inviteId。
     *
     * @param WP_REST_Request $request 请求。
     * @return string
     */
    private function invite_id( WP_REST_Request $request ) {
        return rawurldecode( (string) $request->get_param( 'id' ) );
    }

    /**
     * 转发 Hub 响应，可选提取某个字段。
     *
     * @param array       $response Hub 响应。
     * @param string|null $field    需要提取并原样回传的字段名。
     * @return WP_REST_Response
     */
    private function forward( array $response, $field ) {
        if ( ! $response['success'] ) {
            return $this->fail( $response['status'], $response['message'] );
        }
        $out = array( 'success' => true );
        if ( null !== $field && isset( $response['body'][ $field ] ) ) {
            $out[ $field ] = $response['body'][ $field ];
        }
        return new WP_REST_Response( $out, 200 );
    }

    /**
     * 未登舱响应。
     *
     * @return WP_REST_Response
     */
    private function not_registered() {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'not registered yet' ), 400 );
    }

    /**
     * 失败响应。
     *
     * @param int    $status  状态码。
     * @param string $message 信息。
     * @return WP_REST_Response
     */
    private function fail( $status, $message ) {
        $http = $status >= 400 && $status < 600 ? $status : 400;
        return new WP_REST_Response( array( 'success' => false, 'status' => $status, 'message' => $message ), $http );
    }
}
