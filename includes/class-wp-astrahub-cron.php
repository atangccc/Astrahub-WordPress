<?php
/**
 * WP-Cron 定时推送调度。
 *
 * 注册一个每小时触发的事件，登舱后自动推送 bp.site-links.v1 友链快照到 Hub。
 * 对应 Halo 端的后台定时同步。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Cron {

    const HOOK = 'wp_astrahub_push_graph_cron';

    /**
     * 推送服务。
     *
     * @var WP_AstraHub_Push_Service
     */
    private $push_service;

    /**
     * 友链反向对账服务（链路 B，可选）。
     *
     * @var WP_AstraHub_Friend_Sync_Service|null
     */
    private $friend_sync;

    /**
     * 构造。
     *
     * @param WP_AstraHub_Push_Service             $push_service 推送服务。
     * @param WP_AstraHub_Friend_Sync_Service|null $friend_sync  友链反向对账服务。
     */
    public function __construct( WP_AstraHub_Push_Service $push_service, $friend_sync = null ) {
        $this->push_service = $push_service;
        $this->friend_sync  = $friend_sync;
    }

    /**
     * 注册 cron 钩子。
     */
    public function register() {
        add_action( self::HOOK, array( $this, 'run' ) );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 300, 'hourly', self::HOOK );
        }
    }

	/**
	 * cron 执行体：先反向对账拉回 Hub 侧关系（保证本地包含 Hub 上所有边），
	 * 再推送全量友链快照（避免快照覆盖掉 Hub 上由其他方式建立的关系）。
	 */
	public function run() {
		// 链路 B：先拉取 Hub 关系并对账本地友链（删除已失效、刷新资料变更、
		// 补全 Hub 侧有但本地缺少的边），确保本地 wp_links 是 Hub 的超集。
		if ( $this->friend_sync ) {
			$this->friend_sync->reconcile( 'cron' );
		}
		// 再推送全量友链快照到 Hub。
		$this->push_service->push_graph( 'cron' );
	}

    /**
     * 卸载时清理调度（由插件停用钩子调用）。
     */
    public static function clear() {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }
}
