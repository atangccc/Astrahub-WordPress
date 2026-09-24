<?php
/**
 * 友链管理 REST 路由（命名空间 wp-astrahub/v1），对齐 Halo 端
 * AstraHubFriendManagementRouter。
 *
 * 所有端点签名后转发 Hub，由插件在本地处理 WordPress 特有逻辑
 * （如 wp_insert_link 建链）。
 *
 * 响应格式统一：{ success, status, message, data } —— 对齐 client.ts 的 ApiEnvelope。
 * Hub 返回的业务字段全部放在 data 里。
 *
 * WP 端点 ↔ Hub 端点：
 *   GET  /astrahub/friend-invitations/overview        → GET  /v1/friend-invitations/overview
 *   GET  /astrahub/friend-invitations/link-groups     → 本地 read
 *   POST /astrahub/friend-invitations                 → POST /v1/friend-invitations
 *   POST /astrahub/friend-invitations/{id}/review     → POST /v1/friend-invitations/{id}/review
 *   POST /astrahub/friend-invitations/{id}/cancel     → POST /v1/friend-invitations/{id}/cancel
 *   POST /astrahub/friend-invitations/{id}/delete     → POST /v1/friend-invitations/{id}/delete
 *   POST /astrahub/friend-invitations/{id}/ack        → POST /v1/friend-invitations/{id}/ack
 *   POST /astrahub/friend-invitations/{id}/reconcile  → 本地（WP 版 reconcile）
 *   POST /astrahub/friend-relations/{id}/remove       → POST /v1/friend-relations/{id}/remove
 *   POST /astrahub/friend-follows/{id}/remove         → POST /v1/friend-follows/{id}/remove
 *   GET  /astrahub/sites/lookup                       → GET  /v1/sites/lookup
 *   POST /astrahub/site-relations/batch               → POST /v1/relations/sites/batch
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Rest_Friend {

    /** @var WP_AstraHub_Hub_Client */
    private $hub_client;

    /** @var WP_AstraHub_Credential_Store */
    private $credentials;

    /** @var WP_AstraHub_Link_Reconcile */
    private $reconcile;

    public function __construct(
        WP_AstraHub_Hub_Client $hub_client,
        WP_AstraHub_Credential_Store $credentials,
        WP_AstraHub_Link_Reconcile $reconcile
    ) {
        $this->hub_client  = $hub_client;
        $this->credentials = $credentials;
        $this->reconcile   = $reconcile;
    }

    public function register_routes() {
        $ns         = WP_AstraHub_Rest_Register::NAMESPACE;
        $permission = array( $this, 'check_permission' );

        register_rest_route( $ns, '/astrahub/friend-invitations/overview', array(
            'methods'             => 'GET',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_overview' ),
        ) );

        register_rest_route( $ns, '/astrahub/friend-invitations/link-groups', array(
            'methods'             => 'GET',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_link_groups' ),
        ) );

        register_rest_route( $ns, '/astrahub/friend-invitations', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_create' ),
        ) );

        register_rest_route( $ns, '/astrahub/friend-invitations/(?P<inviteId>[^/]+)/review', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_review' ),
        ) );

        register_rest_route( $ns, '/astrahub/friend-invitations/(?P<inviteId>[^/]+)/cancel', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_cancel' ),
        ) );

        register_rest_route( $ns, '/astrahub/friend-invitations/(?P<inviteId>[^/]+)/delete', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_delete' ),
        ) );

        register_rest_route( $ns, '/astrahub/friend-invitations/(?P<inviteId>[^/]+)/ack', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_ack' ),
        ) );

        register_rest_route( $ns, '/astrahub/friend-invitations/(?P<inviteId>[^/]+)/reconcile', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_reconcile' ),
        ) );

        register_rest_route( $ns, '/astrahub/friend-relations/(?P<peerSiteId>[^/]+)/remove', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_remove_relation' ),
        ) );

        register_rest_route( $ns, '/astrahub/friend-follows/(?P<peerSiteId>[^/]+)/remove', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_remove_follow' ),
        ) );

        register_rest_route( $ns, '/astrahub/sites/lookup', array(
            'methods'             => 'GET',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_site_lookup' ),
        ) );

        register_rest_route( $ns, '/astrahub/site-relations/batch', array(
            'methods'             => 'POST',
            'permission_callback' => $permission,
            'callback'            => array( $this, 'handle_site_relations_batch' ),
        ) );
    }

    public function check_permission() {
        return current_user_can( 'manage_options' );
    }

    // ──────────────────────────────────────────────
    //  友链邀请
    // ──────────────────────────────────────────────

    public function handle_overview( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->envelope_fail( 400, 'not registered yet' );
        }

        // 前端 UI 语义（all / inbox / outbox + pending/accepted/rejected）
        // 直接映射成 Hub 认识的 tab 值（pending/accepted/rejected/outbox/all）。
        // Hub 的 tab=pending 只返回"收到的待审核"，tab=outbox 返回"发出的全部"，
        // tab=all 返回"全部" —— 不需要额外传 status 参数，也不需要二次方向过滤。
        $ui_tab    = trim( (string) $request->get_param( 'tab' ) );
        $ui_status = trim( (string) $request->get_param( 'status' ) );
        $hub_tab   = 'all';
        if ( $ui_tab === 'outbox' ) {
            $hub_tab = 'outbox';
        } elseif ( $ui_status === 'pending' ) {
            $hub_tab = 'pending';
        } elseif ( $ui_status === 'accepted' ) {
            $hub_tab = 'accepted';
        } elseif ( $ui_status === 'rejected' ) {
            $hub_tab = 'rejected';
        }

        $limit  = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 20 ) ) );
        $offset = max( 0, (int) $request->get_param( 'offset' ) );

        $query = array(
            'tab'    => $hub_tab,
            'limit'  => (string) $limit,
            'offset' => (string) $offset,
        );

        $hub_resp = $this->hub_client->request_signed(
            'GET', '/v1/friend-invitations/overview', null, array(), $query
        );

        if ( ! $hub_resp['success'] ) {
            return $this->envelope_fail( $hub_resp['status'], $hub_resp['message'] );
        }

        $body  = $hub_resp['body'];
        $items = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array();

        return $this->envelope( 200, array(
            'tab'          => isset( $body['tab'] ) ? $body['tab'] : $hub_tab,
            'generatedAt'  => isset( $body['generatedAt'] ) ? $body['generatedAt'] : '',
            'total'        => isset( $body['total'] ) ? (int) $body['total'] : count( $items ),
            'limit'        => isset( $body['limit'] ) ? (int) $body['limit'] : $limit,
            'offset'       => isset( $body['offset'] ) ? (int) $body['offset'] : $offset,
            'hasMore'      => isset( $body['hasMore'] ) ? (bool) $body['hasMore'] : false,
            'pendingCount' => isset( $body['pendingCount'] ) ? (int) $body['pendingCount'] : 0,
            'items'        => $items,
            'linkGroups'   => $this->read_link_groups(),
        ) );
    }

    public function handle_create( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->envelope_fail( 400, 'not registered yet' );
        }
        $input   = (array) $request->get_json_params();
        $payload = array(
            'toSiteId'      => trim( (string) ( $input['toSiteId'] ?? '' ) ),
            'message'       => (string) ( $input['message'] ?? '' ),
            'linkGroupName' => (string) ( $input['linkGroupName'] ?? '' ),
        );
        if ( '' === $payload['toSiteId'] ) {
            return $this->envelope_fail( 400, 'toSiteId is required' );
        }
        $response = $this->hub_client->request_signed( 'POST', '/v1/friend-invitations', $payload );
        return $this->hub_to_envelope( $response );
    }

    public function handle_review( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->envelope_fail( 400, 'not registered yet' );
        }
        $invite_id = $this->path_param( $request, 'inviteId' );
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
            return $this->hub_to_envelope( $response );
        }

        $invitation = isset( $response['body']['invitation'] ) && is_array( $response['body']['invitation'] )
            ? $response['body']['invitation'] : array();

        $reconcile_result = null;
        if ( $approved && ! empty( $invitation ) ) {
            $peer = $this->resolve_peer( $invitation );
            if ( ! empty( $peer ) ) {
                $reconcile_result = $this->reconcile->reconcile_peer( $peer, $payload['linkGroupName'] );
            }
        }

        return $this->envelope( 200, array(
            'invitation' => $invitation,
            'reconcile'  => $reconcile_result,
        ) );
    }

    public function handle_cancel( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->envelope_fail( 400, 'not registered yet' );
        }
        $path     = '/v1/friend-invitations/' . rawurlencode( $this->path_param( $request, 'inviteId' ) ) . '/cancel';
        $response = $this->hub_client->request_signed( 'POST', $path, array() );
        return $this->hub_to_envelope( $response );
    }

    public function handle_delete( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->envelope_fail( 400, 'not registered yet' );
        }
        $path     = '/v1/friend-invitations/' . rawurlencode( $this->path_param( $request, 'inviteId' ) ) . '/delete';
        $response = $this->hub_client->request_signed( 'POST', $path, array() );
        return $this->hub_to_envelope( $response );
    }

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
        return $this->envelope( $result['success'] ? 200 : 400, array(
            'created'   => $result['created'],
            'duplicate' => $result['duplicate'],
            'message'   => $result['message'],
        ) );
    }

    public function handle_ack( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->envelope_fail( 400, 'not registered yet' );
        }
        $input   = (array) $request->get_json_params();
        $payload = array( 'lastError' => trim( (string) ( $input['lastError'] ?? '' ) ) );
        $path    = '/v1/friend-invitations/' . rawurlencode( $this->path_param( $request, 'inviteId' ) ) . '/ack';
        $response = $this->hub_client->request_signed( 'POST', $path, $payload );
        return $this->hub_to_envelope( $response );
    }

    // ──────────────────────────────────────────────
    //  友链关系 / 关注
    // ──────────────────────────────────────────────

    public function handle_remove_relation( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->envelope_fail( 400, 'not registered yet' );
        }
        $peer_site_id = $this->path_param( $request, 'peerSiteId' );
        $input        = (array) $request->get_json_params();
        $path         = '/v1/friend-relations/' . rawurlencode( $peer_site_id ) . '/remove';
        $response     = $this->hub_client->request_signed( 'POST', $path, array( 'reason' => trim( (string) ( $input['reason'] ?? '' ) ) ) );
        if ( ! $response['success'] ) {
            return $this->hub_to_envelope( $response );
        }
        $body     = $response['body'];
        $peer_url = isset( $body['peerSiteUrl'] ) ? (string) $body['peerSiteUrl'] : '';
        $local    = ( $peer_url !== '' || $peer_site_id !== '' )
            ? $this->reconcile->delete_by_peer_url( $peer_url, $peer_site_id )
            : array( 'deleted' => 0, 'message' => '' );
        return $this->envelope( 200, array(
            'removed'          => isset( $body['removed'] ) ? (bool) $body['removed'] : true,
            'peerSiteId'       => $peer_site_id,
            'peerSiteUrl'      => $peer_url,
            'localLinkDeleted' => isset( $local['deleted'] ) ? (int) $local['deleted'] : 0,
            'localLinkMessage' => isset( $local['message'] ) ? $local['message'] : '',
        ) );
    }

    public function handle_remove_follow( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->envelope_fail( 400, 'not registered yet' );
        }
        $peer_site_id = $this->path_param( $request, 'peerSiteId' );
        $path         = '/v1/friend-follows/' . rawurlencode( $peer_site_id ) . '/remove';
        $response     = $this->hub_client->request_signed( 'POST', $path, array() );
        if ( ! $response['success'] ) {
            return $this->hub_to_envelope( $response );
        }
        $body     = $response['body'];
        $peer_url = isset( $body['peerSiteUrl'] ) ? (string) $body['peerSiteUrl'] : '';
        $local    = ( $peer_url !== '' || $peer_site_id !== '' )
            ? $this->reconcile->delete_by_peer_url( $peer_url, $peer_site_id )
            : array( 'deleted' => 0, 'message' => '' );
        return $this->envelope( 200, array(
            'removed'          => isset( $body['removed'] ) ? (bool) $body['removed'] : true,
            'peerSiteId'       => $peer_site_id,
            'peerSiteUrl'      => $peer_url,
            'localLinkDeleted' => isset( $local['deleted'] ) ? (int) $local['deleted'] : 0,
            'localLinkMessage' => isset( $local['message'] ) ? $local['message'] : '',
        ) );
    }

    // ──────────────────────────────────────────────
    //  站点查询
    // ──────────────────────────────────────────────

    public function handle_site_lookup( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->envelope_fail( 400, 'not registered yet' );
        }
        $url = trim( (string) $request->get_param( 'url' ) );
        if ( '' === $url ) {
            return $this->envelope_fail( 400, 'url is required' );
        }
        $response = $this->hub_client->request_signed(
            'GET', '/v1/sites/lookup', null, array(), array( 'url' => $url )
        );
        if ( ! $response['success'] ) {
            return $this->hub_to_envelope( $response );
        }
        $body = $response['body'];
        return $this->envelope( 200, array(
            'registered'         => isset( $body['registered'] ) ? (bool) $body['registered'] : false,
            'registeredByPlugin' => isset( $body['registeredByPlugin'] ) ? (bool) $body['registeredByPlugin'] : false,
            'credentialReady'    => isset( $body['credentialReady'] ) ? (bool) $body['credentialReady'] : false,
            'siteId'             => isset( $body['siteId'] ) ? (string) $body['siteId'] : '',
            'siteName'           => isset( $body['siteName'] ) ? (string) $body['siteName'] : '',
            'siteUrl'            => isset( $body['siteUrl'] ) ? (string) $body['siteUrl'] : '',
            'avatarUrl'          => isset( $body['avatarUrl'] ) ? (string) $body['avatarUrl'] : '',
            'supportsInvitation' => isset( $body['supportsInvitation'] ) ? (bool) $body['supportsInvitation'] : false,
            'invitationState'    => isset( $body['invitationState'] ) ? (string) $body['invitationState'] : '',
            'invitationMessage'  => isset( $body['invitationMessage'] ) ? (string) $body['invitationMessage'] : '',
        ) );
    }

    public function handle_site_relations_batch( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->envelope_fail( 400, 'not registered yet' );
        }
        $input   = (array) $request->get_json_params();
        $targets = isset( $input['targetUrls'] ) && is_array( $input['targetUrls'] ) ? $input['targetUrls'] : array();
        $targets = array_values( array_filter( array_map( 'trim', $targets ) ) );
        $response = $this->hub_client->request_signed(
            'POST', '/v1/relations/sites/batch', array( 'targetUrls' => $targets )
        );
        if ( ! $response['success'] ) {
            return $this->hub_to_envelope( $response );
        }
        $body  = $response['body'];
        $items = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array();
        return $this->envelope( 200, array( 'items' => $items ) );
    }

    // ──────────────────────────────────────────────
    //  Link Groups
    // ──────────────────────────────────────────────

    public function handle_link_groups() {
        return $this->envelope( 200, array( 'items' => $this->read_link_groups() ) );
    }

    // ──────────────────────────────────────────────
    //  辅助
    // ──────────────────────────────────────────────

    private function path_param( WP_REST_Request $request, $name ) {
        return rawurldecode( (string) $request->get_param( $name ) );
    }

    private function read_link_groups() {
        $items = array();
        $terms = get_terms( array( 'taxonomy' => 'link_category', 'hide_empty' => false ) );
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $items[] = array(
                    'name'        => $term->name,
                    'displayName' => $term->name,
                );
            }
        }
        return $items;
    }

    private function resolve_peer( array $invitation ) {
        $my_site_id = trim( $this->credentials->get_credentials()['siteId'] );
        $from = isset( $invitation['fromSite'] ) && is_array( $invitation['fromSite'] ) ? $invitation['fromSite'] : array();
        $to   = isset( $invitation['toSite'] ) && is_array( $invitation['toSite'] ) ? $invitation['toSite'] : array();
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
     * 成功响应：{ success: true, status, message: "ok", data }
     */
    private function envelope( $status, array $data ) {
        return new WP_REST_Response(
            array(
                'success' => true,
                'status'  => $status,
                'message' => 'ok',
                'data'    => $data,
            ),
            $status
        );
    }

    /**
     * 失败响应：{ success: false, status, message }
     */
    private function envelope_fail( $status, $message ) {
        $http = $status >= 400 && $status < 600 ? $status : 400;
        return new WP_REST_Response(
            array(
                'success' => false,
                'status'  => $status,
                'message' => $message,
                'data'    => new \stdClass(),
            ),
            $http
        );
    }

    /**
     * Hub 响应 → envelope。失败直接转 envelope_fail；成功时 hub body 作为 data。
     */
    private function hub_to_envelope( array $hub_response ) {
        if ( ! $hub_response['success'] ) {
            return $this->envelope_fail( $hub_response['status'], $hub_response['message'] );
        }
        // Hub 成功时，把 hub body 里的 invitation 等字段原样放进 data。
        $body = isset( $hub_response['body'] ) && is_array( $hub_response['body'] ) ? $hub_response['body'] : array();
        return new WP_REST_Response(
            array(
                'success' => true,
                'status'  => 200,
                'message' => 'ok',
                'data'    => $body,
            ),
            200
        );
    }
}
