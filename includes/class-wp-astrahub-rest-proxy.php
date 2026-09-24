<?php
/**
 * Hub 读写代理 REST 路由。
 *
 * 后台 SPA 不直接请求 Hub（apiKey 不能暴露到浏览器），而是调用插件的代理端点，
 * 插件用 apiKey 签名后转发给 Hub。对应 Halo 端各 Router（PlanetLinks / Graph /
 * FriendManagement 等）的代理职责。
 *
 * 命名空间：wp-astrahub/v1
 *   GET  /planet/links          代理 GET /v1/planet/links（带 size/cursor/tag/keyword/relation）
 *   POST /hub/get               通用签名 GET 代理（受白名单约束）
 *   POST /hub/post              通用签名 POST 代理（受白名单约束）
 *
 * 通用代理用路径前缀白名单防止 SSRF / 越权调用到任意 Hub 端点。后续阶段按需扩白名单。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Rest_Proxy {

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
     * 推送服务（用于 push / report-status 路由）。
     *
     * @var WP_AstraHub_Push_Service|null
     */
    private $push_service;

    /**
     * 友链反向对账服务（用于 friend-sync 路由）。
     *
     * @var WP_AstraHub_Friend_Sync_Service|null
     */
    private $friend_sync;

    /**
     * 前台挂件注入器（用于 widget-settings 路由）。
     *
     * @var WP_AstraHub_Frontend_Widget|null
     */
    private $frontend_widget;

    /**
     * 站点友链搬家服务（用于 site-migration/restore 路由）。
     *
     * @var WP_AstraHub_Site_Migration|null
     */
    private $site_migration;

    /**
     * 多节点选择器（用于 node-status / node-selection 路由）。
     *
     * @var WP_AstraHub_Node_Selector|null
     */
    private $node_selector;

    /**
     * 允许通过通用代理访问的 Hub 路径前缀白名单。
     *
     * 与 Halo 端 AstraHubSignedPlanetReadService / AstraHubWorldChatService
     * 等代理端点对齐。每条前缀的边界规则由 is_allowed_path 保证（'/' 结尾即精确
     * 前缀，非 '/' 结尾视为完整端点）。
     *
     * @var string[]
     */
    private $allowed_prefixes = array(
        '/v1/planet/',              // planet-links / planet-feed / planet-clusters / rss-deep-space
        '/v1/graph/',               // my-site / nodes / topology / sites/{id}/relations
        '/v1/friend-invitations',   // inbox / outbox / all / review / cancel / delete / ack
        '/v1/friend-relations/',    // remove
        '/v1/friend-follows/',      // remove (WP rest-friend 专用，也允许代理访问)
        '/v1/relations/',
        '/v1/sites/lookup',         // 查站点是否已注册
        '/v1/sites/profile',        // PUT updateProfile (对齐 Halo AstraHubRegisterService.PROFILE_PATH)
        '/v1/world-chat/',          // messages / members / stickers / consent / bootstrap
        '/v1/site-migration/',      // restore (对齐 Halo AstraHubSiteMigrationRouter)
    );

    /**
     * 构造。
     *
     * @param WP_AstraHub_Hub_Client       $hub_client  Hub 客户端。
     * @param WP_AstraHub_Credential_Store $credentials 凭据存储。
     */
    public function __construct( WP_AstraHub_Hub_Client $hub_client, WP_AstraHub_Credential_Store $credentials ) {
        $this->hub_client  = $hub_client;
        $this->credentials = $credentials;
    }

    /**
     * 注入推送服务（在主类装配后调用）。
     *
     * @param WP_AstraHub_Push_Service $push_service 推送服务。
     */
    public function set_push_service( WP_AstraHub_Push_Service $push_service ) {
        $this->push_service = $push_service;
    }

    /**
     * 注入友链反向对账服务（在主类装配后调用）。
     *
     * @param WP_AstraHub_Friend_Sync_Service $friend_sync 友链反向对账服务。
     */
    public function set_friend_sync_service( WP_AstraHub_Friend_Sync_Service $friend_sync ) {
        $this->friend_sync = $friend_sync;
    }

    /**
     * 注入前台挂件注入器（在主类装配后调用）。
     *
     * @param WP_AstraHub_Frontend_Widget $frontend_widget 前台挂件注入器。
     */
    public function set_frontend_widget( WP_AstraHub_Frontend_Widget $frontend_widget ) {
        $this->frontend_widget = $frontend_widget;
    }

    /**
     * 注入站点友链搬家服务（在主类装配后调用）。
     *
     * @param WP_AstraHub_Site_Migration $site_migration 搬家服务。
     */
    public function set_site_migration( WP_AstraHub_Site_Migration $site_migration ) {
        $this->site_migration = $site_migration;
    }

    /**
     * 注入多节点选择器（在主类装配后调用）。
     *
     * @param WP_AstraHub_Node_Selector $node_selector 节点选择器。
     */
    public function set_node_selector( WP_AstraHub_Node_Selector $node_selector ) {
        $this->node_selector = $node_selector;
    }

    /**
     * 注册路由。
     */
    public function register_routes() {
        $permission = array( $this, 'check_permission' );

        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/planet/links',
            array(
                'methods'             => 'GET',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_planet_links' ),
            )
        );

        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/hub/get',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_hub_get' ),
            )
        );

        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/hub/post',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_hub_post' ),
            )
        );

        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/push-graph',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_push_graph' ),
            )
        );

        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/report-status',
            array(
                'methods'             => 'GET',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_report_status' ),
            )
        );

        // 实时连接票据：签名换取 Hub 的一次性短期 WebSocket token。
        // 浏览器据此直连 Hub /v1/ws（WebSocket 握手无法逐请求 HMAC，必须先换票）。
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/realtime/token',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_realtime_token' ),
            )
        );

        // 友链反向对账（链路 B）：手动触发一次拉取对账（cron 也会自动跑）。
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/friend-sync',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_friend_sync' ),
            )
        );

        // 友链反向对账状态（最近一次结果）。
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/friend-sync/status',
            array(
                'methods'             => 'GET',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_friend_sync_status' ),
            )
        );

        // 关系图头像代理：3D 画布需把远端头像绘进 canvas 纹理，跨域图片会污染
        // canvas（tainted），因此由插件服务端拉取后同源回传。对齐 Halo 端
        // /apis/.../astrahub/graph/avatar。permission 复用 manage_options（img 标签
        // 通过 ?_wpnonce= 查询参数携带 nonce，WordPress REST 原生支持）。
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/graph/avatar',
            array(
                'methods'             => 'GET',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_graph_avatar' ),
            )
        );

        // 实时定向对账（链路 A 触发）：WS 收到 friend_relation_removed /
        // site_profile_updated 后，前端把对端 URL 回调进来，插件向 Hub 确认权威关系
        // 后删/改本地这一个友链。秒级生效，是否删除以 Hub 为准（事件仅作触发器）。
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/friend-sync/peer',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_friend_sync_peer' ),
            )
        );

        // 世界频道表情文件代理：同源代理 Hub 上的 sticker 图片文件。
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/world-chat/stickers/(?P<stickerId>[^/]+)/file',
            array(
                'methods'             => 'GET',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_world_chat_sticker_file' ),
            )
        );

        // 通用资源代理：前端 restResourceUrl 的同源代理端点，透传 Hub 资源文件。
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/hub/resource',
            array(
                'methods'             => 'GET',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_hub_resource' ),
            )
        );

        // 前台挂件设置（显示前台挂件 / 主星实时播报）读取与保存。
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/widget-settings',
            array(
                'methods'             => 'GET',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_get_widget_settings' ),
            )
        );
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/widget-settings',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_save_widget_settings' ),
            )
        );

        // 站点友链搬家：从 Hub 拉权威快照，完全替换本地 wp_links / link_category。
        // 对齐 Halo AstraHubSiteMigrationRouter → AstraHubSiteMigrationService.migrate。
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/site-migration/restore',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_site_migration_restore' ),
            )
        );

        // 多节点管理（对齐 Halo AstraHubNodeSelector + AstraHubNodeSelectorRouter）：
        //   GET  /node-status        → 当前选中节点 + 所有节点的健康状态快照
        //   POST /node-status/refresh → 立即对所有节点跑 /healthz 探针
        //   POST /node-selection     → 手动锁定某个已验证节点（body: { url }）
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/node-status',
            array(
                'methods'             => 'GET',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_node_status' ),
            )
        );
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/node-status/refresh',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_node_status_refresh' ),
            )
        );
        register_rest_route(
            WP_AstraHub_Rest_Register::NAMESPACE,
            '/node-selection',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_node_selection' ),
            )
        );
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
     * 代理 GET /v1/planet/links，转发分页/筛选/搜索参数（与 Halo 端代理一致）。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_planet_links( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $query = array();
        foreach ( array( 'size', 'cursor', 'tag', 'keyword', 'relation' ) as $key ) {
            $value = $request->get_param( $key );
            if ( null !== $value && '' !== $value ) {
                $query[ $key ] = (string) $value;
            }
        }
        $response = $this->hub_client->request_signed( 'GET', '/v1/planet/links', null, array(), $query );
        return $this->passthrough( $response );
    }

    /**
     * 通用签名 GET 代理。body: { path, query }
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_hub_get( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $params = (array) $request->get_json_params();
        $path   = isset( $params['path'] ) ? (string) $params['path'] : '';
        if ( ! $this->is_allowed_path( $path ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'path not allowed' ), 403 );
        }
        $query = isset( $params['query'] ) && is_array( $params['query'] ) ? array_map( 'strval', $params['query'] ) : array();
        $response = $this->hub_client->request_signed( 'GET', $path, null, array(), $query );
        return $this->passthrough( $response );
    }

    /**
     * 通用签名 POST 代理。body: { path, payload }
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_hub_post( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $params = (array) $request->get_json_params();
        $path   = isset( $params['path'] ) ? (string) $params['path'] : '';
        if ( ! $this->is_allowed_path( $path ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'path not allowed' ), 403 );
        }
        $payload  = isset( $params['payload'] ) && is_array( $params['payload'] ) ? $params['payload'] : array();
        $response = $this->hub_client->request_signed( 'POST', $path, $payload );
        return $this->passthrough( $response );
    }

	/**
	 * 立即推送图谱。
	 *
	 * 推送前先执行一次反向对账（如有），确保本地 wp_links 包含 Hub 侧的所有边，
	 * 避免全量快照覆盖掉由其他方式在 Hub 上建立的关系。
	 *
	 * @param WP_REST_Request $request 请求。
	 * @return WP_REST_Response
	 */
	public function handle_push_graph( WP_REST_Request $request ) {
		if ( ! $this->push_service ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'push service unavailable' ), 500 );
		}
		// 推送前先反向对账，确保本地有 Hub 侧所有边。
		if ( $this->friend_sync ) {
			$this->friend_sync->reconcile( 'pre-push' );
		}
		$reason = (string) ( $request->get_param( 'reason' ) ?: 'manual' );
		$result = $this->push_service->push_graph( $reason );
		$http   = $result['success'] ? 200 : ( $result['status'] >= 400 && $result['status'] < 600 ? $result['status'] : 502 );
		return new WP_REST_Response(
			array(
				'success'  => $result['success'],
				'status'   => $result['status'],
				'message'  => $result['message'],
				'pushedAt' => $result['pushedAt'],
			),
			$http
		);
	}

	/**
	 * 读取最近同步状态。
	 *
	 * @return WP_REST_Response
	 */
	public function handle_report_status() {
		if ( ! $this->push_service ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'push service unavailable' ), 500 );
		}
		$status = $this->push_service->get_report_status();
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array( 'status' => $status ),
			),
			200
		);
	}

    /**
     * 签发实时连接票据：签名转发 Hub POST /v1/ws-token，返回一次性短期 token。
     *
     * Hub 端 token TTL 仅 2 分钟且一次性消费（见 ws_runtime.go）；浏览器拿到后立即
     * 用 access_token 直连 Hub /v1/ws（WebSocket 不受 CORS 限制，无需 PHP 中转长连接）。
     *
     * @return WP_REST_Response
     */
    public function handle_realtime_token() {
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $response = $this->hub_client->request_signed( 'POST', '/v1/ws-token', null );
        if ( ! $response['success'] ) {
            $status = $response['status'] >= 400 && $response['status'] < 600 ? $response['status'] : 502;
            return new WP_REST_Response(
                array( 'success' => false, 'status' => $response['status'], 'message' => $response['message'] ),
                $status
            );
        }
        $body = $response['body'];
        return new WP_REST_Response(
            array(
                'success' => true,
                // 与 planet/news/graph 一致：业务数据放进 data，前端统一读 resp.data。
                'data'    => array(
                    'token'     => isset( $body['token'] ) ? (string) $body['token'] : '',
                    'expiresAt' => isset( $body['expiresAt'] ) ? (string) $body['expiresAt'] : '',
                ),
            ),
            200
        );
    }

    /**
     * 手动触发一次友链反向对账（链路 B）。
     *
     * @return WP_REST_Response
     */
    public function handle_friend_sync() {
        if ( ! $this->friend_sync ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'friend sync service unavailable' ), 500 );
        }
        $result = $this->friend_sync->reconcile( 'manual' );
        return new WP_REST_Response(
            array( 'success' => $result['success'], 'data' => $result ),
            $result['success'] ? 200 : 400
        );
    }

    /**
     * 读取友链反向对账的最近一次结果。
     *
     * @return WP_REST_Response
     */
    public function handle_friend_sync_status() {
        if ( ! $this->friend_sync ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'friend sync service unavailable' ), 500 );
        }
        return new WP_REST_Response(
            array( 'success' => true, 'data' => $this->friend_sync->get_status() ),
            200
        );
    }

    /**
     * 实时自清理：WS 收到 friend_relation_removed / site_profile_updated 后，前端把事件
     * 原样回传，插件按本站凭据直接处理本地友链（删/改）。100% 对齐 Halo 端
     * HubRealtimeBridge 的 handleFriendRelationRemoved / handleSiteProfileUpdated。
     *
     * @param WP_REST_Request $request 请求（body: { type, data }）。
     * @return WP_REST_Response
     */
    public function handle_friend_sync_peer( WP_REST_Request $request ) {
        if ( ! $this->friend_sync ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'friend sync service unavailable' ), 500 );
        }
        $input = (array) $request->get_json_params();
        $type  = trim( (string) ( $input['type'] ?? '' ) );
        $data  = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();

        if ( 'friend_relation_removed' === $type ) {
            $result = $this->friend_sync->handle_relation_removed( $data );
        } elseif ( 'site_profile_updated' === $type ) {
            $result = $this->friend_sync->handle_profile_updated( $data );
        } else {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'unsupported event type' ), 400 );
        }
        return new WP_REST_Response(
            array( 'success' => $result['success'], 'data' => $result ),
            $result['success'] ? 200 : 400
        );
    }

    /**
     * 关系图头像代理：服务端拉取远端头像后同源回传，避免 3D canvas 被跨域图片污染。
     *
     * 安全：仅允许 http/https 的公网图片 URL；拒绝内网/环回地址（SSRF）；限制响应体大小与
     * content-type 必须为 image/*。失败时返回 1x1 透明 PNG，让前端 onerror 回退到默认头像。
     *
     * @param WP_REST_Request $request 请求（query: url）。
     * @return WP_REST_Response
     */
    public function handle_graph_avatar( WP_REST_Request $request ) {
        $url = trim( (string) $request->get_param( 'url' ) );
        if ( '' === $url || ! $this->is_safe_remote_image_url( $url ) ) {
            return $this->blank_avatar_response();
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => 8,
                'redirection' => 2,
                'headers'     => array( 'Accept' => 'image/*' ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return $this->blank_avatar_response();
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return $this->blank_avatar_response();
        }

        $content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
        if ( 0 !== strpos( strtolower( $content_type ), 'image/' ) ) {
            return $this->blank_avatar_response();
        }

        $body = wp_remote_retrieve_body( $response );
        // 上限 2MB，避免被拖大响应。
        if ( '' === $body || strlen( $body ) > 2097152 ) {
            return $this->blank_avatar_response();
        }

        // REST 框架会对返回值做 JSON 序列化，二进制图片必须直接输出原始字节后退出。
        $this->emit_binary( $content_type, $body, 'public, max-age=86400' );
    }

    /**
     * 校验远端图片 URL 是否安全（http/https、非内网/环回，防 SSRF）。
     *
     * @param string $url URL。
     * @return bool
     */
    private function is_safe_remote_image_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        $scheme = strtolower( (string) $parts['scheme'] );
        if ( 'http' !== $scheme && 'https' !== $scheme ) {
            return false;
        }
        $host = strtolower( (string) $parts['host'] );
        if ( 'localhost' === $host || '' === $host ) {
            return false;
        }
        // 若 host 是 IP，拒绝内网/环回/链路本地地址。
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $public = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ( false === $public ) {
                return false;
            }
        }
        return true;
    }

    /**
     * 1x1 透明 PNG 响应（头像加载失败的兜底）。直接输出字节后退出。
     *
     * @return void
     */
    private function blank_avatar_response() {
        $png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' );
        $this->emit_binary( 'image/png', $png, 'no-store' );
    }

    /**
     * 直接输出二进制响应体并终止请求（绕过 REST 的 JSON 序列化）。
     *
     * @param string $content_type Content-Type。
     * @param string $bytes        响应体字节。
     * @param string $cache        Cache-Control。
     * @return void
     */
    private function emit_binary( $content_type, $bytes, $cache ) {
        if ( ! headers_sent() ) {
            status_header( 200 );
            header( 'Content-Type: ' . $content_type );
            header( 'Cache-Control: ' . $cache );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Content-Length: ' . strlen( $bytes ) );
        }
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 原始二进制图片。
        exit;
    }

    /**
     * 路径是否在白名单内。
     *
     * @param string $path 路径。
     * @return bool
     */
    private function is_allowed_path( $path ) {
        if ( '' === $path || '/' !== $path[0] ) {
            return false;
        }
        // 拒绝包含 query / 片段的注入。
        if ( false !== strpos( $path, '?' ) || false !== strpos( $path, '#' ) ) {
            return false;
        }
        foreach ( $this->allowed_prefixes as $prefix ) {
            if ( 0 !== strpos( $path, $prefix ) ) {
                continue;
            }
            // 以 '/' 结尾的前缀本身已含边界，直接放行。
            if ( '/' === substr( $prefix, -1 ) ) {
                return true;
            }
            // 非 '/' 结尾的前缀视为端点：要求完全相等或下一个字符是 '/'，
            // 避免 /v1/friend-invitations 误匹配 /v1/friend-invitations-evil。
            $next = substr( $path, strlen( $prefix ), 1 );
            if ( '' === $next || '/' === $next ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 把 Hub 响应原样透传给前端（保留状态与 body）。
     *
     * @param array $response Hub 响应。
     * @return WP_REST_Response
     */
    private function passthrough( array $response ) {
        $http_status = $response['success'] ? 200 : ( $response['status'] >= 400 && $response['status'] < 600 ? $response['status'] : 502 );
        return new WP_REST_Response(
            array(
                'success' => (bool) $response['success'],
                'status'  => (int) $response['status'],
                'message' => (string) $response['message'],
                'data'    => $response['body'],
            ),
            $http_status
        );
    }

    /**
     * 世界频道表情文件代理：服务端从 Hub 拉取 sticker 图片后同源回传。
     *
     * @param WP_REST_Request $request 请求。
     * @return void
     */
    public function handle_world_chat_sticker_file( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            wp_die( 'not registered', 400 );
        }
        $sticker_id = rawurldecode( (string) $request->get_param( 'stickerId' ) );
        if ( '' === $sticker_id ) {
            wp_die( 'invalid sticker id', 400 );
        }
        $response = $this->hub_client->request_signed(
            'GET',
            '/v1/world-chat/stickers/' . rawurlencode( $sticker_id ) . '/file'
        );
        if ( ! $response['success'] ) {
            wp_die( 'sticker not found', 404 );
        }
        $content_type = isset( $response['contentType'] )
            ? (string) $response['contentType']
            : 'image/png';
        $raw_data = isset( $response['body']['data'] ) ? (string) $response['body']['data'] : '';
        if ( '' === $raw_data ) {
            $this->blank_avatar_response();
            return;
        }
        $bytes = base64_decode( $raw_data, true );
        if ( false === $bytes || '' === $bytes ) {
            $this->blank_avatar_response();
            return;
        }
        $this->emit_binary( $content_type, $bytes, 'public, max-age=3600' );
    }

    /**
     * 通用资源代理：前端 restResourceUrl 指向此法，服务端从 Hub 拉取资源后同源回传。
     * URL 格式：/hub/resource?url=/v1/world-chat/stickers/{id}/file
     * 安全：url 必须是以 /v1/ 开头的已注册路径（复用 is_allowed_path 校验）。
     *
     * @param WP_REST_Request $request 请求。
     * @return void
     */
    public function handle_hub_resource( WP_REST_Request $request ) {
        if ( ! $this->credentials->is_registered() ) {
            wp_die( 'not registered', 400 );
        }
        $hub_path = trim( (string) $request->get_param( 'url' ) );
        if ( '' === $hub_path || ! $this->is_allowed_path( $hub_path ) ) {
            wp_die( 'path not allowed', 403 );
        }
        $response = $this->hub_client->request_signed( 'GET', $hub_path );
        if ( ! $response['success'] ) {
            $this->blank_avatar_response();
            return;
        }
        $content_type = isset( $response['contentType'] )
            ? (string) $response['contentType']
            : 'application/octet-stream';
        $raw_data = isset( $response['body']['data'] ) ? (string) $response['body']['data'] : '';
        if ( '' === $raw_data ) {
            $this->blank_avatar_response();
            return;
        }
        $bytes = base64_decode( $raw_data, true );
        if ( false === $bytes || '' === $bytes ) {
            $this->blank_avatar_response();
            return;
        }
        $cache = ( 0 === strpos( $content_type, 'image/' ) ) ? 'public, max-age=3600' : 'no-store';
        $this->emit_binary( $content_type, $bytes, $cache );
    }

    /**
     * 读取前台挂件设置。
     *
     * @return WP_REST_Response
     */
    public function handle_get_widget_settings() {
        if ( ! $this->frontend_widget ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'widget service unavailable' ), 500 );
        }
        return new WP_REST_Response(
            array( 'success' => true, 'data' => $this->frontend_widget->get_settings() ),
            200
        );
    }

    /**
     * 保存前台挂件设置。body: { enabled, realtimeEnabled }
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_save_widget_settings( WP_REST_Request $request ) {
        if ( ! $this->frontend_widget ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'widget service unavailable' ), 500 );
        }
        $input  = (array) $request->get_json_params();
        $update = array();
        if ( array_key_exists( 'enabled', $input ) ) {
            $update['enabled'] = (bool) $input['enabled'];
        }
        if ( array_key_exists( 'realtimeEnabled', $input ) ) {
            $update['realtimeEnabled'] = (bool) $input['realtimeEnabled'];
        }
        $this->frontend_widget->save_settings( $update );
        return new WP_REST_Response(
            array( 'success' => true, 'data' => $this->frontend_widget->get_settings() ),
            200
        );
    }

    /**
     * 站点友链搬家：签名从 Hub 拉权威快照，校验后完全替换本地数据。
     *
     * @return WP_REST_Response
     */
    public function handle_site_migration_restore() {
        if ( ! $this->site_migration ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'migration service unavailable' ), 500 );
        }
        if ( ! $this->credentials->is_registered() ) {
            return $this->not_registered();
        }
        $result = $this->site_migration->migrate();
        $status = isset( $result['status'] ) ? (int) $result['status'] : ( $result['success'] ? 200 : 400 );
        $http   = $result['success'] ? 200 : ( $status >= 400 && $status < 600 ? $status : 400 );
        return new WP_REST_Response(
            array(
                'success' => (bool) $result['success'],
                'status'  => $status,
                'message' => (string) $result['message'],
                'data'    => isset( $result['data'] ) ? $result['data'] : array(),
            ),
            $http
        );
    }

    /**
     * 未登舱响应。
     *
     * @return WP_REST_Response
     */
    private function not_registered() {
        return new WP_REST_Response(
            array(
                'success' => false,
                'status'  => 400,
                'message' => '当前站点未注册，暂时无法读取友链邀请',
                'data'    => array(),
            ),
            400
        );
    }

    // ========== 多节点管理（对齐 Halo AstraHubNodeSelectorRouter） ==========

    /**
     * 读取节点状态快照（不触发新探针）。
     *
     * @return WP_REST_Response
     */
    public function handle_node_status() {
        if ( ! $this->node_selector ) {
            return new WP_REST_Response(
                array( 'success' => false, 'message' => 'node selector unavailable', 'data' => array() ),
                500
            );
        }
        return new WP_REST_Response(
            array(
                'success' => true,
                'data'    => $this->node_selector->status_snapshot(),
            ),
            200
        );
    }

    /**
     * 立即对所有节点跑一轮 /healthz 探针。
     *
     * @return WP_REST_Response
     */
    public function handle_node_status_refresh() {
        if ( ! $this->node_selector ) {
            return new WP_REST_Response(
                array( 'success' => false, 'message' => 'node selector unavailable', 'data' => array() ),
                500
            );
        }
        $snapshot = $this->node_selector->refresh_nodes();
        $healthy = 0;
        foreach ( $snapshot['nodes'] as $n ) {
            if ( 'healthy' === ( $n['status'] ?? '' ) ) {
                $healthy++;
            }
        }
        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => 0 === $healthy
                    ? '所有节点均未通过健康检测'
                    : sprintf( '已检测 %d 个节点，%d 个健康', count( $snapshot['nodes'] ), $healthy ),
                'data'    => $snapshot,
            ),
            200
        );
    }

    /**
     * 手动锁定某个 Hub 节点。
     *
     * 约束（对齐 Halo AstraHubNodeSelector.selectNode）：
     *   1. URL 必须在内置列表中。
     *   2. 该节点必须通过了最近一次健康检测（状态 healthy，且 lastCheckedAt 未过期）。
     *
     * @param WP_REST_Request $request 请求（body: { url }）。
     * @return WP_REST_Response
     */
    public function handle_node_selection( WP_REST_Request $request ) {
        if ( ! $this->node_selector ) {
            return new WP_REST_Response(
                array( 'success' => false, 'message' => 'node selector unavailable', 'data' => array() ),
                500
            );
        }
        $input = (array) $request->get_json_params();
        $url   = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
        if ( '' === $url ) {
            return new WP_REST_Response(
                array( 'success' => false, 'message' => '缺少 url 参数' ),
                400
            );
        }
        try {
            $snapshot = $this->node_selector->select_node( $url );
            return new WP_REST_Response(
                array(
                    'success' => true,
                    'message' => '已手动切换到该节点，将在 1 小时后自动重新检测',
                    'data'    => $snapshot,
                ),
                200
            );
        } catch ( RuntimeException $e ) {
            return new WP_REST_Response(
                array( 'success' => false, 'message' => $e->getMessage() ),
                400
            );
        }
    }
}
