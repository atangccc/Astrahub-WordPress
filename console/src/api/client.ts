// 后台 SPA 与插件 PHP REST 通信的统一客户端。
// 所有请求带 X-WP-Nonce（WordPress REST nonce），命中插件 wp-astrahub/v1 命名空间。
// 插件再把需要签名的请求转发给 Hub（astra.aobp.cn）。

export interface ApiEnvelope<T = unknown> {
  success: boolean;
  status: number;
  message: string;
  data: T;
}

function bootstrap(): AstraHubBootstrap {
  const b = window.WP_ASTRAHUB_BOOTSTRAP;
  if (!b) {
    return {
      restBase: "/wp-json/wp-astrahub/v1",
      restNonce: "",
      hubBaseUrl: "https://astra.aobp.cn",
      registered: false,
      __dev: true
    };
  }
  return b;
}

export function isDev(): boolean {
  return Boolean(bootstrap().__dev);
}

export function hubBaseUrl(): string {
  return bootstrap().hubBaseUrl;
}

// 关系图头像代理 URL：3D 画布把头像绘进 canvas 纹理，跨域图片会污染 canvas，
// 因此走插件同源代理。<img> 无法带 X-WP-Nonce 头，nonce 以 ?_wpnonce= 查询参数携带
// （WordPress REST 原生识别）。对齐 Halo 端 graph/avatar 代理。
export function graphAvatarProxyUrl(remoteUrl: string): string {
  const b = bootstrap();
  const params = new URLSearchParams({ url: remoteUrl });
  if (b.restNonce) {
    params.set("_wpnonce", b.restNonce);
  }
  return `${b.restBase}/graph/avatar?${params.toString()}`;
}

async function request<T>(
  method: string,
  path: string,
  body?: Record<string, unknown> | null,
  query?: Record<string, string>
): Promise<ApiEnvelope<T>> {
  const b = bootstrap();
  let url = `${b.restBase}${path}`;
  if (query && Object.keys(query).length > 0) {
    const qs = new URLSearchParams(query).toString();
    url += (url.includes("?") ? "&" : "?") + qs;
  }

  const headers: Record<string, string> = {
    Accept: "application/json"
  };
  if (b.restNonce) {
    headers["X-WP-Nonce"] = b.restNonce;
  }
  const init: RequestInit = { method, headers };
  if (body !== undefined && body !== null) {
    headers["Content-Type"] = "application/json";
    init.body = JSON.stringify(body);
  }

  const resp = await fetch(url, init);
  const text = await resp.text();
  let parsed: Partial<ApiEnvelope<T>> = {};
  try {
    parsed = text ? (JSON.parse(text) as Partial<ApiEnvelope<T>>) : {};
  } catch {
    parsed = {};
  }

  return {
    success: Boolean(parsed.success),
    status: Number(parsed.status ?? resp.status),
    message: parsed.message ?? (resp.ok ? "ok" : `request failed: ${resp.status}`),
    data: (parsed.data ?? {}) as T
  };
}

export const api = {
  get<T>(path: string, query?: Record<string, string>) {
    return request<T>("GET", path, null, query);
  },
  post<T>(path: string, body?: Record<string, unknown> | null) {
    return request<T>("POST", path, body ?? null);
  }
};
