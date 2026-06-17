<?php
/**
 * 简易 PSR-style 自动加载器。
 *
 * 类名约定：WP_AstraHub_Xxx_Yyy -> includes/class-wp-astrahub-xxx-yyy.php
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Autoloader {

    /**
     * 注册自动加载。
     */
    public static function register() {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * 根据类名解析文件路径并加载。
     *
     * @param string $class 类名。
     */
    public static function autoload( $class ) {
        if ( strpos( $class, 'WP_AstraHub' ) !== 0 ) {
            return;
        }

        // WP_AstraHub_Hub_Client -> class-wp-astrahub-hub-client.php
        $slug = strtolower( str_replace( '_', '-', $class ) );
        $file = WP_ASTRAHUB_DIR . 'includes/class-' . $slug . '.php';

        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
}
