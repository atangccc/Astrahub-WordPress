<?php
/**
 * AstraHub Hub 请求签名工具。
 *
 * 签名协议 v1（与 Hub 服务端 internal/security/signature.go 逐字节对齐）：
 *
 *   canonical = METHOD(大写) + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + SHA256_hex(BODY)
 *   signature = 小写hex( HMAC-SHA256(apiKey, canonical) )
 *
 * 请求需携带以下 Header（由调用方设置）：
 *   - X-BP-Site-Id
 *   - X-BP-Timestamp   （Unix 秒）
 *   - X-BP-Nonce        （UUID 去掉横线）
 *   - X-BP-Signature
 *
 * 重要细节：
 *   1. PATH 必须是「解码后」的原始路径（与 Go 的 r.URL.Path 一致），不能 percent-encode。
 *   2. signature 为小写十六进制（PHP hash_hmac 默认即小写）。
 *   3. body 为请求体原始字符串；GET 等无 body 时传入空串 ""，其 sha256 为固定值。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Hub_Signer {

    /**
     * 对一次请求生成签名字段。
     *
     * @param string $method  HTTP 方法（GET/POST，不区分大小写）。
     * @param string $path    解码后的请求路径，例如 /v1/sites/register。
     * @param string $body    请求体原始字符串；无 body 传 ""。
     * @param string $site_id 站点 ID。
     * @param string $api_key 站点 API Key（HMAC 密钥）。
     * @return array{siteId:string,timestamp:string,nonce:string,signature:string}
     */
    public static function sign_request( $method, $path, $body, $site_id, $api_key ) {
        $timestamp = (string) time();
        $nonce     = self::generate_nonce();
        $body_hash = self::sha256_hex( null === $body ? '' : $body );

        $canonical = strtoupper( $method )
            . "\n" . $path
            . "\n" . $timestamp
            . "\n" . $nonce
            . "\n" . $body_hash;

        $signature = self::hmac_sha256_hex( $api_key, $canonical );

        return array(
            'siteId'    => $site_id,
            'timestamp' => $timestamp,
            'nonce'     => $nonce,
            'signature' => $signature,
        );
    }

    /**
     * 把签名字段转换为 HTTP 头数组。
     *
     * @param array $signed sign_request 的返回值。
     * @return array<string,string>
     */
    public static function to_headers( array $signed ) {
        return array(
            'X-BP-Site-Id'   => $signed['siteId'],
            'X-BP-Timestamp' => $signed['timestamp'],
            'X-BP-Nonce'     => $signed['nonce'],
            'X-BP-Signature' => $signed['signature'],
        );
    }

    /**
     * HMAC-SHA256 小写十六进制。
     *
     * @param string $secret  密钥。
     * @param string $message 消息。
     * @return string
     */
    public static function hmac_sha256_hex( $secret, $message ) {
        return hash_hmac( 'sha256', $message, $secret );
    }

    /**
     * SHA-256 小写十六进制。
     *
     * @param string $input 输入。
     * @return string
     */
    public static function sha256_hex( $input ) {
        return hash( 'sha256', null === $input ? '' : $input );
    }

    /**
     * 生成 nonce：UUIDv4 去掉横线。
     *
     * @return string
     */
    public static function generate_nonce() {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return str_replace( '-', '', wp_generate_uuid4() );
        }
        // 兜底：32 位随机十六进制。
        return bin2hex( random_bytes( 16 ) );
    }
}
