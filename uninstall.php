<?php
/**
 * 卸载清理：删除插件写入的所有持久化数据。
 *
 * WordPress 在用户「删除」插件时自动加载本文件（无需注册 hook）。这里清理：
 *   - 凭据 / 连接快照（含 apiKey、siteId 等敏感信息）
 *   - 上报状态、友链反向对账状态
 *   - 由本插件为启用 Links Manager 而写入的 link_manager_enabled
 *   - 残留的定时任务
 *
 * 注意：不删除 wp_insert_link 建立的本地友链（属于用户内容，由用户自行管理）。
 *
 * @package WPAstraHub
 */

// 仅允许在卸载流程中执行。
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// 与各服务类常量保持一致的 option 名称（此处硬编码，因卸载时类未必加载）。
$wp_astrahub_options = array(
    'wp_astrahub_credentials',       // WP_AstraHub_Credential_Store::OPTION_CREDENTIALS
    'wp_astrahub_connection',        // WP_AstraHub_Credential_Store::OPTION_CONNECTION
    'wp_astrahub_report_status',     // WP_AstraHub_Push_Service::OPTION_REPORT
    'wp_astrahub_friend_sync_status', // WP_AstraHub_Friend_Sync_Service::OPTION_LAST
);

foreach ( $wp_astrahub_options as $wp_astrahub_option ) {
    delete_option( $wp_astrahub_option );
    // 多站点：清理各站点同名 option。
    if ( is_multisite() ) {
        delete_site_option( $wp_astrahub_option );
    }
}

// 本插件曾为使用 Links Manager 而自动开启 link_manager_enabled，卸载时恢复关闭。
// 仅当当前为开启态才动它，避免覆盖用户可能的其它意图（值恢复为 0）。
if ( get_option( 'link_manager_enabled' ) ) {
    update_option( 'link_manager_enabled', 0 );
}

// 清理定时任务（与 WP_AstraHub_Cron::HOOK 一致）。
wp_clear_scheduled_hook( 'wp_astrahub_push_graph_cron' );
