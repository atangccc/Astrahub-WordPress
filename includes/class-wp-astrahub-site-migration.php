<?php
/**
 * 站点友链搬家服务（对齐 Halo AstraHubSiteMigrationService）。
 *
 * 从 Hub 签名 GET /v1/site-migration/snapshot 拉取权威友链快照，
 * 校验版本号、siteId 和 checksum（SHA-256）后，用快照完全替换本地
 * wp_links / link_category 中的 AstraHub 友链数据。
 *
 * 快照格式：
 *   { version: "bp.site-migration.v1", siteId, snapshotAt,
 *     groups: [{ externalId, name, priority }],
 *     links:  [{ externalId, title, url, description, logo, rssUrl,
 *               priority, groupExternalIds: [...], peerSiteId }],
 *     checksum }
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Site_Migration {

    const SNAPSHOT_PATH   = '/v1/site-migration/snapshot';
    const SNAPSHOT_VERSION = 'bp.site-migration.v1';
    const NOTE_GROUP_PREFIX = 'astrahub:group-external-id=';

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
     * 本地友链对账。
     *
     * @var WP_AstraHub_Link_Reconcile
     */
    private $reconcile;

    /**
     * 构造。
     *
     * @param WP_AstraHub_Hub_Client       $hub_client  Hub 客户端。
     * @param WP_AstraHub_Credential_Store $credentials 凭据存储。
     * @param WP_AstraHub_Link_Reconcile   $reconcile   本地友链对账。
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
     * 执行搬家：拉快照 → 校验 → 替换本地数据。
     *
     * @return array{success:bool,status:int,message:string,data:array}
     */
    public function migrate() {
        $creds = $this->credentials->get_credentials();
        $site_id = trim( (string) ( $creds['siteId'] ?? '' ) );
        $api_key = trim( (string) ( $creds['apiKey'] ?? '' ) );
        if ( '' === $site_id || '' === $api_key ) {
            return $this->fail( 401, '请先完成登舱' );
        }

        $response = $this->hub_client->request_signed( 'GET', self::SNAPSHOT_PATH );
        if ( ! $response['success'] ) {
            return $this->fail( $response['status'], $response['message'] );
        }

        $body     = $response['body'];
        $snapshot = isset( $body['snapshot'] ) && is_array( $body['snapshot'] )
            ? $body['snapshot'] : array();

        $validation = $this->validate_snapshot( $snapshot, $site_id );
        if ( ! $validation['success'] ) {
            return $this->fail( 400, $validation['message'] );
        }

        $counts = $this->replace_local_data( $snapshot );

        return $this->ok(
            200,
            '友链搬家完成',
            array(
                'snapshotAt'      => isset( $snapshot['snapshotAt'] ) ? (string) $snapshot['snapshotAt'] : '',
                'deletedLinks'    => $counts['deletedLinks'],
                'deletedGroups'   => $counts['deletedGroups'],
                'restoredLinks'   => $counts['restoredLinks'],
                'restoredGroups'  => $counts['restoredGroups'],
            )
        );
    }

    /**
     * 校验快照：版本号、siteId、checksum（对齐 Halo verifyChecksum）。
     *
     * @param array  $snapshot 快照体。
     * @param string $site_id  当前站点 siteId。
     * @return array{success:bool,message:string}
     */
    private function validate_snapshot( array $snapshot, $site_id ) {
        if ( empty( $snapshot ) ) {
            return array( 'success' => false, 'message' => '快照体为空' );
        }
        $version = trim( (string) ( $snapshot['version'] ?? '' ) );
        if ( self::SNAPSHOT_VERSION !== $version ) {
            return array( 'success' => false, 'message' => '服务端返回了不支持的搬家快照版本: ' . $version );
        }
        if ( $site_id !== trim( (string) ( $snapshot['siteId'] ?? '' ) ) ) {
            return array( 'success' => false, 'message' => '搬家快照与当前绑定站点不一致' );
        }
        $checksum = trim( (string) ( $snapshot['checksum'] ?? '' ) );
        if ( '' === $checksum ) {
            return array( 'success' => false, 'message' => '服务端搬家快照缺少校验值' );
        }

        // 校验 groups/links 的 externalId 唯一且非空。
        $group_ids = array();
        foreach ( (array) ( $snapshot['groups'] ?? array() ) as $group ) {
            $gid = trim( (string) ( $group['externalId'] ?? '' ) );
            if ( '' === $gid || isset( $group_ids[ $gid ] ) ) {
                return array( 'success' => false, 'message' => '服务端搬家快照包含无效分组' );
            }
            $group_ids[ $gid ] = true;
        }
        $link_ids = array();
        foreach ( (array) ( $snapshot['links'] ?? array() ) as $link ) {
            $lid = trim( (string) ( $link['externalId'] ?? '' ) );
            $url = trim( (string) ( $link['url'] ?? '' ) );
            if ( '' === $lid || '' === $url || isset( $link_ids[ $lid ] ) ) {
                return array( 'success' => false, 'message' => '服务端搬家快照包含无效友链' );
            }
            $link_ids[ $lid ] = true;
        }

        // checksum 校验（对齐 Halo canonical JSON + SHA-256）。
        if ( ! $this->verify_checksum( $snapshot, $checksum ) ) {
            return array( 'success' => false, 'message' => '服务端搬家快照校验失败' );
        }

        return array( 'success' => true, 'message' => '' );
    }

    /**
     * 计算 canonical JSON 的 SHA-256 并与 Hub 返回的 checksum 比对。
     *
     * @param array  $snapshot 快照。
     * @param string $expected  期望的 hex checksum。
     * @return bool
     */
    private function verify_checksum( array $snapshot, $expected ) {
        $canonical = wp_json_encode(
            array(
                'groups' => isset( $snapshot['groups'] ) ? $snapshot['groups'] : array(),
                'links'  => isset( $snapshot['links'] ) ? $snapshot['links'] : array(),
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ( false === $canonical ) {
            return false;
        }
        $actual = hash( 'sha256', $canonical, false );
        return hash_equals( strtolower( trim( $expected ) ), $actual );
    }

    /**
     * 替换本地数据：先删后建。
     *
     * @param array $snapshot 快照。
     * @return array{deletedLinks:int,deletedGroups:int,restoredLinks:int,restoredGroups:int}
     */
    private function replace_local_data( array $snapshot ) {
        $groups = isset( $snapshot['groups'] ) && is_array( $snapshot['groups'] ) ? $snapshot['groups'] : array();
        $links  = isset( $snapshot['links'] ) && is_array( $snapshot['links'] ) ? $snapshot['links'] : array();

        // 1. 先删本插件管理的所有友链和分组。
        $deleted_links  = 0;
        $deleted_groups = 0;
        foreach ( $this->reconcile->list_managed_links() as $managed ) {
            if ( $this->reconcile->delete_by_link_id( $managed['linkId'] ) ) {
                $deleted_links++;
            }
        }

        // 2. 重建分组：externalId → term_id。
        $group_map = array();
        foreach ( $groups as $group ) {
            $ext_id = trim( (string) ( $group['externalId'] ?? '' ) );
            $name   = trim( (string) ( $group['name'] ?? '' ) );
            if ( '' === $ext_id ) {
                continue;
            }
            if ( '' === $name ) {
                $name = $ext_id;
            }
            $term = get_term_by( 'name', $name, 'link_category' );
            if ( $term && ! is_wp_error( $term ) ) {
                $term_id = (int) $term->term_id;
                wp_update_term( $term_id, 'link_category', array( 'name' => $name ) );
            } else {
                $created = wp_insert_term( $name, 'link_category' );
                $term_id = ! is_wp_error( $created ) ? (int) ( $created['term_id'] ?? 0 ) : 0;
            }
            if ( $term_id > 0 ) {
                $group_map[ $ext_id ] = $term_id;
            }
        }

        // 3. 重建友链。
        $restored_links = 0;
        foreach ( $links as $link ) {
            $result = $this->build_and_insert_link( $link, $group_map );
            if ( $result ) {
                $restored_links++;
            }
        }

        return array(
            'deletedLinks'   => $deleted_links,
            'deletedGroups'  => $deleted_groups,
            'restoredLinks'  => $restored_links,
            'restoredGroups' => count( $group_map ),
        );
    }

    /**
     * 按快照里一条 link 数据构造 wp_links 记录并插入。
     *
     * @param array $link      快照 link 条目。
     * @param array $group_map externalId → term_id。
     * @return bool 是否成功。
     */
    private function build_and_insert_link( array $link, array $group_map ) {
        $url   = esc_url_raw( trim( (string) ( $link['url'] ?? '' ) ) );
        $title = sanitize_text_field( trim( (string) ( $link['title'] ?? '' ) ) );
        if ( '' === $url ) {
            return false;
        }

        // 取第一个合法分组。
        $categories = array();
        foreach ( (array) ( $link['groupExternalIds'] ?? array() ) as $gid ) {
            $gid = trim( (string) $gid );
            if ( isset( $group_map[ $gid ] ) && $group_map[ $gid ] > 0 ) {
                $categories = array( $group_map[ $gid ] );
                break;
            }
        }

        $peer_site_id = sanitize_text_field( trim( (string) ( $link['peerSiteId'] ?? '' ) ) );

        $linkdata = array(
            'link_url'         => $url,
            'link_name'        => '' !== $title ? $title : $url,
            'link_description' => sanitize_text_field( trim( (string) ( $link['description'] ?? '' ) ) ),
            'link_image'       => esc_url_raw( trim( (string) ( $link['logo'] ?? '' ) ) ),
            'link_rss'         => esc_url_raw( trim( (string) ( $link['rssUrl'] ?? '' ) ) ),
            'link_visible'     => 'Y',
            'link_notes'       => WP_AstraHub_Link_Reconcile::NOTE_PEER_PREFIX . $peer_site_id,
            'link_category'    => $categories,
        );

        require_once ABSPATH . 'wp-admin/includes/bookmark.php';
        $result = wp_insert_link( $linkdata, true );
        return ! is_wp_error( $result ) && (bool) $result;
    }

    /**
     * 成功结果。
     *
     * @param int    $status  HTTP 状态。
     * @param string $message 信息。
     * @param array  $data    附加数据。
     * @return array
     */
    private function ok( $status, $message, array $data = array() ) {
        return array( 'success' => true, 'status' => $status, 'message' => $message, 'data' => $data );
    }

    /**
     * 失败结果。
     *
     * @param int    $status  HTTP 状态。
     * @param string $message 信息。
     * @return array
     */
    private function fail( $status, $message ) {
        return array( 'success' => false, 'status' => $status, 'message' => $message, 'data' => array() );
    }
}
