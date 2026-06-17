<?php
/**
 * 凭据与连接配置存储。
 *
 * - credentials: 登舱/注册后 Hub 签发的身份（siteId / apiKey / createdAt / nodeName ...）。
 *   apiKey 是用于对 Hub 请求签名的密钥，属于敏感信息，读写时不对外回显原值。
 * - connection: 站点接入信息（siteName / siteUrl / siteDescription / siteRssUrl /
 *   siteAvatarUrl / contactEmail / siteNodeName / siteNodeAvatar）。
 *
 * 对应 Halo 插件的 ConfigMap credentials / connection 两个分组。WordPress 侧落在
 * wp_options，autoload 关闭避免每次请求都加载。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Credential_Store {

    const OPTION_CREDENTIALS = 'wp_astrahub_credentials';
    const OPTION_CONNECTION  = 'wp_astrahub_connection';

    /**
     * 读取凭据分组。
     *
     * @return array{siteId:string,apiKey:string,createdAt:string,nodeName:string,category:string,nodeAvatar:string}
     */
    public function get_credentials() {
        $stored = get_option( self::OPTION_CREDENTIALS, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return wp_parse_args(
            $stored,
            array(
                'siteId'     => '',
                'apiKey'     => '',
                'createdAt'  => '',
                'nodeName'   => '',
                'category'   => '',
                'nodeAvatar' => '',
            )
        );
    }

    /**
     * 写入凭据分组（整体覆盖）。
     *
     * @param array $credentials 凭据。
     * @return bool
     */
    public function save_credentials( array $credentials ) {
        $current = $this->get_credentials();
        $merged  = array_merge( $current, $credentials );
        return update_option( self::OPTION_CREDENTIALS, $merged, false );
    }

    /**
     * 清空凭据（退出/重登舱前）。
     *
     * @return bool
     */
    public function clear_credentials() {
        return delete_option( self::OPTION_CREDENTIALS );
    }

    /**
     * 是否已登舱（拿到 siteId + apiKey）。
     *
     * @return bool
     */
    public function is_registered() {
        $c = $this->get_credentials();
        return '' !== trim( $c['siteId'] ) && '' !== trim( $c['apiKey'] );
    }

    /**
     * 读取连接配置，缺失项用 WordPress 站点信息自动填充。
     *
     * @return array
     */
    public function get_connection() {
        $stored = get_option( self::OPTION_CONNECTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        $defaults = array(
            'siteName'        => get_bloginfo( 'name' ),
            'siteUrl'         => home_url(),
            'siteDescription' => get_bloginfo( 'description' ),
            'siteRssUrl'      => get_feed_link(),
            'siteAvatarUrl'   => $this->resolve_site_avatar(),
            'contactEmail'    => get_option( 'admin_email', '' ),
            'siteNodeName'    => '',
            'siteNodeAvatar'  => '',
        );
        return wp_parse_args( $stored, $defaults );
    }

    /**
     * 写入连接配置。
     *
     * @param array $connection 连接配置。
     * @return bool
     */
    public function save_connection( array $connection ) {
        $current = $this->get_connection();
        $merged  = array_merge( $current, $connection );
        return update_option( self::OPTION_CONNECTION, $merged, false );
    }

    /**
     * 解析站点头像/Logo（自定义 Logo -> site icon -> 空）。
     *
     * @return string
     */
    private function resolve_site_avatar() {
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $src = wp_get_attachment_image_src( $custom_logo_id, 'full' );
            if ( $src && ! empty( $src[0] ) ) {
                return $src[0];
            }
        }
        $icon = get_site_icon_url();
        return $icon ? $icon : '';
    }
}
