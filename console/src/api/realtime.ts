// 实时连接票据 API：经插件 /realtime/token 签名换取 Hub 的一次性短期 WebSocket token。
// 浏览器据此直连 Hub /v1/ws（WebSocket 不受 CORS 限制，无需 PHP 维持长连接）。
import { api } from "./client";

export interface RealtimeTokenResult {
  success: boolean;
  token: string;
  expiresAt: string;
}

// 把 Hub 基础地址（https://astra.aobp.cn）转成 wss，并拼上 /v1/ws + access_token。
// 对应 Halo 端 useAstraHubRealtimeToken.ts 的 buildHubWsUrl。
export function buildHubWsUrl(rawBaseUrl: string, token: string, lastEventId = ""): string {
  const value = String(rawBaseUrl || "").trim().replace(/\/+$/, "");
  const accessToken = String(token || "").trim();
  if (!value || !accessToken) {
    return "";
  }
  try {
    const url = new URL(value);
    url.protocol = url.protocol === "https:" ? "wss:" : "ws:";
    url.pathname = "/v1/ws";
    url.search = "";
    url.hash = "";
    url.searchParams.set("access_token", accessToken);
    url.searchParams.set("replayLimit", "200");
    const lastId = String(lastEventId || "").trim();
    if (lastId) {
      url.searchParams.set("lastEventId", lastId);
    }
    return url.toString();
  } catch {
    return "";
  }
}

export async function issueRealtimeToken(): Promise<RealtimeTokenResult> {
  const resp = await api.post<{ token?: string; expiresAt?: string }>("/realtime/token", {});
  const data = (resp.data || {}) as { token?: string; expiresAt?: string };
  const token = String(data.token || "").trim();
  if (!resp.success || !token) {
    throw new Error(resp.message || "获取实时连接令牌失败");
  }
  return {
    success: true,
    token,
    expiresAt: String(data.expiresAt || "")
  };
}
