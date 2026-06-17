<?php
/**
 * 前台星链挂件注入器。
 *
 * 对应 Halo 端 AstraHubGalaxyWidgetHeadProcessor：在前台页面注入
 *   <style id="astrahub-galaxy-widget-style">  挂件样式
 *   <script id="astrahub-galaxy-widget-data" type="application/json">  挂件数据（站点/节点/创作者/状态）
 *   <script id="astrahub-galaxy-widget-script">  挂件脚本（live2d 吉祥物 + 面板 + 状态气泡）
 * 三段内容，由 galaxy-link-widget.js 据此渲染右下角 live2d 吉祥物挂件。
 *
 * 资源（CSS/JS/live2d）原样取自 Halo 插件 plugin-astrahub/src/main/resources/static，
 * 仅 live2d 资源基址改为 WP 插件 assets URL（通过 window.WP_ASTRAHUB_WIDGET 注入）。
 *
 * 仅在「显示前台挂件」开启且站点已接入星链时渲染（对齐 Halo widgetEnabled && linked）。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Frontend_Widget {

    const OPTION_SETTINGS = 'wp_astrahub_widget_settings';

    /**
     * 凭据存储。
     *
     * @var WP_AstraHub_Credential_Store
     */
    private $credentials;

    /**
     * 推送服务（用于读取最近同步健康度）。
     *
     * @var WP_AstraHub_Push_Service|null
     */
    private $push_service;

    /**
     * 构造。
     *
     * @param WP_AstraHub_Credential_Store $credentials  凭据存储。
     * @param WP_AstraHub_Push_Service     $push_service 推送服务。
     */
    public function __construct( WP_AstraHub_Credential_Store $credentials, WP_AstraHub_Push_Service $push_service = null ) {
        $this->credentials  = $credentials;
        $this->push_service = $push_service;
    }

    /**
     * 注册前台钩子。
     */
    public function register() {
        add_action( 'wp_footer', array( $this, 'render' ), 100 );
    }

    /**
     * 读取挂件设置。
     *
     * @return array{enabled:bool,realtimeEnabled:bool}
     */
    public function get_settings() {
        $stored = get_option( self::OPTION_SETTINGS, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return wp_parse_args(
            $stored,
            array(
                'enabled'         => true,
                'realtimeEnabled' => true,
            )
        );
    }

    /**
     * 保存挂件设置。
     *
     * @param array $settings 设置。
     * @return bool
     */
    public function save_settings( array $settings ) {
        $current = $this->get_settings();
        $merged  = array(
            'enabled'         => array_key_exists( 'enabled', $settings ) ? (bool) $settings['enabled'] : $current['enabled'],
            'realtimeEnabled' => array_key_exists( 'realtimeEnabled', $settings ) ? (bool) $settings['realtimeEnabled'] : $current['realtimeEnabled'],
        );
        return update_option( self::OPTION_SETTINGS, $merged, false );
    }

    /**
     * 前台页脚渲染挂件三段内容。
     */
    public function render() {
        // 后台、feed、REST、AJAX 等非前台页不注入。
        if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        $settings = $this->get_settings();
        $linked   = $this->credentials->is_registered();
        if ( ! $settings['enabled'] || ! $linked ) {
            return;
        }

        $style  = $this->load_asset( 'assets/widget/galaxy-link-widget.css' );
        $script = $this->load_asset( 'assets/widget/galaxy-link-widget.js' );
        if ( '' === $style || '' === $script ) {
            return;
        }

        $data = $this->build_widget_data( $settings );

        $assets_base = esc_js( rtrim( WP_ASTRAHUB_URL, '/' ) . '/assets' );
        $config_json = wp_json_encode(
            array(
                'assetsBase'         => rtrim( WP_ASTRAHUB_URL, '/' ) . '/assets',
                // WP 无同源 public-status 桥接 WS，置空以优雅跳过实时连接（live2d/面板/气泡仍工作）。
                'publicStatusWsPath' => '',
            )
        );

        $json      = wp_json_encode( $data );
        $safe_json = $this->escape_inline_json( $json );

        echo "\n<script>window.WP_ASTRAHUB_WIDGET = " . $config_json . ";</script>\n";
        echo '<style id="astrahub-galaxy-widget-style">' . "\n" . $style . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 静态资源 CSS。
        echo '<script id="astrahub-galaxy-widget-data" type="application/json">' . $safe_json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已转义内联 JSON。
        echo '<script id="astrahub-galaxy-widget-script">' . "\n" . $script . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 静态资源 JS。
    }

    /**
     * 构造挂件数据（对齐 Halo buildWidgetData）。
     *
     * @param array $settings 挂件设置。
     * @return array
     */
    private function build_widget_data( array $settings ) {
        $conn  = $this->credentials->get_connection();
        $creds = $this->credentials->get_credentials();

        $site_name   = $this->str( $conn['siteName'] ) ?: get_bloginfo( 'name' );
        $site_url    = $this->str( $conn['siteUrl'] ) ?: home_url();
        $node_name   = $this->str( $conn['siteNodeName'] ) ?: $this->str( $creds['nodeName'] );
        $node_name   = $node_name ?: $site_name;
        $node_avatar = $this->str( $conn['siteNodeAvatar'] ) ?: $this->str( $creds['nodeAvatar'] );
        $node_avatar = $node_avatar ?: $this->str( $conn['siteAvatarUrl'] );

        // 创作者头像取本站友链（WP blogroll），对齐 Halo 从友链快照取前 4 个。
        $creators     = array();
        $total_links  = 0;
        if ( function_exists( 'get_bookmarks' ) ) {
            $bookmarks   = get_bookmarks( array( 'orderby' => 'rating', 'order' => 'DESC', 'hide_invisible' => true ) );
            $total_links = is_array( $bookmarks ) ? count( $bookmarks ) : 0;
            foreach ( (array) $bookmarks as $bookmark ) {
                if ( count( $creators ) >= 4 ) {
                    break;
                }
                $title = $this->str( $bookmark->link_name ?? '' );
                if ( '' === $title ) {
                    continue;
                }
                $dup = false;
                foreach ( $creators as $existing ) {
                    if ( strcasecmp( $existing['name'], $title ) === 0 ) {
                        $dup = true;
                        break;
                    }
                }
                if ( $dup ) {
                    continue;
                }
                $creators[] = array(
                    'name'   => $title,
                    'avatar' => $this->str( $bookmark->link_image ?? '' ),
                );
            }
        }
        if ( empty( $creators ) ) {
            $creators[] = array( 'name' => 'AstraHub', 'avatar' => '' );
        }

        $more_creator_count = max( 0, $total_links - count( $creators ) );
        $featured_creator   = $this->str( $creators[0]['name'] );

        // 健康度：以最近一次同步成功为准（对齐 Halo healthy）。
        $healthy      = true;
        $status_label = '已链接主星';
        if ( $this->push_service ) {
            $report = $this->push_service->get_report_status();
            if ( isset( $report['success'] ) ) {
                $healthy      = (bool) $report['success'];
                $status_label = $healthy ? '已链接主星' : '同步异常';
            }
        }

        return array(
            'render'            => true,
            'linked'            => true,
            'healthy'           => $healthy,
            'siteName'          => $site_name,
            'siteUrl'           => $site_url,
            'nodeName'          => $node_name,
            'nodeAvatar'        => $node_avatar,
            'hubBaseUrl'        => WP_ASTRAHUB_HUB_BASE_URL,
            'joinUrl'           => WP_ASTRAHUB_HUB_BASE_URL ?: $site_url,
            'protocol'          => 'GALAXY-X9',
            'statusLabel'       => $status_label,
            'realtimeBroadcast' => array(
                'enabled'           => (bool) $settings['realtimeEnabled'],
                'minIntervalSeconds' => 15,
            ),
            'creators'          => $creators,
            'featuredCreator'   => $featured_creator,
            'moreCreatorCount'  => $more_creator_count,
            'metrics'           => array(
                'totalLinks'      => $total_links,
                'totalGroups'     => 0,
                'groupedLinks'    => 0,
                'standaloneLinks' => $total_links,
            ),
        );
    }

    /**
     * 读取插件内资源文件文本。
     *
     * @param string $relative 相对插件根的路径。
     * @return string
     */
    private function load_asset( $relative ) {
        $file = WP_ASTRAHUB_DIR . $relative;
        if ( ! is_readable( $file ) ) {
            return '';
        }
        $content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 读取插件内置静态资源。
        return false === $content ? '' : $content;
    }

    /**
     * 内联 JSON 转义（对齐 Halo escapeInlineJson）。
     *
     * @param string $raw JSON。
     * @return string
     */
    private function escape_inline_json( $raw ) {
        return str_replace(
            array( '&', '<', '>' ),
            array( '\\u0026', '\\u003c', '\\u003e' ),
            (string) $raw
        );
    }

    /**
     * 安全字符串。
     *
     * @param mixed $value 值。
     * @return string
     */
    private function str( $value ) {
        return trim( (string) ( $value ?? '' ) );
    }
}
