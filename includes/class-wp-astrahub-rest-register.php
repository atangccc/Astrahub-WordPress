<?php
/**
 * 注册 / 登舱 REST 路由。
 *
 * 命名空间：wp-astrahub/v1
 * 这些路由由后台 UI（管理员）调用，插件再转发给 Hub。全部要求 manage_options 权限。
 *
 * 端点：
 *   POST /register                直接注册
 *   POST /invitation/request      申请邮箱签发码
 *   POST /invitation/register     用签发码注册
 *   POST /boarding/send-code      重登舱发码
 *   POST /boarding/restore        重登舱恢复
 *   GET  /status                  当前登舱状态 + 连接配置
 *   POST /connection              保存连接配置（不触发 Hub）
 *   POST /logout                  清空本地凭据
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Rest_Register {

    const NAMESPACE = 'wp-astrahub/v1';

    /**
     * 注册服务。
     *
     * @var WP_AstraHub_Register_Service
     */
    private $register_service;

    /**
     * 凭据存储。
     *
     * @var WP_AstraHub_Credential_Store
     */
    private $credentials;

    /**
     * 构造。
     *
     * @param WP_AstraHub_Register_Service $register_service 注册服务。
     * @param WP_AstraHub_Credential_Store $credentials      凭据存储。
     */
    public function __construct( WP_AstraHub_Register_Service $register_service, WP_AstraHub_Credential_Store $credentials ) {
        $this->register_service = $register_service;
        $this->credentials      = $credentials;
    }

    /**
     * 注册路由。
     */
    public function register_routes() {
        $permission = array( $this, 'check_permission' );

        register_rest_route(
            self::NAMESPACE,
            '/register',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_register' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/invitation/request',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_invitation_request' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/invitation/register',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_invitation_register' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/boarding/send-code',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_boarding_send_code' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/boarding/restore',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_boarding_restore' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/status',
            array(
                'methods'             => 'GET',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_status' ),
            )
        );

        // 接入密钥明文（仅管理员，用于「显示/复制」，对齐 Halo 端 settings.credentials.apiKey）。
        register_rest_route(
            self::NAMESPACE,
            '/credentials/reveal',
            array(
                'methods'             => 'GET',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_reveal_api_key' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/connection',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_save_connection' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/logout',
            array(
                'methods'             => 'POST',
                'permission_callback' => $permission,
                'callback'            => array( $this, 'handle_logout' ),
            )
        );
    }

    /**
     * 权限校验：必须是能管理选项的管理员。
     *
     * REST nonce（X-WP-Nonce）由 WordPress 在 rest_cookie_check_errors 中处理，
     * 此处只需校验能力。
     *
     * @return bool
     */
    public function check_permission() {
        return current_user_can( 'manage_options' );
    }

    /**
     * 直接注册。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_register( WP_REST_Request $request ) {
        $input  = $this->merge_connection_defaults( $request->get_json_params() );
        $result = $this->register_service->register( $input );
        return $this->respond( $result );
    }

    /**
     * 申请邮箱签发码。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_invitation_request( WP_REST_Request $request ) {
        $input  = (array) $request->get_json_params();
        if ( empty( $input['siteUrl'] ) ) {
            $input['siteUrl'] = home_url();
        }
        $result = $this->register_service->request_invitation( $input );
        return $this->respond( $result );
    }

    /**
     * 用签发码注册。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_invitation_register( WP_REST_Request $request ) {
        $input  = $this->merge_connection_defaults( $request->get_json_params() );
        $result = $this->register_service->register_with_invitation( $input );
        return $this->respond( $result );
    }

    /**
     * 重登舱发码。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_boarding_send_code( WP_REST_Request $request ) {
        $result = $this->register_service->send_boarding_code( (array) $request->get_json_params() );
        return $this->respond( $result );
    }

    /**
     * 重登舱恢复。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_boarding_restore( WP_REST_Request $request ) {
        $result = $this->register_service->restore_boarding( (array) $request->get_json_params() );
        return $this->respond( $result );
    }

    /**
     * 当前登舱状态 + 连接配置（不回显 apiKey 原值）。
     *
     * @return WP_REST_Response
     */
    public function handle_status() {
        $creds = $this->credentials->get_credentials();
        return new WP_REST_Response(
            array(
                'success' => true,
                'data'    => array(
                    'registered'  => $this->credentials->is_registered(),
                    'credentials' => array(
                        'siteId'      => $creds['siteId'],
                        'apiKeyMask'  => $this->mask_secret( $creds['apiKey'] ),
                        'hasApiKey'   => '' !== trim( $creds['apiKey'] ),
                        'createdAt'   => $creds['createdAt'],
                        'nodeName'    => $creds['nodeName'],
                        'category'    => $creds['category'],
                        'nodeAvatar'  => $creds['nodeAvatar'],
                    ),
                    'connection' => $this->credentials->get_connection(),
                    'hubBaseUrl' => WP_ASTRAHUB_HUB_BASE_URL,
                ),
            ),
            200
        );
    }

    /**
     * 返回接入密钥明文（仅管理员）。对齐 Halo：后台「显示」按钮可看到真实密钥。
     *
     * @return WP_REST_Response
     */
    public function handle_reveal_api_key() {
        $creds = $this->credentials->get_credentials();
        return new WP_REST_Response(
            array(
                'success' => true,
                'data'    => array(
                    'apiKey' => (string) $creds['apiKey'],
                ),
            ),
            200
        );
    }

    /**
     * 保存连接配置（仅本地，不触发 Hub）。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response
     */
    public function handle_save_connection( WP_REST_Request $request ) {
        $input   = (array) $request->get_json_params();
        $allowed = array( 'siteName', 'siteUrl', 'siteDescription', 'siteRssUrl', 'siteAvatarUrl', 'contactEmail', 'siteNodeName', 'siteNodeAvatar' );
        $clean   = array();
        foreach ( $allowed as $key ) {
            if ( isset( $input[ $key ] ) ) {
                $clean[ $key ] = trim( (string) $input[ $key ] );
            }
        }
        $this->credentials->save_connection( $clean );
        return new WP_REST_Response(
            array(
                'success'    => true,
                'message'    => 'connection saved',
                'connection' => $this->credentials->get_connection(),
            ),
            200
        );
    }

    /**
     * 清空本地凭据（退出 / 重登舱前）。
     *
     * @return WP_REST_Response
     */
    public function handle_logout() {
        $this->credentials->clear_credentials();
        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => 'logged out',
            ),
            200
        );
    }

    /**
     * 把缺省连接信息合并进注册输入（用户未填则用 WP 站点信息）。
     *
     * @param mixed $input 原始 JSON 参数。
     * @return array
     */
    private function merge_connection_defaults( $input ) {
        $input = (array) $input;
        $conn  = $this->credentials->get_connection();
        $map   = array(
            'siteName'        => $conn['siteName'],
            'siteUrl'         => $conn['siteUrl'],
            'siteDescription' => $conn['siteDescription'],
            'siteRssUrl'      => $conn['siteRssUrl'],
            'siteAvatarUrl'   => $conn['siteAvatarUrl'],
            'contactEmail'    => $conn['contactEmail'],
            'siteNodeName'    => $conn['siteNodeName'],
            'siteNodeAvatar'  => $conn['siteNodeAvatar'],
        );
        foreach ( $map as $key => $default ) {
            if ( empty( $input[ $key ] ) && '' !== (string) $default ) {
                $input[ $key ] = $default;
            }
        }
        return $input;
    }

    /**
     * 把服务层结果转成 REST 响应。
     *
     * @param array $result 服务层结果。
     * @return WP_REST_Response
     */
    private function respond( array $result ) {
        $status = isset( $result['status'] ) ? (int) $result['status'] : ( $result['success'] ? 200 : 400 );
        // 统一对外用 200 包裹业务状态，避免前端 fetch 误判 HTTP 层错误。
        $http_status = $result['success'] ? 200 : ( $status >= 400 && $status < 600 ? $status : 400 );
        return new WP_REST_Response(
            array(
                'success' => (bool) $result['success'],
                'status'  => $status,
                'message' => isset( $result['message'] ) ? $result['message'] : '',
                'data'    => isset( $result['data'] ) ? $result['data'] : array(),
            ),
            $http_status
        );
    }

    /**
     * 掩码敏感串（只显示前后各 4 位）。
     *
     * @param string $secret 密钥。
     * @return string
     */
    private function mask_secret( $secret ) {
        $secret = (string) $secret;
        $len    = strlen( $secret );
        if ( 0 === $len ) {
            return '';
        }
        if ( $len <= 8 ) {
            return str_repeat( '*', $len );
        }
        return substr( $secret, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $secret, -4 );
    }
}
