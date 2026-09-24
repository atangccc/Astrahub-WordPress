<?php
/**
 * 插件主类（单例）。
 *
 * 阶段 0：搭骨架 + 装配核心服务（凭据存储、签名、Hub 客户端），并提供一个
 * 管理员可访问的签名自检入口，用于核对 PHP 签名实现与 Hub 服务端对齐。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Plugin {

    /**
     * 单例。
     *
     * @var WP_AstraHub_Plugin|null
     */
    private static $instance = null;

    /**
     * 凭据存储。
     *
     * @var WP_AstraHub_Credential_Store
     */
    private $credentials;

    /**
     * Hub 客户端。
     *
     * @var WP_AstraHub_Hub_Client
     */
    private $hub_client;

    /**
     * 多节点选择器。
     *
     * @var WP_AstraHub_Node_Selector
     */
    private $node_selector;

    /**
     * 注册服务。
     *
     * @var WP_AstraHub_Register_Service
     */
    private $register_service;

    /**
     * 注册 REST 路由。
     *
     * @var WP_AstraHub_Rest_Register
     */
    private $rest_register;

    /**
     * 代理 REST 路由。
     *
     * @var WP_AstraHub_Rest_Proxy
     */
    private $rest_proxy;

    /**
     * 图谱采集器。
     *
     * @var WP_AstraHub_Graph_Collector
     */
    private $collector;

    /**
     * 推送服务。
     *
     * @var WP_AstraHub_Push_Service
     */
    private $push_service;

    /**
     * Cron 调度。
     *
     * @var WP_AstraHub_Cron
     */
    private $cron;

    /**
     * 本地建链。
     *
     * @var WP_AstraHub_Link_Reconcile
     */
    private $reconcile;

    /**
     * 友链反向对账服务（链路 B）。
     *
     * @var WP_AstraHub_Friend_Sync_Service
     */
    private $friend_sync;

    /**
     * 友链管理 REST。
     *
     * @var WP_AstraHub_Rest_Friend
     */
    private $rest_friend;

    /**
     * 前台挂件注入器。
     *
     * @var WP_AstraHub_Frontend_Widget
     */
    private $frontend_widget;

    /**
     * 站点友链搬家服务。
     *
     * @var WP_AstraHub_Site_Migration
     */
    private $site_migration;

    /**
     * 获取单例。
     *
     * @return WP_AstraHub_Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造：装配服务 + 注册钩子。
     */
    private function __construct() {
        $this->credentials   = new WP_AstraHub_Credential_Store();

        // 多节点选择器（防御性 class_exists，若 node-selector.php 缺失则优雅降级到单节点模式）。
        $node_selector_exists = class_exists( 'WP_AstraHub_Node_Selector' );
        $this->node_selector  = $node_selector_exists ? new WP_AstraHub_Node_Selector() : null;
        $this->hub_client     = new WP_AstraHub_Hub_Client( $this->credentials, $this->node_selector );
        if ( $this->node_selector ) {
            $this->node_selector->set_hub_client( $this->hub_client );
        }

        $this->register_service = new WP_AstraHub_Register_Service( $this->hub_client, $this->credentials );
        $this->rest_register    = new WP_AstraHub_Rest_Register( $this->register_service, $this->credentials );
        $this->rest_proxy       = new WP_AstraHub_Rest_Proxy( $this->hub_client, $this->credentials );
        if ( $this->node_selector ) {
            $this->rest_proxy->set_node_selector( $this->node_selector );
        }

        $this->collector        = new WP_AstraHub_Graph_Collector( $this->credentials );
        $this->push_service     = new WP_AstraHub_Push_Service( $this->hub_client, $this->credentials, $this->collector );
        $this->rest_proxy->set_push_service( $this->push_service );
        $this->reconcile        = new WP_AstraHub_Link_Reconcile();
        $this->friend_sync      = new WP_AstraHub_Friend_Sync_Service( $this->hub_client, $this->credentials, $this->reconcile );
        $this->cron             = new WP_AstraHub_Cron( $this->push_service, $this->friend_sync );
        $this->rest_friend      = new WP_AstraHub_Rest_Friend( $this->hub_client, $this->credentials, $this->reconcile );
        $this->rest_proxy->set_friend_sync_service( $this->friend_sync );

        $this->frontend_widget  = new WP_AstraHub_Frontend_Widget( $this->credentials, $this->push_service );
        $this->rest_proxy->set_frontend_widget( $this->frontend_widget );

        // 站点友链搬家服务（防御性 class_exists，若 site-migration.php 缺失则静默跳过）。
        $site_migration_exists = class_exists( 'WP_AstraHub_Site_Migration' );
        $this->site_migration  = $site_migration_exists
            ? new WP_AstraHub_Site_Migration( $this->hub_client, $this->credentials, $this->reconcile )
            : null;
        if ( $this->site_migration ) {
            $this->rest_proxy->set_site_migration( $this->site_migration );
        }

        // 认证兜底：如果 WP 核心的 rest_cookie_check_errors 因为 nonce 失败拦截了请求，
        // 但用户已登录且有 manage_options，则放行。虚拟主机上宝塔/Nginx WAF 可能会过滤
        // 掉 _wpnonce query 参数，导致 WP 原生 cookie 认证在 nonce 验证阶段就提前拒绝。
        add_filter( 'rest_authentication_errors', array( $this, 'soften_rest_auth_errors' ), 99 );

        add_action( 'rest_api_init', array( $this->rest_register, 'register_routes' ) );
        add_action( 'rest_api_init', array( $this->rest_proxy, 'register_routes' ) );
        add_action( 'rest_api_init', array( $this->rest_friend, 'register_routes' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'init', array( $this->cron, 'register' ) );
        $this->frontend_widget->register();
        register_deactivation_hook( WP_ASTRAHUB_FILE, array( 'WP_AstraHub_Cron', 'clear' ) );
    }

    /**
     * 认证兜底：nonce 错误但已登录管理员则放行。
     *
     * @param WP_Error|null|bool $result 认证结果。
     * @return WP_Error|null|bool
     */
    public function soften_rest_auth_errors( $result ) {
        if ( is_wp_error( $result ) ) {
            $code = $result->get_error_code();
            // rest_cookie_invalid_nonce: nonce 无效/过期; rest_cookie_missing_session: session 丢失。
            if ( in_array( $code, array( 'rest_cookie_invalid_nonce', 'rest_cookie_missing_session', 'rest_cookie_colors_required' ), true ) ) {
                // 先让 wp 加载当前用户（如果还没加载的话）。
                if ( ! did_action( 'wp_loaded' ) ) {
                    wp_get_current_user();
                }
                if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
                    // 放行 — 后续各路由的 permission_callback 会再次检查 manage_options。
                    error_log( '[AstraHub] soften_rest_auth_errors bypassed nonce=' . $code . ' for logged-in admin (user_id=' . get_current_user_id() . ')' );
                    return null;
                }
            }
        }
        return $result;
    }

    /**
     * 凭据存储访问器。
     *
     * @return WP_AstraHub_Credential_Store
     */
    public function credentials() {
        return $this->credentials;
    }

    /**
     * Hub 客户端访问器。
     *
     * @return WP_AstraHub_Hub_Client
     */
    public function hub_client() {
        return $this->hub_client;
    }

    /**
     * 注册服务访问器。
     *
     * @return WP_AstraHub_Register_Service
     */
    public function register_service() {
        return $this->register_service;
    }

    /**
     * 注册后台菜单页。Vue SPA 在阶段 2 挂载到此页的容器上；
     * 当前阶段先输出容器与最小占位，确保菜单与挂载点就位。
     */
    public function register_admin_menu() {
        $hook = add_menu_page(
            'AstraHub 星链',
            'AstraHub 星链',
            'manage_options',
            'wp-astrahub',
            array( $this, 'render_admin_page' ),
            'dashicons-share',
            58
        );
        add_action( 'admin_print_scripts-' . $hook, array( $this, 'bootstrap_admin_data' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /**
     * 仅在本插件后台页加载 Vue SPA 构建产物。
     *
     * @param string $hook_suffix 当前后台页 hook。
     */
    public function enqueue_admin_assets( $hook_suffix ) {
        if ( 'toplevel_page_wp-astrahub' !== $hook_suffix ) {
            return;
        }
        $js  = WP_ASTRAHUB_DIR . 'assets/dist/wp-astrahub-admin.js';
        $css = WP_ASTRAHUB_DIR . 'assets/dist/wp-astrahub-admin.css';
        if ( is_readable( $js ) ) {
            wp_enqueue_script(
                'wp-astrahub-admin',
                WP_ASTRAHUB_URL . 'assets/dist/wp-astrahub-admin.js',
                array(),
                filemtime( $js ),
                true
            );
        }
        if ( is_readable( $css ) ) {
            wp_enqueue_style(
                'wp-astrahub-admin',
                WP_ASTRAHUB_URL . 'assets/dist/wp-astrahub-admin.css',
                array(),
                filemtime( $css )
            );
        }
    }

    /**
     * 输出后台页面挂载容器。
     */
    public function render_admin_page() {
        echo '<div class="wrap"><div id="wp-astrahub-app" data-astrahub-app></div></div>';
    }

    /**
     * 向后台页面注入引导数据（REST 根地址、nonce、Hub 地址）。
     * 阶段 2 的 Vue SPA 会读取 window.WP_ASTRAHUB_BOOTSTRAP。
     */
    public function bootstrap_admin_data() {
        $bootstrap = array(
            'restBase'   => esc_url_raw( rest_url( WP_AstraHub_Rest_Register::NAMESPACE ) ),
            'restNonce'  => wp_create_nonce( 'wp_rest' ),
            'hubBaseUrl' => WP_ASTRAHUB_HUB_BASE_URL,
            'registered' => $this->credentials->is_registered(),
        );
        echo '<script>window.WP_ASTRAHUB_BOOTSTRAP = ' . wp_json_encode( $bootstrap ) . ';</script>';
    }
}
