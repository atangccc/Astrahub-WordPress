// 友链管理 API：经插件 REST 代理签名转发 Hub /v1/friend-invitations/*。
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

export interface FriendInvitationsResponse {
  success: boolean;
  generatedAt: string;
  total: number;
  items: FriendInvitationItem[];
}

export interface LinkGroupOption {
  name: string;
  displayName: string;
}

type Box = "inbox" | "outbox";

export async function fetchFriendInvitations(box: Box, status = ""): Promise<FriendInvitationsResponse> {
  const query: Record<string, string> = { box };
  if (status.trim()) {
    query.status = status.trim();
  }
  const resp = await api.get<unknown>("/friend-invitations", query);
  const raw = (resp.data || {}) as Record<string, unknown>;
  const items = Array.isArray(raw.items) ? (raw.items as FriendInvitationItem[]) : [];
  return {
    success: Boolean(resp.success),
    generatedAt: String(raw.generatedAt || ""),
    total: Number(raw.total || items.length),
    items
  };
}

export async function reviewFriendInvitation(
  inviteId: string,
  approved: boolean,
  reason = "",
  linkGroupName = ""
) {
  const resp = await api.post<unknown>(`/friend-invitations/${encodeURIComponent(inviteId)}/review`, {
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
  const resp = await api.post<unknown>(`/friend-invitations/${encodeURIComponent(inviteId)}/cancel`, {});
  if (!resp.success) {
    throw new Error(resp.message || "撤回失败");
  }
  return resp;
}

export async function deleteFriendInvitation(inviteId: string) {
  const resp = await api.post<unknown>(`/friend-invitations/${encodeURIComponent(inviteId)}/delete`, {});
  if (!resp.success) {
    throw new Error(resp.message || "删除失败");
  }
  return resp;
}

export async function fetchLinkGroups(): Promise<LinkGroupOption[]> {
  const resp = await api.get<unknown>("/friend-invitations/link-groups");
  const raw = (resp.data || {}) as Record<string, unknown>;
  return Array.isArray(raw.items) ? (raw.items as LinkGroupOption[]) : [];
}

export async function createFriendInvitation(toSiteId: string, message = "", linkGroupName = "") {
  const resp = await api.post<unknown>("/friend-invitations", { toSiteId, message, linkGroupName });
  if (!resp.success) {
    throw new Error(resp.message || "发起邀请失败");
  }
  return resp;
}

// 邀请方侧：已接受邀请后，把对端写进本地友链（对齐 Halo reconcileFriendInvitation）。
// 请求体与 Halo 一致，扁平化双方站点字段 + currentSiteId，由插件按本站凭据择出对端。
export async function reconcileFriendInvitation(
  invitation: FriendInvitationItem,
  currentSiteId: string
): Promise<{ created: boolean; duplicate: boolean; message: string }> {
  const resp = await api.post<unknown>(`/friend-invitations/${encodeURIComponent(invitation.inviteId)}/reconcile`, {
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

// 邀请方侧：把本地建链结果回执给 Hub（写 ackedAt / lastError）。对齐 Halo ackFriendInvitation。
export async function ackFriendInvitation(inviteId: string, lastError = ""): Promise<void> {
  const resp = await api.post<unknown>(`/friend-invitations/${encodeURIComponent(inviteId)}/ack`, { lastError });
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
  const resp = await api.post<unknown>(`/friend-relations/${encodeURIComponent(peerSiteId)}/remove`, { reason });
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

// 实时自清理：WS 收到对端解除关系/改资料事件后，把事件原样回传插件，插件按本站凭据
// 直接处理本地友链（删/改），对齐 Halo 端 HubRealtimeBridge。失败静默（cron 兜底）。
export async function dispatchSelfCleanupEvent(type: string, data: unknown): Promise<void> {
  const t = String(type || "").trim();
  if (t !== "friend_relation_removed" && t !== "site_profile_updated") return;
  try {
    await api.post("/friend-sync/peer", { type: t, data });
  } catch {
    /* 静默：cron 全量对账会兜底 */
  }
}
