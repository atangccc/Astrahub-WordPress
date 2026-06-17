<?php
/**
 * bp.site-links.v1 友链快照采集与构造。
 *
 * 与 Typecho 端 AstraHub_PushService 完全对齐：从 WordPress 的 Links Manager
 * （wp_links）采集友链，原样导出每条「外站」友链（名称/url/头像/描述/rss），构造
 * 与服务端 /v1/site-link-edges/push 一致的扁平 edges payload。
 *
 * 只上报友链关系本身，不上报文章内容，也不上报「自身站点节点」（self-link）：
 * 站点本体的星球/星系是服务端在列友链时按 sites 本体动态合成的（接入即可见），
 * 插件再推一条指向自己的边只会产生重复脏数据。
 *
 * 唯一剔除：targetUrl 与本站同源（同 host）的自链边——一个站点把自己当自己的友链
 * 是无意义脏数据，上报后会在 Hub 友链星球/关系图里渲染成指向自己的卡片/节点。
 *
 * 服务端期望的 JSON 结构（对齐 Halo AstraHubLinkEdgeExportService）：
 *   {
 *     version: 'bp.site-links.v1',
 *     snapshotAt,
 *     source { platform, plugin, pluginVersion, siteId, siteName, siteUrl },
 *     edges[{ targetUrl, targetSiteId, title, description, logo, rssUrl,
 *             isActive, firstSeenAt, lastSeenAt, updatedAt }]
 *   }
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Graph_Collector {

    const EDGES_VERSION = 'bp.site-links.v1';
    const PLUGIN_NAME   = 'plugin-wp-astrahub';

    /**
     * 凭据存储。
     *
     * @var WP_AstraHub_Credential_Store
     */
    private $credentials;

    /**
     * 构造。
     *
     * @param WP_AstraHub_Credential_Store $credentials 凭据存储。
     */
    public function __construct( WP_AstraHub_Credential_Store $credentials ) {
        $this->credentials = $credentials;
    }

    /**
     * 构造 bp.site-links.v1 payload。
     *
     * @param string $sync_reason 同步原因（保留参数，edges 协议体不携带）。
     * @return array
     */
    public function build_payload( $sync_reason = '' ) {
        $conn  = $this->credentials->get_connection();
        $creds = $this->credentials->get_credentials();
        $snapshot_at = $this->now_iso();

        $site_url  = $this->normalize_site_root( $conn['siteUrl'] ?: home_url() );
        $self_host = $this->host_of( $site_url );

        $edges = array();
        foreach ( $this->collect_links() as $link ) {
            $target_url = $this->normalize_url( $link['url'], $site_url );
            if ( '' === $target_url ) {
                continue;
            }
            // 跳过指向本站自己的自链边（同 host 即视为自链）。
            if ( '' !== $self_host && $this->host_of( $target_url ) === $self_host ) {
                continue;
            }
            $edges[] = array(
                'targetUrl'    => $target_url,
                'targetSiteId' => '',
                'title'        => $this->sanitize_email( $link['title'] ),
                'description'  => $this->sanitize_email( $link['description'] ),
                'logo'         => $this->normalize_url( $link['logo'], $target_url ),
                'rssUrl'       => trim( (string) $link['rssUrl'] ),
                'isActive'     => $link['isActive'] ? true : false,
                'firstSeenAt'  => $snapshot_at,
                'lastSeenAt'   => $snapshot_at,
                'updatedAt'    => $snapshot_at,
            );
        }

        $source = array(
            'platform'      => 'wordpress',
            'plugin'        => self::PLUGIN_NAME,
            'pluginVersion' => WP_ASTRAHUB_VERSION,
            'siteId'        => $creds['siteId'],
            'siteName'      => $conn['siteName'] ?: get_bloginfo( 'name' ),
            'siteUrl'       => $site_url,
        );

        return array(
            'version'    => self::EDGES_VERSION,
            'snapshotAt' => $snapshot_at,
            'source'     => $source,
            'edges'      => $edges,
        );
    }

    /**
     * 采集友链（wp_links / Links Manager）。
     *
     * @return array<int,array>
     */
    private function collect_links() {
        $result    = array();
        $bookmarks = get_bookmarks( array( 'hide_invisible' => 0, 'orderby' => 'id' ) );
        foreach ( $bookmarks as $b ) {
            $result[] = array(
                'externalId'  => (string) $b->link_id,
                'url'         => $b->link_url,
                'title'       => $b->link_name,
                'description' => $b->link_description,
                'logo'        => $b->link_image,
                'rssUrl'      => $b->link_rss,
                'isActive'    => ( isset( $b->link_visible ) && 'N' === $b->link_visible ) ? false : true,
            );
        }
        return $result;
    }

    /** 取 URL 的小写 host（用于自链判定）；无法解析时返回空串。 */
    private function host_of( $url ) {
        $value = trim( (string) $url );
        if ( '' === $value ) {
            return '';
        }
        if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $value ) ) {
            $value = 'http://' . $value;
        }
        $parts = wp_parse_url( $value );
        return ! empty( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
    }

    /** URL 规范化（解析相对、去尾斜杠、去追踪参数）。 */
    private function normalize_url( $raw, $base_url ) {
        $value = trim( (string) $raw );
        if ( '' === $value || 0 === strpos( $value, '#' ) || 0 === strpos( $value, 'mailto:' )
            || 0 === strpos( $value, 'tel:' ) || 0 === strpos( $value, 'javascript:' ) ) {
            return '';
        }
        // 相对路径基于 base 解析。
        if ( ! preg_match( '#^https?://#i', $value ) ) {
            $base = trim( (string) $base_url );
            if ( '' === $base ) {
                return '';
            }
            $value = $this->resolve_relative( $base, $value );
        }
        $parts = wp_parse_url( $value );
        if ( empty( $parts['host'] ) ) {
            return '';
        }
        $scheme = strtolower( $parts['scheme'] ?? 'https' );
        $host   = strtolower( $parts['host'] );
        $port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
        $use_port = $port > 0 && ! ( 'http' === $scheme && 80 === $port ) && ! ( 'https' === $scheme && 443 === $port );
        $path = $parts['path'] ?? '/';
        if ( strlen( $path ) > 1 && '/' === substr( $path, -1 ) ) {
            $path = substr( $path, 0, -1 );
        }
        if ( '' === $path ) {
            $path = '/';
        }
        $query = $this->normalize_query( $parts['query'] ?? '' );
        return $scheme . '://' . $host . ( $use_port ? ':' . $port : '' ) . $path . ( '' === $query ? '' : '?' . $query );
    }

    /** 站点根（scheme://host[:port]）。 */
    private function normalize_site_root( $raw ) {
        $normalized = $this->normalize_url( $raw, '' );
        if ( '' === $normalized ) {
            return '';
        }
        $parts = wp_parse_url( $normalized );
        if ( empty( $parts['host'] ) ) {
            return '';
        }
        $scheme = strtolower( $parts['scheme'] ?? 'https' );
        $host   = strtolower( $parts['host'] );
        $port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
        $use_port = $port > 0 && ! ( 'http' === $scheme && 80 === $port ) && ! ( 'https' === $scheme && 443 === $port );
        return $scheme . '://' . $host . ( $use_port ? ':' . $port : '' );
    }

    /** 简单相对路径解析。 */
    private function resolve_relative( $base, $rel ) {
        $bp = wp_parse_url( $base );
        if ( empty( $bp['scheme'] ) || empty( $bp['host'] ) ) {
            return '';
        }
        $root = $bp['scheme'] . '://' . $bp['host'] . ( isset( $bp['port'] ) ? ':' . $bp['port'] : '' );
        if ( 0 === strpos( $rel, '/' ) ) {
            return $root . $rel;
        }
        $base_path = isset( $bp['path'] ) ? preg_replace( '#/[^/]*$#', '/', $bp['path'] ) : '/';
        return $root . $base_path . $rel;
    }

    /** 去掉 utm_* 与常见追踪参数。 */
    private function normalize_query( $raw ) {
        $query = trim( (string) $raw );
        if ( '' === $query ) {
            return '';
        }
        $tracking = array( 'fbclid', 'gclid', 'igshid', 'mc_cid', 'mc_eid', 'ref', 'source', 'spm' );
        $parts    = array();
        foreach ( explode( '&', $query ) as $token ) {
            $token = trim( $token );
            if ( '' === $token ) {
                continue;
            }
            $key = strtolower( false !== strpos( $token, '=' ) ? substr( $token, 0, strpos( $token, '=' ) ) : $token );
            if ( 0 === strpos( $key, 'utm_' ) || in_array( $key, $tracking, true ) ) {
                continue;
            }
            $parts[] = $token;
        }
        return implode( '&', $parts );
    }

    /** 邮箱脱敏。 */
    private function sanitize_email( $value ) {
        $text = trim( (string) $value );
        if ( '' === $text ) {
            return '';
        }
        return preg_replace( '/\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b/i', '[redacted]', $text );
    }

    /** 当前 ISO-8601 UTC 时间。 */
    private function now_iso() {
        return gmdate( 'Y-m-d\TH:i:s\Z' );
    }
}
