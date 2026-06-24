<?php
/**
 * 本地友链对账（reconcile）。
 *
 * 友链邀请通过后，把对端站点写入 WordPress 内置 Links Manager（wp_links），
 * 对应 Halo 端 AstraHubFriendLinkReconcileService 写 Link CR 的职责。
 *
 * 由于 wp_links 没有任意 annotation，使用 link_notes 存一行标记：
 *   astrahub:peer-site-id=<siteId>
 * 用于后续按对端 siteId 反查、更新、删除，幂等保证不重复建链。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Link_Reconcile {

    const NOTE_PEER_PREFIX = 'astrahub:peer-site-id=';

    /**
     * 确保 Links Manager 可用（WP 3.5+ 默认隐藏，需开启 link_manager_enabled）。
     */
    private function ensure_links_manager() {
        if ( ! get_option( 'link_manager_enabled' ) ) {
            update_option( 'link_manager_enabled', 1 );
        }
        require_once ABSPATH . 'wp-admin/includes/bookmark.php';
    }

    /**
     * 邀请通过后，把对端写进本地友链（幂等：URL 已存在则跳过）。
     *
     * @param array  $peer            对端站点信息（siteId/siteName/siteUrl/description/avatarUrl/rssUrl）。
     * @param string $link_group_name 友链分组名（可选，命中本地分类才用，否则未分组）。
     * @return array{success:bool,created:bool,duplicate:bool,message:string}
     */
    public function reconcile_peer( array $peer, $link_group_name = '' ) {
        $this->ensure_links_manager();

        $peer_url  = $this->trim( $peer['siteUrl'] ?? '' );
        $peer_id   = $this->trim( $peer['siteId'] ?? '' );
        $peer_name = $this->trim( $peer['siteName'] ?? '' );
        if ( '' === $peer_url ) {
            return array( 'success' => false, 'created' => false, 'duplicate' => false, 'message' => 'peer site url is missing' );
        }

        // 去重：按 URL 比对现有友链。
        foreach ( $this->all_bookmarks() as $b ) {
            $bookmark_peer_id = $this->extract_peer_site_id( (string) ( $b->link_notes ?? '' ) );
            if ( '' !== $peer_id && $bookmark_peer_id === $peer_id ) {
                return array( 'success' => true, 'created' => false, 'duplicate' => true, 'message' => 'duplicate' );
            }
            if ( $this->same_site_url( $this->trim( $b->link_url ), $peer_url ) ) {
                return array( 'success' => true, 'created' => false, 'duplicate' => true, 'message' => 'duplicate' );
            }
        }

        $category_id = $this->resolve_category_id( $link_group_name );

        $linkdata = array(
            'link_url'         => esc_url_raw( $peer_url ),
            'link_name'        => sanitize_text_field( $peer_name !== '' ? $peer_name : $peer_url ),
            'link_description' => sanitize_text_field( $this->trim( $peer['description'] ?? '' ) ),
            'link_image'       => esc_url_raw( $this->trim( $peer['avatarUrl'] ?? '' ) ),
            'link_rss'         => esc_url_raw( $this->trim( $peer['rssUrl'] ?? '' ) ),
            'link_visible'     => 'Y',
            'link_notes'       => self::NOTE_PEER_PREFIX . sanitize_text_field( $peer_id ),
            'link_category'    => $category_id > 0 ? array( $category_id ) : array(),
        );

        $result = wp_insert_link( $linkdata, true );
        if ( is_wp_error( $result ) || ! $result ) {
            $msg = is_wp_error( $result ) ? $result->get_error_message() : 'wp_insert_link failed';
            return array( 'success' => false, 'created' => false, 'duplicate' => false, 'message' => $msg );
        }

        return array( 'success' => true, 'created' => true, 'duplicate' => false, 'message' => 'created' );
    }

    /**
     * 解除友链关系后，按对端 URL 删除本地友链（幂等）。
     *
     * @param string $peer_url 对端 URL。
     * @return array{success:bool,deleted:int,message:string}
     */
    public function delete_by_peer_url( $peer_url, $peer_site_id = '' ) {
        $this->ensure_links_manager();
        $peer_url     = $this->trim( $peer_url );
        $peer_site_id = $this->trim( $peer_site_id );
        if ( '' === $peer_url && '' === $peer_site_id ) {
            return array( 'success' => false, 'deleted' => 0, 'message' => 'peerUrl or peerSiteId is required' );
        }
        $deleted = 0;
        foreach ( $this->all_bookmarks() as $b ) {
            $bookmark_peer_id = $this->extract_peer_site_id( (string) ( $b->link_notes ?? '' ) );
            $matches_peer_id  = '' !== $peer_site_id && $bookmark_peer_id === $peer_site_id;
            $matches_url      = '' !== $peer_url && $this->same_site_url( $this->trim( $b->link_url ), $peer_url );
            if ( $matches_peer_id || $matches_url ) {
                if ( wp_delete_link( (int) $b->link_id ) ) {
                    $deleted++;
                }
            }
        }
        return array( 'success' => true, 'deleted' => $deleted, 'message' => $deleted > 0 ? 'deleted' : 'not_found' );
    }

    /**
     * 按对端 siteId（link_notes 的 astrahub:peer-site-id= 标记）更新本地友链展示字段。
     *
     * 对齐 Halo 端 AstraHubFriendLinkReconcileService.updateLocalLinkByPeerSiteId：
     * site_profile_updated 事件到达后，把本地指向该对端的所有友链同步成最新本体。
     *
     * @param string $peer_site_id 对端 siteId。
     * @param array  $fields       待更新字段（name/url/image/rss/description）。
     * @return array{success:bool,updated:int,message:string}
     */
    public function update_by_peer_site_id( $peer_site_id, array $fields ) {
        $this->ensure_links_manager();
        $sid = $this->trim( $peer_site_id );
        if ( '' === $sid ) {
            return array( 'success' => false, 'updated' => 0, 'message' => 'peerSiteId is required' );
        }
        $updated = 0;
        foreach ( $this->all_bookmarks() as $b ) {
            if ( $this->extract_peer_site_id( (string) ( $b->link_notes ?? '' ) ) !== $sid ) {
                continue;
            }
            if ( $this->update_local_link( (int) $b->link_id, $fields ) ) {
                $updated++;
            }
        }
        return array(
            'success' => true,
            'updated' => $updated,
            'message' => $updated > 0 ? 'updated' : 'not_found',
        );
    }

    /**
     * 取全部友链（含隐藏）。
     *
     * @return array
     */
    private function all_bookmarks() {
        return get_bookmarks( array( 'hide_invisible' => 0, 'orderby' => 'id' ) );
    }

    /**
     * 列出所有由本插件管理的友链（link_notes 带 astrahub:peer-site-id= 标记）。
     *
     * 用于 cron 反向对账：把这些链接的当前状态与 Hub 对齐。
     *
     * @return array<int,array{linkId:int,url:string,peerSiteId:string,name:string,image:string,rss:string,description:string}>
     */
    public function list_managed_links() {
        $this->ensure_links_manager();
        $managed = array();
        foreach ( $this->all_bookmarks() as $b ) {
            $notes = (string) ( $b->link_notes ?? '' );
            $peer_id = $this->extract_peer_site_id( $notes );
            // 只认带标记的链接；标记里 peerSiteId 可能为空（旧数据），仍按 URL 对账。
            if ( null === $peer_id ) {
                continue;
            }
            $managed[] = array(
                'linkId'      => (int) $b->link_id,
                'url'         => $this->trim( $b->link_url ),
                'peerSiteId'  => $peer_id,
                'name'        => $this->trim( $b->link_name ),
                'image'       => $this->trim( $b->link_image ),
                'rss'         => $this->trim( $b->link_rss ),
                'description' => $this->trim( $b->link_description ),
            );
        }
        return $managed;
    }

    /**
     * 按 link_id 删除本地友链。
     *
     * @param int $link_id 链接 ID。
     * @return bool
     */
    public function delete_by_link_id( $link_id ) {
        $this->ensure_links_manager();
        $id = (int) $link_id;
        if ( $id <= 0 ) {
            return false;
        }
        return (bool) wp_delete_link( $id );
    }

    /**
     * 按 link_id 更新本地友链的展示字段（仅当有变化时写库）。
     *
     * @param int   $link_id 链接 ID。
     * @param array $fields  待更新字段（name/url/image/rss/description）。
     * @return bool 是否实际写库。
     */
    public function update_local_link( $link_id, array $fields ) {
        $this->ensure_links_manager();
        $id = (int) $link_id;
        if ( $id <= 0 ) {
            return false;
        }
        $bookmark = get_bookmark( $id );
        if ( ! $bookmark ) {
            return false;
        }

        $linkdata = array( 'link_id' => $id );
        $changed  = false;

        $map = array(
            'name'        => 'link_name',
            'url'         => 'link_url',
            'image'       => 'link_image',
            'rss'         => 'link_rss',
            'description' => 'link_description',
        );
        foreach ( $map as $key => $column ) {
            if ( ! array_key_exists( $key, $fields ) ) {
                continue;
            }
            $next = $this->trim( $fields[ $key ] );
            // name/url 不允许被清空（避免对端字段缺失时把链接弄坏）。
            if ( '' === $next && in_array( $key, array( 'name', 'url' ), true ) ) {
                continue;
            }
            // 防御性净化：URL 类字段用 esc_url_raw，文本类用 sanitize_text_field。
            $next = in_array( $key, array( 'url', 'image', 'rss' ), true )
                ? esc_url_raw( $next )
                : sanitize_text_field( $next );
            $current = $this->trim( $bookmark->{$column} ?? '' );
            if ( $current !== $next ) {
                $linkdata[ $column ] = $next;
                $changed = true;
            }
        }

        if ( ! $changed ) {
            return false;
        }
        $result = wp_update_link( $linkdata );
        return ! is_wp_error( $result ) && (bool) $result;
    }

    /**
     * 从 link_notes 解析对端 siteId（带 astrahub:peer-site-id= 标记返回该值，否则 null）。
     *
     * @param string $notes link_notes 原文。
     * @return string|null
     */
    private function extract_peer_site_id( $notes ) {
        $text = (string) $notes;
        $pos  = strpos( $text, self::NOTE_PEER_PREFIX );
        if ( false === $pos ) {
            return null;
        }
        $rest = substr( $text, $pos + strlen( self::NOTE_PEER_PREFIX ) );
        // 标记值取到行尾。
        $line = preg_split( '/[\r\n]/', $rest );
        return $this->trim( is_array( $line ) ? ( $line[0] ?? '' ) : $rest );
    }

    /**
     * 解析分组名到 link_category term id；不存在则返回 0（未分组）。
     *
     * @param string $group_name 分组名。
     * @return int
     */
    private function resolve_category_id( $group_name ) {
        $name = $this->trim( $group_name );
        if ( '' === $name ) {
            return 0;
        }
        $term = get_term_by( 'name', $name, 'link_category' );
        if ( $term && ! is_wp_error( $term ) ) {
            return (int) $term->term_id;
        }
        return 0;
    }

    /**
     * 比对站点 URL，兼容尾斜杠、大小写和主页路径差异。
     *
     * @param string $left  URL。
     * @param string $right URL。
     * @return bool
     */
    private function same_site_url( $left, $right ) {
        $left_url  = $this->normalize_url( $left, false );
        $right_url = $this->normalize_url( $right, false );
        if ( '' !== $left_url && '' !== $right_url && $left_url === $right_url ) {
            return true;
        }
        $left_root  = $this->normalize_url( $left, true );
        $right_root = $this->normalize_url( $right, true );
        return '' !== $left_root && '' !== $right_root && $left_root === $right_root;
    }

    /**
     * URL 规范化，用于本地友链去重和删除。
     *
     * @param string $url       URL。
     * @param bool   $root_only 是否只保留站点根地址。
     * @return string
     */
    private function normalize_url( $url, $root_only = false ) {
        $value = $this->trim( $url );
        if ( '' === $value ) {
            return '';
        }
        if ( ! preg_match( '#^https?://#i', $value ) ) {
            $value = 'https://' . $value;
        }
        $parts = wp_parse_url( $value );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return strtolower( rtrim( $value, "/ \t\n\r\0\x0B" ) );
        }
        $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
        $host   = strtolower( $parts['host'] );
        $port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        if ( $root_only ) {
            return $scheme . '://' . $host . $port;
        }
        $path = isset( $parts['path'] ) ? '/' . ltrim( $parts['path'], '/' ) : '';
        $path = rtrim( $path, '/' );
        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * trim 辅助。
     *
     * @param mixed $v 值。
     * @return string
     */
    private function trim( $v ) {
        return is_scalar( $v ) ? trim( (string) $v ) : '';
    }
}
