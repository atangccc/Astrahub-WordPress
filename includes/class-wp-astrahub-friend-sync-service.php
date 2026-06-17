<?php
/**
 * 友链反向对账服务（链路 B）。
 *
 * WordPress/PHP 是短生命周期模型，无法像 Halo 端 HubRealtimeBridge 那样常驻
 * WebSocket 监听 friend_relation_removed / site_profile_updated 事件。改用「拉取 +
 * 对账」：cron（或手动）触发时，把本地由本插件建立的友链（link_notes 带
 * astrahub:peer-site-id= 标记）的 URL 批量提交给 Hub 的关系解析端点
 * POST /v1/relations/sites/batch（签名），按返回的 relationKind 决定：
 *
 *   - relationKind = none      且对端已注册 → 双方都没加，关系已不存在 → 删本地友链
 *   - relationKind = one_way_in 且对端已注册 → 我没加、仅对方加了 → 删本地友链
 *   - relationKind = mutual / one_way_out      → 我仍加着 → 保留，并用 targetIdentity
 *                                                刷新本地展示字段（名称/头像/RSS/简介）
 *   - relationKind = unknown / 对端未注册 / 解析失败 → 不确定 → 保留（绝不误删）
 *
 * 幂等、无状态、不依赖管理员在线，最长延迟 = cron 周期（hourly）。这是对链路 A
 * （浏览器实时 UI）的服务端兜底，保证本地友链表最终与 Hub 一致。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Friend_Sync_Service {

    const RECONCILE_PATH = '/v1/relations/sites/batch';
    const OPTION_LAST    = 'wp_astrahub_friend_sync_status';
    const BATCH_LIMIT    = 80;

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
     * 执行一次反向对账。
     *
     * @param string $reason 触发原因（cron / manual）。
     * @return array{success:bool,checked:int,deleted:int,updated:int,skipped:int,message:string,syncedAt:string}
     */
    public function reconcile( $reason = 'cron' ) {
        if ( ! $this->credentials->is_registered() ) {
            return $this->record( false, 0, 0, 0, 0, 'not registered yet', $reason );
        }

        $managed = $this->reconcile->list_managed_links();
        if ( empty( $managed ) ) {
            return $this->record( true, 0, 0, 0, 0, 'no managed links', $reason );
        }

        // 建 URL → 本地链接 的索引（规范化后比对，避免尾斜杠/大小写差异）。
        $by_url = array();
        $urls   = array();
        foreach ( $managed as $link ) {
            $url = $this->normalize_url( $link['url'] );
            if ( '' === $url ) {
                continue;
            }
            $by_url[ $url ] = $link;
            $urls[]         = $link['url']; // 提交原始 URL，Hub 自行规范化。
        }
        if ( empty( $urls ) ) {
            return $this->record( true, 0, 0, 0, 0, 'no resolvable links', $reason );
        }

        $checked = 0;
        $deleted = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = array();

        // 分批，避免单次请求体过大。
        foreach ( array_chunk( $urls, self::BATCH_LIMIT ) as $chunk ) {
            $response = $this->hub_client->request_signed( 'POST', self::RECONCILE_PATH, array( 'targetUrls' => $chunk ) );
            if ( ! $response['success'] ) {
                $errors[] = (string) $response['message'];
                continue;
            }
            $items = isset( $response['body']['items'] ) && is_array( $response['body']['items'] )
                ? $response['body']['items'] : array();
            foreach ( $items as $item ) {
                $checked++;
                $action = $this->apply_relation_result( $item, $by_url );
                if ( 'deleted' === $action ) {
                    $deleted++;
                } elseif ( 'updated' === $action ) {
                    $updated++;
                } else {
                    $skipped++;
                }
            }
        }

        if ( ! empty( $errors ) && 0 === $checked ) {
            return $this->record( false, 0, 0, 0, 0, implode( '; ', array_slice( $errors, 0, 3 ) ), $reason );
        }

        $message = empty( $errors ) ? 'ok' : ( 'partial: ' . implode( '; ', array_slice( $errors, 0, 3 ) ) );
        return $this->record( true, $checked, $deleted, $updated, $skipped, $message, $reason );
    }

    /**
     * 处理 friend_relation_removed 事件：按对端 URL 删除本地友链（自清理）。
     *
     * 100% 对齐 Halo 端 HubRealtimeBridge.handleFriendRelationRemoved +
     * resolveLocalLinkPeerUrlForSelfCleanup：用本站凭据 siteId 判定本站是 actor 还是
     * peer，取对端 URL，再按 URL（大小写不敏感）删本地友链。幂等。
     *
     * @param array $event friend_relation_removed 的 data（actorSiteId/actorSiteUrl/peerSiteId/peerSiteUrl）。
     * @return array{success:bool,action:string,message:string}
     */
    public function handle_relation_removed( array $event ) {
        if ( ! $this->credentials->is_registered() ) {
            return array( 'success' => false, 'action' => 'skipped', 'message' => 'not registered yet' );
        }
        $current_site_id = trim( (string) $this->credentials->get_credentials()['siteId'] );
        $peer_url        = $this->resolve_self_cleanup_peer_url( $event, $current_site_id );
        if ( '' === $peer_url ) {
            // 本站既非 actor 也非 peer，不处理（与 Halo 一致）。
            return array( 'success' => true, 'action' => 'skipped', 'message' => 'not actor or peer' );
        }
        $result = $this->reconcile->delete_by_peer_url( $peer_url );
        return array(
            'success' => (bool) $result['success'],
            'action'  => ( isset( $result['deleted'] ) && $result['deleted'] > 0 ) ? 'deleted' : 'skipped',
            'message' => (string) $result['message'],
        );
    }

    /**
     * 处理 site_profile_updated 事件：按对端 siteId 更新本地友链展示字段。
     *
     * 100% 对齐 Halo 端 HubRealtimeBridge.handleSiteProfileUpdated +
     * updateLocalLinkByPeerSiteId。幂等。
     *
     * @param array $event site_profile_updated 的 data（siteId/name/url/description/nodeAvatar/rssUrl）。
     * @return array{success:bool,action:string,message:string}
     */
    public function handle_profile_updated( array $event ) {
        if ( ! $this->credentials->is_registered() ) {
            return array( 'success' => false, 'action' => 'skipped', 'message' => 'not registered yet' );
        }
        $site_id = trim( (string) ( $event['siteId'] ?? '' ) );
        if ( '' === $site_id ) {
            return array( 'success' => true, 'action' => 'skipped', 'message' => 'siteId is required' );
        }
        // 与 Halo updateLinkByNameWithRetry 同口径：用对端权威资料覆写本地展示字段。
        $fields = array(
            'name'        => (string) ( $event['name'] ?? '' ),
            'url'         => (string) ( $event['url'] ?? '' ),
            'description' => (string) ( $event['description'] ?? '' ),
            'image'       => (string) ( $event['nodeAvatar'] ?? '' ),
            'rss'         => (string) ( $event['rssUrl'] ?? '' ),
        );
        $result = $this->reconcile->update_by_peer_site_id( $site_id, $fields );
        return array(
            'success' => (bool) $result['success'],
            'action'  => ( isset( $result['updated'] ) && $result['updated'] > 0 ) ? 'updated' : 'skipped',
            'message' => (string) $result['message'],
        );
    }

    /**
     * 解析自清理的对端 URL（对齐 Halo resolveLocalLinkPeerUrlForSelfCleanup）。
     *
     * 本站是 actor → 删 peer 的 URL；本站是 peer → 删 actor 的 URL；都不是 → 空。
     *
     * @param array  $event           事件 data。
     * @param string $current_site_id 本站 siteId。
     * @return string
     */
    private function resolve_self_cleanup_peer_url( array $event, $current_site_id ) {
        $current = trim( (string) $current_site_id );
        if ( '' === $current ) {
            return '';
        }
        $actor_site_id = trim( (string) ( $event['actorSiteId'] ?? '' ) );
        $peer_site_id  = trim( (string) ( $event['peerSiteId'] ?? '' ) );
        if ( $current === $actor_site_id ) {
            return trim( (string) ( $event['peerSiteUrl'] ?? '' ) );
        }
        if ( $current === $peer_site_id ) {
            return trim( (string) ( $event['actorSiteUrl'] ?? '' ) );
        }
        return '';
    }

    /**
     * 针对单条关系解析结果决定动作：删除 / 更新 / 跳过。
     *
     * @param array $item   Hub 返回的单条 SiteRelationResult。
     * @param array $by_url 规范化 URL → 本地链接 索引。
     * @return string deleted|updated|skipped
     */
    private function apply_relation_result( array $item, array $by_url ) {
        $target_url = $this->normalize_url( (string) ( $item['targetUrl'] ?? '' ) );
        // targetUrl 是 Hub 规范化后的，可能与本地原始 URL 规范化不完全一致，做两级匹配。
        $link = $by_url[ $target_url ] ?? null;
        if ( null === $link ) {
            // 用 targetIdentity.siteUrl / normalizedUrl 再试一次。
            $identity = isset( $item['targetIdentity'] ) && is_array( $item['targetIdentity'] ) ? $item['targetIdentity'] : array();
            foreach ( array( $identity['siteUrl'] ?? '', $identity['normalizedUrl'] ?? '', $identity['queryUrl'] ?? '' ) as $candidate ) {
                $norm = $this->normalize_url( (string) $candidate );
                if ( '' !== $norm && isset( $by_url[ $norm ] ) ) {
                    $link = $by_url[ $norm ];
                    break;
                }
            }
        }
        if ( null === $link ) {
            return 'skipped';
        }

        $kind       = strtolower( trim( (string) ( $item['relationKind'] ?? '' ) ) );
        $identity   = isset( $item['targetIdentity'] ) && is_array( $item['targetIdentity'] ) ? $item['targetIdentity'] : array();
        $registered = ! empty( $identity['registered'] );

        // 关系已不存在（双方未连或仅对方连着）→ 删本地友链。
        // 仅在「对端已注册且解析明确」时删，unknown/未注册一律保留，杜绝误删。
        if ( $registered && in_array( $kind, array( 'none', 'one_way_in' ), true ) ) {
            return $this->reconcile->delete_by_link_id( (int) $link['linkId'] ) ? 'deleted' : 'skipped';
        }

        // 关系仍在（mutual / one_way_out）→ 用对端最新资料刷新本地展示字段。
        if ( in_array( $kind, array( 'mutual', 'one_way_out' ), true ) ) {
            $fields = $this->build_profile_fields( $identity );
            if ( ! empty( $fields ) && $this->reconcile->update_local_link( (int) $link['linkId'], $fields ) ) {
                return 'updated';
            }
            return 'skipped';
        }

        // unknown / self / 未注册：不确定，保留。
        return 'skipped';
    }

    /**
     * 从对端 identity 抽取可用于刷新本地友链的展示字段（空值不覆盖）。
     *
     * @param array $identity targetIdentity。
     * @return array
     */
    private function build_profile_fields( array $identity ) {
        $fields = array();
        $name   = trim( (string) ( $identity['siteName'] ?? '' ) );
        $url    = trim( (string) ( $identity['siteUrl'] ?? '' ) );
        $desc   = (string) ( $identity['description'] ?? '' );
        $rss    = trim( (string) ( $identity['rssUrl'] ?? '' ) );
        $avatar = trim( (string) ( $identity['nodeAvatar'] ?? ( $identity['avatarUrl'] ?? '' ) ) );

        if ( '' !== $name ) {
            $fields['name'] = $name;
        }
        if ( '' !== $url ) {
            $fields['url'] = $url;
        }
        // description 允许为空字符串（对端清空简介也同步），但仅当 key 存在时。
        if ( array_key_exists( 'description', $identity ) ) {
            $fields['description'] = $desc;
        }
        if ( '' !== $rss ) {
            $fields['rss'] = $rss;
        }
        if ( '' !== $avatar ) {
            $fields['image'] = $avatar;
        }
        return $fields;
    }

    /**
     * 读取上次对账状态。
     *
     * @return array
     */
    public function get_status() {
        $stored = get_option( self::OPTION_LAST, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return wp_parse_args(
            $stored,
            array(
                'success'  => false,
                'checked'  => 0,
                'deleted'  => 0,
                'updated'  => 0,
                'skipped'  => 0,
                'message'  => '',
                'trigger'  => '',
                'syncedAt' => '',
            )
        );
    }

    /**
     * 规范化 URL：去协议大小写差异、去尾斜杠、转小写。仅用于本地比对，不用于签名。
     *
     * @param string $raw 原始 URL。
     * @return string
     */
    private function normalize_url( $raw ) {
        $value = strtolower( trim( (string) $raw ) );
        if ( '' === $value ) {
            return '';
        }
        return rtrim( $value, '/' );
    }

    /**
     * 记录对账结果到 option 并返回结构。
     *
     * @param bool   $success 是否成功。
     * @param int    $checked 检查条数。
     * @param int    $deleted 删除条数。
     * @param int    $updated 更新条数。
     * @param int    $skipped 跳过条数。
     * @param string $message 信息。
     * @param string $reason  触发原因。
     * @return array
     */
    private function record( $success, $checked, $deleted, $updated, $skipped, $message, $reason ) {
        $now    = current_time( 'mysql', true );
        $status = array(
            'success'  => (bool) $success,
            'checked'  => (int) $checked,
            'deleted'  => (int) $deleted,
            'updated'  => (int) $updated,
            'skipped'  => (int) $skipped,
            'message'  => (string) $message,
            'trigger'  => (string) $reason,
            'syncedAt' => $now,
        );
        update_option( self::OPTION_LAST, $status, false );
        return $status;
    }
}
