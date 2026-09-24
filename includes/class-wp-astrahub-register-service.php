<?php
/**
 * 注册 / 登舱服务。
 *
 * 完整复刻 Halo 端 AstraHubRegisterService + Boarding 流程，对接 Hub 的 5 个端点：
 *   - POST /v1/sites/register                直接注册（可选 X-BP-Register-Token）
 *   - POST /v1/sites/invitations/apply       申请邮箱签发码
 *   - POST /v1/sites/register                + X-BP-Invitation-Code 用签发码注册
 *   - POST /v1/sites/boarding/send-code      重登舱：发送验证码到邮箱
 *   - POST /v1/sites/boarding/restore        重登舱：用验证码恢复站点身份
 *
 * 注册/登舱成功后，把 Hub 返回的 siteId / apiKey 等写入凭据存储，并同步连接配置。
 * 这些请求都「不需要站点签名」（此时还没有 apiKey），用 request_public。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AstraHub_Register_Service {

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
     * 构造。
     *
     * @param WP_AstraHub_Hub_Client       $hub_client  Hub 客户端。
     * @param WP_AstraHub_Credential_Store $credentials 凭据存储。
     */
    public function __construct( WP_AstraHub_Hub_Client $hub_client, WP_AstraHub_Credential_Store $credentials ) {
        $this->hub_client  = $hub_client;
        $this->credentials = $credentials;
    }

    /**
     * 直接注册。
     *
     * @param array $input 站点信息字段（见 build_register_payload）。
     * @return array{success:bool,status:int,message:string,data:array}
     */
    public function register( array $input ) {
        $errors = $this->validate_register_fields( $input );
        if ( ! empty( $errors ) ) {
            return $this->fail( 400, '请填写所有必填字段', $errors );
        }

        $headers = array();
        $token   = isset( $input['registerToken'] ) ? trim( (string) $input['registerToken'] ) : '';
        if ( '' !== $token ) {
            $headers['X-BP-Register-Token'] = $token;
        }

        $payload  = $this->build_register_payload( $input );
        $response = $this->hub_client->request_public( 'POST', '/v1/sites/register', $payload, $headers );

        return $this->handle_register_response( $response, $input );
    }

    /**
     * 申请邮箱签发码。
     *
     * @param array $input contactEmail（必填）, siteUrl（可选）。
     * @return array
     */
    public function request_invitation( array $input ) {
        $contact_email = isset( $input['contactEmail'] ) ? trim( (string) $input['contactEmail'] ) : '';
        if ( '' === $contact_email ) {
            return $this->fail( 400, '联系邮箱不能为空', array( 'contactEmail' => '联系邮箱不能为空' ) );
        }

        $payload = array( 'contactEmail' => $contact_email );
        $site_url = isset( $input['siteUrl'] ) ? trim( (string) $input['siteUrl'] ) : '';
        if ( '' !== $site_url ) {
            $payload['siteUrl'] = $site_url;
        }

        $response = $this->hub_client->request_public( 'POST', '/v1/sites/invitations/apply', $payload );

        if ( $response['success'] ) {
            return $this->ok(
                $response['status'],
                '验证码已发送至邮箱',
                array(
                    'expiresAt'     => $this->str( $response['body'], 'expiresAt' ),
                    'cooldownUntil' => $this->str( $response['body'], 'cooldownUntil' ),
                )
            );
        }
        return $this->fail( $response['status'], $response['message'] );
    }

    /**
     * 用签发码注册。
     *
     * @param array $input 站点信息 + invitationCode（必填）。
     * @return array
     */
    public function register_with_invitation( array $input ) {
        $code = isset( $input['invitationCode'] ) ? trim( (string) $input['invitationCode'] ) : '';
        if ( '' === $code ) {
            return $this->fail( 400, '邀请码不能为空', array( 'invitationCode' => '邀请码不能为空' ) );
        }
        $errors = $this->validate_register_fields( $input );
        if ( ! empty( $errors ) ) {
            return $this->fail( 400, '请填写所有必填字段', $errors );
        }

        $payload  = $this->build_register_payload( $input );
        $headers  = array( 'X-BP-Invitation-Code' => $code );
        $response = $this->hub_client->request_public( 'POST', '/v1/sites/register', $payload, $headers );

        return $this->handle_register_response( $response, $input );
    }

    /**
     * 重登舱：发送验证码到邮箱。
     *
     * @param array $input contactEmail（必填）。
     * @return array
     */
    public function send_boarding_code( array $input ) {
        $contact_email = isset( $input['contactEmail'] ) ? trim( (string) $input['contactEmail'] ) : '';
        if ( '' === $contact_email ) {
            return $this->fail( 400, '联系邮箱不能为空', array( 'contactEmail' => '联系邮箱不能为空' ) );
        }

        $payload  = array( 'contactEmail' => $contact_email );
        $response = $this->hub_client->request_public( 'POST', '/v1/sites/boarding/send-code', $payload );

        if ( $response['success'] ) {
            return $this->ok(
                $response['status'],
                '验证码已发送',
                array( 'expiresAt' => $this->str( $response['body'], 'expiresAt' ) )
            );
        }
        return $this->fail( $response['status'], $response['message'] );
    }

    /**
     * 重登舱：用验证码恢复站点身份。
     *
     * @param array $input contactEmail + code（均必填）。
     * @return array
     */
    public function restore_boarding( array $input ) {
        $contact_email = isset( $input['contactEmail'] ) ? trim( (string) $input['contactEmail'] ) : '';
        $code          = isset( $input['code'] ) ? trim( (string) $input['code'] ) : '';
        if ( '' === $contact_email ) {
            return $this->fail( 400, '联系邮箱不能为空', array( 'contactEmail' => '联系邮箱不能为空' ) );
        }
        if ( '' === $code ) {
            return $this->fail( 400, '验证码不能为空', array( 'code' => '验证码不能为空' ) );
        }

        $payload = array(
            'contactEmail' => $contact_email,
            'code'         => $code,
        );
        $response = $this->hub_client->request_public( 'POST', '/v1/sites/boarding/restore', $payload );

        if ( ! $response['success'] ) {
            return $this->fail( $response['status'], $response['message'] );
        }

        $body    = $response['body'];
        $site_id = $this->str( $body, 'siteId' );
        $api_key = $this->str( $body, 'apiKey' );
        if ( '' === $site_id || '' === $api_key ) {
            return $this->fail( $response['status'], '恢复响应中缺少 siteId/apiKey' );
        }

        $this->persist_identity( $body );

        // 恢复时 Hub 会回传站点资料，回填连接配置（域名变更后以 Hub 为准）。
        $this->credentials->save_connection(
            array_filter(
                array(
                    'siteName'        => $this->str( $body, 'siteName' ),
                    'siteUrl'         => $this->str( $body, 'siteUrl' ),
                    'siteDescription' => $this->str( $body, 'description' ),
                    'siteRssUrl'      => $this->str( $body, 'rssUrl' ),
                    'contactEmail'    => $this->str( $body, 'contactEmail' ),
                    'siteNodeName'    => $this->str( $body, 'nodeName' ),
                    'siteNodeAvatar'  => $this->str( $body, 'nodeAvatar' ),
                ),
                static function ( $v ) {
                    return '' !== $v;
                }
            )
        );

        return $this->ok(
            $response['status'],
            '已恢复登舱',
            $this->credentials->get_credentials()
        );
    }

    /**
     * 校验注册必填字段，返回字段级错误（空数组表示通过）。
     *
     * @param array $input 输入。
     * @return array<string,string> 字段名 => 中文错误提示。
     */
    private function validate_register_fields( array $input ) {
        $errors   = array();
        $required = array(
            'siteName'       => '站点名称不能为空',
            'siteUrl'        => '站点 URL 不能为空',
            'contactEmail'   => '联系邮箱不能为空',
            'siteNodeName'   => '星链节点名不能为空',
            'siteNodeAvatar' => '星链头像链接不能为空',
        );
        foreach ( $required as $key => $message ) {
            $value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
            if ( '' === $value ) {
                $errors[ $key ] = $message;
            }
        }
        return $errors;
    }

    /**
     * 构造注册请求体（与 Halo 端字段一致）。
     *
     * @param array $input 输入。
     * @return array
     */
    private function build_register_payload( array $input ) {
        return array(
            'name'         => trim( (string) ( $input['siteName'] ?? '' ) ),
            'url'          => trim( (string) ( $input['siteUrl'] ?? '' ) ),
            'description'  => trim( (string) ( $input['siteDescription'] ?? '' ) ),
            'rssUrl'       => trim( (string) ( $input['siteRssUrl'] ?? '' ) ),
            'avatarUrl'    => trim( (string) ( $input['siteAvatarUrl'] ?? '' ) ),
            'contactEmail' => trim( (string) ( $input['contactEmail'] ?? '' ) ),
            'nodeName'     => trim( (string) ( $input['siteNodeName'] ?? '' ) ),
            'category'     => trim( (string) ( $input['siteNodeName'] ?? '' ) ),
            'nodeAvatar'   => trim( (string) ( $input['siteNodeAvatar'] ?? '' ) ),
        );
    }

    /**
     * 处理 register / register_with_invitation 的响应，成功则落库。
     *
     * @param array $response Hub 响应。
     * @param array $input    原始输入（用于回填连接配置）。
     * @return array
     */
    private function handle_register_response( array $response, array $input ) {
        if ( ! $response['success'] ) {
            return $this->fail( $response['status'], $response['message'] );
        }

        $body    = $response['body'];
        $site_id = $this->str( $body, 'siteId' );
        $api_key = $this->str( $body, 'apiKey' );
        if ( '' === $site_id || '' === $api_key ) {
            return $this->fail( $response['status'], '注册响应中缺少 siteId/apiKey' );
        }

        $this->persist_identity( $body );

        // 注册成功后，把本次提交的连接信息落库，供后续推送使用。
        $this->credentials->save_connection(
            array(
                'siteName'        => trim( (string) ( $input['siteName'] ?? '' ) ),
                'siteUrl'         => trim( (string) ( $input['siteUrl'] ?? '' ) ),
                'siteDescription' => trim( (string) ( $input['siteDescription'] ?? '' ) ),
                'siteRssUrl'      => trim( (string) ( $input['siteRssUrl'] ?? '' ) ),
                'siteAvatarUrl'   => trim( (string) ( $input['siteAvatarUrl'] ?? '' ) ),
                'contactEmail'    => trim( (string) ( $input['contactEmail'] ?? '' ) ),
                'siteNodeName'    => trim( (string) ( $input['siteNodeName'] ?? '' ) ),
                'siteNodeAvatar'  => trim( (string) ( $input['siteNodeAvatar'] ?? '' ) ),
            )
        );

        return $this->ok(
            $response['status'],
            '注册成功',
            $this->credentials->get_credentials()
        );
    }

    /**
     * 把 Hub 返回的身份字段写入凭据存储。nodeName 缺失时回退 category。
     *
     * @param array $body Hub 响应体。
     */
    private function persist_identity( array $body ) {
        $node_name = $this->str( $body, 'nodeName' );
        $category  = $this->str( $body, 'category' );
        if ( '' === $node_name ) {
            $node_name = $category;
        }
        $this->credentials->save_credentials(
            array(
                'siteId'     => $this->str( $body, 'siteId' ),
                'apiKey'     => $this->str( $body, 'apiKey' ),
                'createdAt'  => $this->str( $body, 'createdAt' ),
                'nodeName'   => $node_name,
                'category'   => $category,
                'nodeAvatar' => $this->str( $body, 'nodeAvatar' ),
            )
        );
    }

    /**
     * 更新站点资料（对齐 Halo AstraHubRegisterService.updateProfileBlocking）。
     *
     * Hub 端签名 PUT /v1/sites/profile，允许已注册站点更新 name/url/description/
     * rssUrl/avatarUrl/contactEmail/nodeName/nodeAvatar。成功后 Hub 会返回最新的
     * nodeName / category / nodeAvatar，以此为准落库。
     *
     * @param array $input 站点资料字段（同 build_register_payload）。
     * @return array{success:bool,status:int,message:string,data:array}
     */
    public function update_profile( array $input ) {
        $creds = $this->credentials->get_credentials();
        if ( '' === trim( $creds['siteId'] ) || '' === trim( $creds['apiKey'] ) ) {
            return $this->fail( 401, 'siteId/apiKey is required，请先注册站点' );
        }

        $connection = $this->credentials->get_connection();
        $site_name  = trim( (string) ( $input['siteName'] ?? $connection['siteName'] ) );
        $site_url   = trim( (string) ( $input['siteUrl'] ?? $connection['siteUrl'] ) );
        $email      = trim( (string) ( $input['contactEmail'] ?? $connection['contactEmail'] ) );
        $node_name  = trim( (string) ( $input['siteNodeName'] ?? $connection['siteNodeName'] ) );
        if ( '' === $site_name || '' === $site_url || '' === $email || '' === $node_name ) {
            return $this->fail( 400, 'site profile is incomplete', array(
                'siteName'     => '' === $site_name ? '站点名称不能为空' : '',
                'siteUrl'      => '' === $site_url ? '站点 URL 不能为空' : '',
                'contactEmail' => '' === $email ? '联系邮箱不能为空' : '',
                'siteNodeName' => '' === $node_name ? '星链节点名不能为空' : '',
            ) );
        }

        $payload = array(
            'name'         => $site_name,
            'url'          => $site_url,
            'description'  => trim( (string) ( $input['siteDescription'] ?? $connection['siteDescription'] ) ),
            'rssUrl'       => trim( (string) ( $input['siteRssUrl'] ?? $connection['siteRssUrl'] ) ),
            'avatarUrl'    => trim( (string) ( $input['siteAvatarUrl'] ?? $connection['siteAvatarUrl'] ) ),
            'contactEmail' => $email,
            'nodeName'     => $node_name,
            'nodeAvatar'   => trim( (string) ( $input['siteNodeAvatar'] ?? $connection['siteNodeAvatar'] ) ),
        );

        $response = $this->hub_client->request_signed( 'PUT', '/v1/sites/profile', $payload );

        if ( ! $response['success'] ) {
            return $this->fail( $response['status'], $response['message'] );
        }

        $body = $response['body'];

        // Hub 可能回传更新后的 nodeName / category / nodeAvatar，以此为准覆盖本地凭据。
        $hub_node_name = $this->str( $body, 'nodeName' );
        $hub_category  = $this->str( $body, 'category' );
        $hub_avatar    = $this->str( $body, 'nodeAvatar' );

        if ( '' !== $hub_node_name || '' !== $hub_category || '' !== $hub_avatar ) {
            $update = array();
            if ( '' !== $hub_node_name ) {
                $update['nodeName'] = $hub_node_name;
            }
            if ( '' !== $hub_category ) {
                $update['category'] = $hub_category;
            }
            if ( '' !== $hub_avatar ) {
                $update['nodeAvatar'] = $hub_avatar;
            }
            if ( ! empty( $update ) ) {
                $merged = array_merge( $creds, $update );
                $this->credentials->save_credentials( $merged );
            }
        }

        // 同步连接配置：把本次提交的字段（或 Hub 返回的）写进本地连接信息。
        $this->credentials->save_connection(
            array(
                'siteName'        => $site_name,
                'siteUrl'         => $site_url,
                'siteDescription' => $payload['description'],
                'siteRssUrl'      => $payload['rssUrl'],
                'siteAvatarUrl'   => $payload['avatarUrl'],
                'contactEmail'    => $email,
                'siteNodeName'    => $node_name,
                'siteNodeAvatar'  => $payload['nodeAvatar'],
            )
        );

        return $this->ok(
            $response['status'],
            'profile updated',
            array(
                'siteId'     => $creds['siteId'],
                'nodeName'   => $hub_node_name ?: $creds['nodeName'],
                'category'   => $hub_category ?: $creds['category'],
                'nodeAvatar' => $hub_avatar ?: $creds['nodeAvatar'],
            )
        );
    }

    /**
     * 安全读取字符串字段。
     *
     * @param array  $source 源。
     * @param string $key    键。
     * @return string
     */
    private function str( array $source, $key ) {
        return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? trim( (string) $source[ $key ] ) : '';
    }

    /**
     * 成功结果。
     *
     * @param int    $status  状态。
     * @param string $message 信息。
     * @param array  $data    数据。
     * @return array
     */
    private function ok( $status, $message, array $data = array() ) {
        return array(
            'success' => true,
            'status'  => $status,
            'message' => $message,
            'data'    => $data,
        );
    }

    /**
     * 失败结果。
     *
     * @param int    $status  状态码。
     * @param string $message 错误摘要。
     * @param array  $fields  字段级错误（字段名 => 中文提示），可选。
     * @return array
     */
    private function fail( $status, $message, array $fields = array() ) {
        $result = array(
            'success' => false,
            'status'  => $status ? $status : 400,
            'message' => $message ? $message : '请求失败，请重试',
            'data'    => array(),
        );
        if ( ! empty( $fields ) ) {
            $result['fields'] = $fields;
        }
        return $result;
    }
}
