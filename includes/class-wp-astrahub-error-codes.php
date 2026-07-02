<?php
/**
 * AstraHub Hub 错误码中文映射。
 *
 * 把 Hub 服务端返回的 error.code 翻译为用户友好的中文提示。
 * 所有错误码面向最终用户（非开发者），避免泄漏内部实现细节。
 *
 * @package WPAstraHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AstraHub_Error_Codes {

	/**
	 * 错误码 → 中文标题 映射表。
	 *
	 * @var array<string,string>
	 */
	private static $map = array(
		// ── 鉴权类 ──────────────────────────────────────────
		'AUTH_MISSING_HEADERS'          => '缺少签名请求头，可能是插件凭据未正确保存',
		'AUTH_UNKNOWN_SITE'             => '站点 ID 不存在，请重新注册',
		'AUTH_INVALID_TIMESTAMP'        => '时间戳格式异常，请确认服务器时间是否准确',
		'AUTH_EXPIRED'                  => '请求签名已过期（超过 5 分钟），请重试',
		'AUTH_REPLAY'                   => '请求已被重复提交，请刷新页面后重试',
		'AUTH_INVALID_SIGNATURE'        => '签名验证失败，请检查 API Key 是否正确',
		'AUTH_FAILED'                   => 'WebSocket 连接凭据无效，请刷新页面重连',
		'SITE_BLOCKED'                  => '站点已被管理员封禁，如有疑问请联系运营',
		'RATE_LIMITED'                  => '请求过于频繁，请稍后再试',

		// ── 注册类 ──────────────────────────────────────────
		'INVALID_INPUT'                 => '输入字段校验未通过，请检查必填项是否完整',
		'INVITATION_INVALID'            => '邀请码无效，请确认输入是否正确',
		'INVITATION_ALREADY_USED'       => '该邀请码已被使用，每个邀请码仅可注册一次',
		'INVITATION_NOT_ALLOCATED'      => '该邀请码尚未分配，请先向管理员申请',
		'INVITATION_EMAIL_MISMATCH'     => '邮箱与邀请码绑定的邮箱不一致',
		'SITE_URL_CONFLICT'             => '该站点地址已被其他邮箱注册',
		'SITE_ALREADY_REGISTERED'       => '该邮箱已注册过站点，请使用“恢复登舱”功能',
		'SITE_EXISTS'                   => '站点已存在，无需重复注册',
		'INVITATION_MARK_USED_FAILED'   => '注册成功但邀请码标记失败，请联系管理员',

		// ── 登舱恢复类 ──────────────────────────────────────
		'SITE_NOT_FOUND'                => '未找到关联站点，请确认邮箱是否正确',
		'BOARDING_CODE_INVALID'         => '验证码错误或已过期，请重新获取',
		'BOARDING_CODE_LOCKED'          => '验证码错误次数超限，请稍后重新获取',

		// ── 友链邀请类 ──────────────────────────────────────
		'FRIEND_INVITATION_INVALID_JSON'    => '请求格式异常，请刷新页面后重试',
		'FRIEND_INVITATION_INVALID_INPUT'   => '友链邀请参数不完整，缺少目标站点',
		'FRIEND_INVITATION_NOT_FOUND'       => '友链邀请不存在，可能已被撤回',
		'FRIEND_INVITATION_FORBIDDEN'       => '无权操作该友链邀请',
		'FRIEND_INVITATION_ALREADY_REVIEWED' => '该友链邀请已被处理，不可重复操作',
		'FRIEND_INVITATION_ALREADY_CANCELLED' => '该友链邀请已取消，不可再操作',
		'FRIEND_INVITATION_DUPLICATE'       => '已存在相同的友链邀请，请勿重复发送',

		// ── 通用 ────────────────────────────────────────────
		'INVALID_JSON'                  => '请求数据格式异常，请刷新页面后重试',
		'INVALID_BODY'                  => '请求体过大（超过 1 MiB），请精简数据后重试',
		'INTERNAL_ERROR'                => 'Hub 服务内部异常，请稍后重试',
		'INTERNAL_PANIC'                => 'Hub 服务内部异常，请稍后重试',
		'WEBSOCKET_UNAVAILABLE'         => 'WebSocket 服务暂不可用，请稍后重试',
	);

	/**
	 * 根据错误码获取中文描述。
	 *
	 * 若错误码在映射表中存在，返回 “[] 中文描述”；否则返回原始 message。
	 *
	 * @param string $code    Hub 返回的 error.code。
	 * @param string $message Hub 返回的 error.message（备用）。
	 * @return string
	 */
	public static function describe( $code, $message = '' ) {
		$code = trim( (string) $code );
		if ( '' !== $code && isset( self::$map[ $code ] ) ) {
			return '[' . $code . '] ' . self::$map[ $code ];
		}
		// 无映射或有原始 message 直接用
		if ( '' !== trim( (string) $message ) ) {
			return (string) $message;
		}
		return '未知错误，请重试或联系管理员';
	}

	/**
	 * 判断是否可重试。
	 *
	 * @param int    $http_status HTTP 状态码。
	 * @param string $error_code  Hub error.code（可选）。
	 * @return bool
	 */
	public static function is_retryable( $http_status, $error_code = '' ) {
		$status = (int) $http_status;
		// 可重试：429、502、503、504、INTERNAL_ERROR
		if ( in_array( $status, array( 429, 502, 503, 504 ), true ) ) {
			return true;
		}
		if ( 'INTERNAL_ERROR' === $error_code || 'INTERNAL_PANIC' === $error_code || 'WEBSOCKET_UNAVAILABLE' === $error_code ) {
			return true;
		}
		// 签名错误一律不重试
		return false;
	}
}
