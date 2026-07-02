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
        $this->credentials = new WP_AstraHub_Credential_Store();
        $this->hub_client  = new WP_AstraHub_Hub_Client( $this->credentials );
        $this->register_service = new WP_AstraHub_Register_Service( $this->hub_client, $this->credentials );
        $this->rest_register    = new WP_AstraHub_Rest_Register( $this->register_service, $this->credentials );
        $this->rest_proxy       = new WP_AstraHub_Rest_Proxy( $this->hub_client, $this->credentials );
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

        add_action( 'rest_api_init', array( $this->rest_register, 'register_routes' ) );
        add_action( 'rest_api_init', array( $this->rest_proxy, 'register_routes' ) );
        add_action( 'rest_api_init', array( $this->rest_friend, 'register_routes' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'init', array( $this->cron, 'register' ) );
        $this->frontend_widget->register();
        register_deactivation_hook( WP_ASTRAHUB_FILE, array( 'WP_AstraHub_Cron', 'clear' ) );
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
