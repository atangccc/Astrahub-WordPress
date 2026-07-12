// 关系图谱 API：经插件 /hub/get 代理签名转发 Hub /v1/graph/*。
// 对应 Halo 端 AstraHubGraphRouter + useRelationGraph.ts 的数据流。
import { api } from "./client";

export interface GraphNodeFriendLink {
  id: string;
  sourceSiteId?: string;
  sourceSiteName?: string;
  sourceSiteUrl?: string;
  title: string;
  url: string;
  description?: string;
  logo?: string;
  rssUrl?: string;
  targetSiteId?: string;
  targetRegistered: boolean;
  targetNodeId?: string;
  targetNodeName?: string;
  targetAvatar?: string;
  firstSeenAt?: string;
  lastSeenAt?: string;
}

export interface GraphSiteSummary {
  siteId: string;
  name: string;
  url: string;
  nodeId?: string;
  nodeName?: string;
  avatar?: string;
  status: string;
}

export interface GraphNodeSummary {
  nodeId: string;
  name: string;
  avatar?: string;
  status: string;
  primarySite?: { siteId?: string; name?: string; url?: string; avatar?: string };
}

export interface GraphSiteDetailResponse {
  generatedAt: string;
  summary: GraphSiteSummary;
}

export interface GraphNodeDetailResponse {
  generatedAt: string;
  summary: GraphNodeSummary;
  friendLinks: GraphNodeFriendLink[];
}

export interface GraphNodeListItem {
  summary: GraphNodeSummary;
  metrics?: Record<string, unknown>;
}

export interface GraphNodesResponse {
  generatedAt: string;
  page: number;
  size: number;
  total: number;
  items: GraphNodeListItem[];
}

async function hubGet<T>(path: string, query: Record<string, string>): Promise<T> {
  const resp = await api.post<T>("/hub/get", { path, query });
  if (!resp.success) {
    throw new Error(resp.message || "加载关系图失败");
  }
  return (resp.data || {}) as T;
}

// 校验并返回安全的路径段（与 Halo 端 sanitizePathSegment 对齐）：
// 拒绝含 / ? # 或空字符的值。返回「未编码」的原始段，使插件端签名的 PATH
// 与 Hub 的 r.URL.Path（已解码）逐字节一致——unicode 节点名也能正确签名。
function safeSegment(raw: string): string {
  const value = String(raw || "").trim();
  if (!value || value.length > 256) {
    throw new Error("节点标识无效");
  }
  for (const ch of value) {
    if (ch === "/" || ch === "?" || ch === "#" || ch === "\u0000") {
      throw new Error("节点标识无效");
    }
  }
  return value;
}

// 取本站节点摘要（前端据此解析自身 nodeId 作为 BFS 种子）。
export async function fetchMySite(siteId: string, size = 100): Promise<GraphSiteDetailResponse> {
  const safe = safeSegment(siteId);
  return hubGet<GraphSiteDetailResponse>(`/v1/graph/sites/${safe}`, {
    size: String(size)
  });
}

// 全量节点列表（按推荐度排序，用于把孤岛节点也铺到画布上）。
export async function fetchGraphNodes(page = 1, size = 100, sort = "recommendation"): Promise<GraphNodesResponse> {
  return hubGet<GraphNodesResponse>("/v1/graph/nodes", {
    page: String(page),
    size: String(size),
    sort
  });
}

// 单节点详情（含一度友链，用于 BFS 展开）。
export async function fetchGraphNode(nodeId: string, size = 100): Promise<GraphNodeDetailResponse> {
  const safe = safeSegment(nodeId);
  return hubGet<GraphNodeDetailResponse>(`/v1/graph/nodes/${safe}`, {
    size: String(size)
  });
}
