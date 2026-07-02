<?php
/**
 * AstraHub Hub HTTP 客户端。
 *
 * 负责：
 *   - 拼接 Hub 基础地址（WP_ASTRAHUB_HUB_BASE_URL）与请求路径。
 *   - 对需要鉴权的请求用 apiKey 进行 HMAC 签名并附加 X-BP-* 头。
 *   - 用 wp_remote_* 发起请求，统一返回结构。
 *
 * 签名所用 PATH 为「解码后路径」（与 Go r.URL.Path 对齐）；带 query 的 GET 请求，
 * query 不参与签名，仅拼接到实际请求 URL。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Hub_Client {

    /**
     * 凭据存储。
     *
     * @var WP_AstraHub_Credential_Store
     */
    private $credentials;

    /**
     * 请求超时（秒）。
     *
     * @var int
     */
    private $timeout = 15;

    /**
     * 构造。
     *
     * @param WP_AstraHub_Credential_Store $credentials 凭据存储。
     */
    public function __construct( WP_AstraHub_Credential_Store $credentials ) {
        $this->credentials = $credentials;
    }

    /**
     * Hub 基础地址（去尾部斜杠）。
     *
     * @return string
     */
    public function base_url() {
        return rtrim( WP_ASTRAHUB_HUB_BASE_URL, '/' );
    }

    /**
     * 发起一个「无需站点签名」的请求（注册、申请签发码等）。
     *
     * @param string     $method  HTTP 方法。
     * @param string     $path    路径（以 / 开头）。
     * @param array|null $body    请求体（数组，将编码为 JSON）；GET 传 null。
     * @param array      $headers 额外头（如 X-BP-Register-Token / X-BP-Invitation-Code）。
     * @param array      $query   query 参数。
     * @return array{success:bool,status:int,body:array,raw:string,message:string}
     */
    public function request_public( $method, $path, $body = null, array $headers = array(), array $query = array() ) {
        return $this->dispatch( $method, $path, $body, $headers, $query, false );
    }

    /**
     * 发起一个「需要站点签名」的请求（推送、友链邀请、读取代理等）。
     *
     * @param string     $method  HTTP 方法。
     * @param string     $path    路径（以 / 开头）。
     * @param array|null $body    请求体（数组，将编码为 JSON）；GET 传 null。
     * @param array      $headers 额外头。
     * @param array      $query   query 参数。
     * @return array{success:bool,status:int,body:array,raw:string,message:string}
     */
    public function request_signed( $method, $path, $body = null, array $headers = array(), array $query = array() ) {
        return $this->dispatch( $method, $path, $body, $headers, $query, true );
    }

    /**
     * 实际派发请求。
     *
     * @param string     $method HTTP 方法。
     * @param string     $path   路径。
     * @param array|null $body   请求体。
     * @param array      $headers 额外头。
     * @param array      $query  query。
     * @param bool       $signed 是否签名。
     * @return array
     */
    private function dispatch( $method, $path, $body, array $headers, array $query, $signed ) {
        $method = strtoupper( $method );

        // body 序列化：仅当传入数组时编码为 JSON；GET 等无 body 用空串参与签名。
        $body_string = '';
        if ( null !== $body ) {
            $json_body = is_array( $body ) && empty( $body ) ? (object) array() : $body;
            $body_string = wp_json_encode( $json_body );
            if ( false === $body_string ) {
                return $this->fail( 400, '请求体编码失败' );
            }
        }

        $request_headers = array(
            'Accept' => 'application/json',
        );
        if ( null !== $body ) {
            $request_headers['Content-Type'] = 'application/json';
        }

        if ( $signed ) {
            $creds = $this->credentials->get_credentials();
            $site_id = trim( $creds['siteId'] );
            $api_key = trim( $creds['apiKey'] );
            if ( '' === $site_id || '' === $api_key ) {
                return $this->fail( 400, '缺少凭据（siteId/apiKey），请先注册站点' );
            }
            // 签名 PATH 用解码后的路径，不含 query。
            $signed_fields   = WP_AstraHub_Hub_Signer::sign_request( $method, $path, $body_string, $site_id, $api_key );
            $request_headers = array_merge( $request_headers, WP_AstraHub_Hub_Signer::to_headers( $signed_fields ) );
        }

        // 调用方额外头优先级最高（覆盖）。
        $request_headers = array_merge( $request_headers, $headers );

        $url = $this->base_url() . $path;
        if ( ! empty( $query ) ) {
            $url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
        }

        $args = array(
            'method'  => $method,
            'headers' => $request_headers,
            'timeout' => $this->timeout,
        );
        if ( null !== $body ) {
            $args['body'] = $body_string;
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $this->fail( 502, '网络请求错误：' . $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = (string) wp_remote_retrieve_body( $response );
        $parsed = json_decode( $raw, true );
        if ( ! is_array( $parsed ) ) {
            $parsed = array();
        }

        if ( $status >= 200 && $status < 300 ) {
            return array(
                'success' => true,
                'status'  => $status,
                'body'    => $parsed,
                'raw'     => $raw,
                'message' => '',
            );
        }

        return array(
            'success' => false,
            'status'  => $status,
            'body'    => $parsed,
            'raw'     => $raw,
            'message' => $this->extract_error_message( $parsed, $status ),
        );
    }

    /**
     * 从响应体提取错误信息（含中文错误码映射）。
     *
     * @param array $body   解析后的响应体。
     * @param int   $status 状态码。
     * @return string
     */
    private function extract_error_message( array $body, $status ) {
        $code    = '';
        $message = '';

        // Hub 标准格式：{ "error": { "code": "...", "message": "..." } }
        if ( isset( $body['error'] ) ) {
            if ( is_string( $body['error'] ) ) {
                $message = $body['error'];
            } elseif ( is_array( $body['error'] ) ) {
                $code    = isset( $body['error']['code'] ) ? (string) $body['error']['code'] : '';
                $message = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';
            }
        }

        // 优先用错误码映射；无映射则用原始 message 或状态码兜底
        if ( '' !== $code ) {
            return WP_AstraHub_Error_Codes::describe( $code, $message );
        }

        if ( '' !== $message ) {
            return $message;
        }

        // 兜底：body 顶层 message
        if ( isset( $body['message'] ) && is_string( $body['message'] ) && '' !== trim( $body['message'] ) ) {
            return $body['message'];
        }

        return '请求失败（HTTP ' . $status . '），请检查 Hub 地址配置或网络连接';
    }

    /**
     * 构造失败结果。
     *
     * @param int    $status  状态码。
     * @param string $message 信息。
     * @return array
     */
    private function fail( $status, $message ) {
        return array(
            'success' => false,
            'status'  => $status,
            'body'    => array(),
            'raw'     => '',
            'message' => $message,
        );
    }
}
