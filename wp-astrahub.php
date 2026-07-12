<?php
/**
 * Plugin Name:       AstraHub 星链
 * Plugin URI:        https://www.aobp.cn/
 * Description:        把站点接入 AstraHub 星链——独立博客的连接层：整合多生态博主、在线交换友链、沿关系图谱发现同好圈子。
 * Version:           0.2.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Serenity
 * Author URI:        https://www.aobp.cn/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-astrahub
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // 禁止直接访问
}

define( 'WP_ASTRAHUB_VERSION', '0.1.9' );
define( 'WP_ASTRAHUB_FILE', __FILE__ );
define( 'WP_ASTRAHUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_ASTRAHUB_URL', plugin_dir_url( __FILE__ ) );

/**
 * Hub 服务端基础地址（固定写死，不暴露给用户配置）。
 *
 * 所有对 Hub 的请求都以此为前缀。签名时使用的 PATH 为该 URL 之后的路径部分
 * （已解码），与 Go 服务端 r.URL.Path 对齐。
 */
if ( ! defined( 'WP_ASTRAHUB_HUB_BASE_URL' ) ) {
    define( 'WP_ASTRAHUB_HUB_BASE_URL', 'https://astra.aobp.cn' );
}

require_once WP_ASTRAHUB_DIR . 'includes/class-wp-astrahub-autoloader.php';
WP_AstraHub_Autoloader::register();

/**
 * 插件启动入口。
 */
function wp_astrahub() {
    return WP_AstraHub_Plugin::instance();
}

// 启动。
wp_astrahub();
