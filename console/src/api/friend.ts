// 友链管理 API：对齐 Halo 端 AstraHubFriendManagementRouter。
// 插件 REST 层签名转发 Hub，前端路径与 WP 端点一一对应。
import { api } from "./client";

export interface FriendSiteInfo {
  siteId: string;
  siteName: string;
  siteUrl: string;
  description?: string;
  avatarUrl?: string;
  rssUrl?: string;
}

export interface FriendInvitationItem {
  inviteId: string;
  direction?: string;
  fromSite: FriendSiteInfo;
  toSite: FriendSiteInfo;
  message?: string;
  status: string;
  deliveryStatus?: string;
  reviewReason?: string;
  linkGroupName?: string;
  createdAt: string;
  reviewedAt?: string;
  ackedAt?: string;
  lastError?: string;
  retryCount?: number;
  updatedAt: string;
}

export interface LinkGroupOption {
  name: string;
  displayName: string;
}

export interface FriendInvitationOverviewResponse {
  success: boolean;
  status: number;
  message: string;
  tab: string;
  generatedAt: string;
  total: number;
  limit: number;
  offset: number;
  hasMore: boolean;
  pendingCount: number;
  items: FriendInvitationItem[];
  linkGroups: LinkGroupOption[];
}

export type FriendInvitationOverviewTab = "all" | "inbox" | "outbox";

export const FRIEND_INVITATION_STATUSES = ["pending", "accepted", "rejected", "cancelled", "expired"] as const;

export function normalizeFriendInvitationStatus(status: unknown): FriendInvitationStatus {
  const value = String(status || "").trim().toLowerCase();
  if (value === "approved" || value === "approve") {
    return "accepted";
  }
  if (value === "denied" || value === "declined" || value === "reject") {
    return "rejected";
  }
  if (value === "canceled") {
    return "cancelled";
  }
  return value || "pending";
}

export type FriendInvitationStatus = "pending" | "accepted" | "rejected" | "cancelled" | "expired" | string;

function normalizeFriendInvitationItem(item: FriendInvitationItem): FriendInvitationItem {
  return {
    ...item,
    status: normalizeFriendInvitationStatus(item.status)
  };
}

/**
 * 友链邀请管理总览 —— 一次请求返回指定 tab 的列表 + linkGroups + 统计，
 * 对齐 Halo 端 AstraHubFriendManagementRouter.overviewInvitations。
 */
export async function fetchFriendInvitationOverview(
  tab: FriendInvitationOverviewTab = "all",
  status = "",
  limit = 20,
  offset = 0
): Promise<FriendInvitationOverviewResponse> {
  const query: Record<string, string> = {
    tab,
    limit: String(Math.max(1, Math.min(100, limit))),
    offset: String(Math.max(0, offset))
  };
  if (status.trim()) {
    query.status = status.trim();
  }
  const resp = await api.get<unknown>("/astrahub/friend-invitations/overview", query);
  const raw = (resp.data || {}) as Record<string, unknown>;
  const items = Array.isArray(raw.items)
    ? (raw.items as FriendInvitationItem[]).map(normalizeFriendInvitationItem)
    : [];
  const linkGroups = Array.isArray(raw.linkGroups)
    ? (raw.linkGroups as LinkGroupOption[])
    : [];
  return {
    success: Boolean(resp.success),
    status: Number(raw.status || resp.status || 200),
    message: String(raw.message || ""),
    tab: String(raw.tab || tab),
    generatedAt: String(raw.generatedAt || ""),
    total: Number(raw.total || items.length),
    limit: Number(raw.limit || limit),
    offset: Number(raw.offset || offset),
    hasMore: Boolean(raw.hasMore),
    pendingCount: Number(raw.pendingCount || 0),
    items,
    linkGroups
  };
}

/**
 * 兼容旧名：fetchFriendInvitations → overview（一次搞定列表 + linkGroups）。
 */
export const fetchFriendInvitations = fetchFriendInvitationOverview;

export async function fetchLinkGroups(): Promise<LinkGroupOption[]> {
  const resp = await api.get<unknown>("/astrahub/friend-invitations/link-groups");
  const raw = (resp.data || {}) as Record<string, unknown>;
  return Array.isArray(raw.items) ? (raw.items as LinkGroupOption[]) : [];
}

export async function createFriendInvitation(toSiteId: string, message = "", linkGroupName = "") {
  const resp = await api.post<unknown>("/astrahub/friend-invitations", { toSiteId, message, linkGroupName });
  if (!resp.success) {
    throw new Error(resp.message || "发起邀请失败");
  }
  return resp;
}

export async function reviewFriendInvitation(
  inviteId: string,
  approved: boolean,
  reason = "",
  linkGroupName = ""
) {
  const resp = await api.post<unknown>(`/astrahub/friend-invitations/${encodeURIComponent(inviteId)}/review`, {
    approved,
    reason,
    linkGroupName
  });
  if (!resp.success) {
    throw new Error(resp.message || "审核失败");
  }
  return resp;
}

export async function cancelFriendInvitation(inviteId: string) {
  const resp = await api.post<unknown>(`/astrahub/friend-invitations/${encodeURIComponent(inviteId)}/cancel`, {});
  if (!resp.success) {
    throw new Error(resp.message || "撤回失败");
  }
  return resp;
}

export async function deleteFriendInvitation(inviteId: string) {
  const resp = await api.post<unknown>(`/astrahub/friend-invitations/${encodeURIComponent(inviteId)}/delete`, {});
  if (!resp.success) {
    throw new Error(resp.message || "删除失败");
  }
  return resp;
}

// 邀请方侧：已接受邀请后，把对端写进本地友链（对齐 Halo reconcileFriendInvitation）。
export async function reconcileFriendInvitation(
  invitation: FriendInvitationItem,
  currentSiteId: string
): Promise<{ created: boolean; duplicate: boolean; message: string }> {
  const resp = await api.post<unknown>(`/astrahub/friend-invitations/${encodeURIComponent(invitation.inviteId)}/reconcile`, {
    currentSiteId,
    fromSiteId: invitation.fromSite.siteId,
    fromSiteName: invitation.fromSite.siteName,
    fromSiteUrl: invitation.fromSite.siteUrl,
    fromDescription: invitation.fromSite.description || "",
    fromAvatarUrl: invitation.fromSite.avatarUrl || "",
    fromRssUrl: invitation.fromSite.rssUrl || "",
    toSiteId: invitation.toSite.siteId,
    toSiteName: invitation.toSite.siteName,
    toSiteUrl: invitation.toSite.siteUrl,
    toDescription: invitation.toSite.description || "",
    toAvatarUrl: invitation.toSite.avatarUrl || "",
    toRssUrl: invitation.toSite.rssUrl || "",
    linkGroupName: invitation.linkGroupName || ""
  });
  if (!resp.success) {
    throw new Error(resp.message || "本地建链失败");
  }
  const raw = (resp.data || {}) as Record<string, unknown>;
  return {
    created: Boolean(raw.created),
    duplicate: Boolean(raw.duplicate),
    message: String(raw.message || "ok")
  };
}

// 邀请方侧：把本地建链结果回执给 Hub（对齐 Halo ackFriendInvitation）。
export async function ackFriendInvitation(inviteId: string, lastError = ""): Promise<void> {
  const resp = await api.post<unknown>(`/astrahub/friend-invitations/${encodeURIComponent(inviteId)}/ack`, { lastError });
  if (!resp.success) {
    throw new Error(resp.message || "回执 Hub 失败");
  }
}

export interface RemoveRelationResult {
  removed: boolean;
  peerSiteId: string;
  peerSiteUrl: string;
  localLinkDeleted: number;
}

export async function removeFriendRelation(peerSiteId: string, reason = ""): Promise<RemoveRelationResult> {
  const resp = await api.post<unknown>(`/astrahub/friend-relations/${encodeURIComponent(peerSiteId)}/remove`, { reason });
  if (!resp.success) {
    throw new Error(resp.message || "解除友链关系失败");
  }
  const raw = (resp.data || {}) as Record<string, unknown>;
  return {
    removed: Boolean(raw.removed),
    peerSiteId: String(raw.peerSiteId || peerSiteId),
    peerSiteUrl: String(raw.peerSiteUrl || ""),
    localLinkDeleted: Number(raw.localLinkDeleted || 0)
  };
}

export async function removeOwnFriendFollow(peerSiteId: string): Promise<RemoveRelationResult> {
  const resp = await api.post<unknown>(`/astrahub/friend-follows/${encodeURIComponent(peerSiteId)}/remove`, {});
  if (!resp.success) {
    throw new Error(resp.message || "删除友链失败");
  }
  const raw = (resp.data || {}) as Record<string, unknown>;
  return {
    removed: Boolean(raw.removed),
    peerSiteId: String(raw.peerSiteId || peerSiteId),
    peerSiteUrl: String(raw.peerSiteUrl || ""),
    localLinkDeleted: Number(raw.localLinkDeleted || 0)
  };
}

// ──────────────────────────────────────────────
//  站点查询（Halo 端新增端点）
// ──────────────────────────────────────────────

export interface SiteLookupResult {
  registered: boolean;
  registeredByPlugin: boolean;
  credentialReady: boolean;
  siteId: string;
  siteName: string;
  siteUrl: string;
  avatarUrl: string;
  supportsInvitation: boolean;
  invitationState: string;
  invitationMessage: string;
}

export async function lookupSiteByUrl(rawUrl: string): Promise<SiteLookupResult> {
  const resp = await api.get<unknown>("/astrahub/sites/lookup", { url: rawUrl });
  if (!resp.success) {
    throw new Error(resp.message || "查询站点失败");
  }
  return (resp.data || {}) as SiteLookupResult;
}

// ──────────────────────────────────────────────
//  实时自清理：对端解除关系/改资料事件回传插件
// ──────────────────────────────────────────────

export async function dispatchSelfCleanupEvent(type: string, data: unknown): Promise<void> {
  const t = String(type || "").trim();
  if (t !== "friend_relation_removed" && t !== "site_profile_updated") return;
  try {
    await api.post("/friend-sync/peer", { type: t, data });
  } catch {
    /* 静默：cron 全量对账会兜底 */
  }
}
