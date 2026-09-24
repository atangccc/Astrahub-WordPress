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
    console.warn("[AstraHub] WP_ASTRAHUB_BOOTSTRAP 未注入，使用回退值（仅开发模式有效）");
    return {
      restBase: "/wp-json/wp-astrahub/v1",
      restNonce: "",
      hubBaseUrl: "https://astra.aobp.cn",
      registered: false,
      __dev: true
    };
  }
  // 一次性诊断：首次 bootstrap 打印关键值
  if (!(window as any).__AH_BOOTSTRAP_LOGGED) {
    (window as any).__AH_BOOTSTRAP_LOGGED = true;
    console.log("[AstraHub] bootstrap", {
      restBase: b.restBase,
      nonce: b.restNonce ? "***" : "missing",
      hubBaseUrl: b.hubBaseUrl,
      registered: b.registered,
    });
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

// 通用 REST 资源代理 URL：插件同源代理 Hub 资源文件（如图片、表情等）。
// 后端 rest-proxy 通过 ?url= 拉取并透传 Content-Type。
export function restResourceUrl(hubPath: string): string {
  const b = bootstrap();
  const params = new URLSearchParams({ url: hubPath });
  if (b.restNonce) {
    params.set("_wpnonce", b.restNonce);
  }
  return `${b.restBase}/hub/resource?${params.toString()}`;
}

async function request<T>(
  method: string,
  path: string,
  body?: Record<string, unknown> | null,
  query?: Record<string, string>
): Promise<ApiEnvelope<T>> {
  const b = bootstrap();
  const qs = new URLSearchParams(query ?? {});
  // 额外把 nonce 塞进查询参数（?_wpnonce），绕过宝塔/Nginx WAF 对 X-WP-Nonce header 的特定路径拦截。
  // WordPress REST 原生同时支持 header 和 query 参数两种鉴权形式，功能等价。
  if (b.restNonce) {
    qs.set("_wpnonce", b.restNonce);
  }
  const qsStr = qs.toString();
  let url = `${b.restBase}${path}${qsStr ? "?" + qsStr : ""}`;

  const headers: Record<string, string> = {
    Accept: "application/json"
  };
  // 注意：这里**不再设置 X-WP-Nonce header**，因为宝塔/Nginx WAF 对特定路径（friend-invitations、hub/get 等）
  // 带 X-WP-Nonce header 的请求直接返回 nginx 原生 404（请求根本不到 PHP）。
  // WordPress REST 原生同时支持 header 和 query 参数两种鉴权形式，?_wpnonce= 功能完全等价，已在上面注入 URL。
  const init: RequestInit = { method, headers, credentials: "same-origin" };
  if (body !== undefined && body !== null) {
    headers["Content-Type"] = "application/json";
    init.body = JSON.stringify(body);
  }

  let urlFull: string;
  try {
    urlFull = new URL(url, window.location.origin).href;
  } catch {
    urlFull = url;
  }

  try {
    const resp = await fetch(urlFull, init);
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
  } catch (e: unknown) {
    // 诊断日志：打印实际请求的 URL，方便排查网络/CORS/证书等问题
    const nav = typeof navigator !== "undefined" ? navigator : null;
    const online = nav && "onLine" in nav ? nav.onLine : "unknown";
    console.warn(
      "[AstraHub] fetch failed",
      {
        url: urlFull,
        restBase: b.restBase,
        nonce: b.restNonce ? "present" : "missing",
        online,
        error: e instanceof Error ? e.message : String(e)
      }
    );
    throw e;
  }
}

export const api = {
  get<T>(path: string, query?: Record<string, string>) {
    return request<T>("GET", path, null, query);
  },
  post<T>(path: string, body?: Record<string, unknown> | null) {
    return request<T>("POST", path, body ?? null);
  },
  put<T>(path: string, body?: Record<string, unknown> | null) {
    return request<T>("PUT", path, body ?? null);
  },
  delete<T>(path: string, body?: Record<string, unknown> | null) {
    return request<T>("DELETE", path, body ?? null);
  }
};
