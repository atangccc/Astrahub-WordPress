import { api, restResourceUrl } from "../api/client";

const HUB_BASE = "/v1/world-chat";

export interface WorldChatEncryptedPayload {
  algorithm: string;
  keyAgreement: string;
  epoch: number;
  nonce: string;
  ciphertext: string;
  aad?: string;
}

export interface WorldChatMemberSummary {
  siteId: string;
  name: string;
  url: string;
  category?: string;
  avatarUrl?: string;
  joinedAt?: string;
  updatedAt?: string;
}

export interface WorldChatMemberDetail extends WorldChatMemberSummary {
  description?: string;
  category?: string;
  rssUrl?: string;
  influenceScore?: number;
  trustScore?: number;
  friendLinkCount?: number;
  relationStatus?: string;
}

export interface WorldChatMessage {
  messageId: string;
  clientMessageId: string;
  sender: WorldChatMemberSummary;
  encrypted: WorldChatEncryptedPayload;
  replyToMessageId?: string;
  replyTo?: WorldChatReplySummary;
  mentions?: WorldChatMentionTarget[];
  status: string;
  reportCount?: number;
  createdAt: string;
  updatedAt: string;
  deletedAt?: string;
}

export interface WorldChatReplySummary {
  messageId: string;
  sender: WorldChatMemberSummary;
  encrypted: WorldChatEncryptedPayload;
  status: string;
  createdAt: string;
  deletedAt?: string;
}

export interface WorldChatBootstrapResponse {
  siteId: string;
  member: WorldChatMemberDetail;
  latest: WorldChatMessage[];
  muted?: {
    reason?: string;
    mutedUntil: string;
  };
  generatedAt: string;
  limits: {
    messagePageSize: number;
    maxMessageBytes: number;
    sendPerMinute?: number;
  };
  unread: WorldChatUnreadResponse;
  stickers: WorldChatStickersResponse;
  members: WorldChatMembersResponse;
}

export interface WorldChatConsentState {
  siteId: string;
  version: string;
  title: string;
  contentMarkdown: string;
  policyUpdatedAt: string;
  accepted: boolean;
  acceptedAt?: string;
  declinedAt?: string;
  updatedAt?: string;
  generatedAt: string;
}

export interface WorldChatConsentSaveResponse {
  state: WorldChatConsentState;
}

export interface WorldChatMessagesResponse {
  items: WorldChatMessage[];
  hasMoreBefore: boolean;
  generatedAt: string;
}

export interface WorldChatUnreadResponse {
  unreadCount: number;
  mentionCount?: number;
  latestMessageId?: string;
  latestMentionMessageId?: string;
  lastReadMessageId?: string;
  lastReadAt?: string;
  generatedAt: string;
}

export interface WorldChatReadResponse {
  unreadCount: number;
  lastReadMessageId?: string;
  lastReadAt: string;
  generatedAt: string;
}

export interface WorldChatMembersResponse {
  items: WorldChatMemberSummary[];
  total: number;
  offset?: number;
  limit?: number;
  generatedAt: string;
}

export interface WorldChatMentionTarget {
  kind: "site" | "sitebot";
  id: string;
  name?: string;
}

export interface WorldChatMentionCandidate extends WorldChatMentionTarget {
  siteId?: string;
  url?: string;
  category?: string;
  avatarUrl?: string;
  description?: string;
}

export interface WorldChatMentionCandidatesResponse {
  items: WorldChatMentionCandidate[];
  total: number;
  generatedAt: string;
}

export interface WorldChatRecentPost {
  itemId: string;
  title: string;
  url: string;
  summary?: string;
  publishedAt?: string;
  updatedAt?: string;
}

export interface WorldChatRecentPostsResponse {
  items: WorldChatRecentPost[];
  generatedAt: string;
}

export interface WorldChatSticker {
  stickerId: string;
  fileName?: string;
  mimeType: string;
  fileSize: number;
  width: number;
  height: number;
  frameCount: number;
  fileUrl: string;
  createdAt: string;
}

export interface WorldChatStickersResponse {
  items: WorldChatSticker[];
  limit: number;
  generatedAt: string;
}

async function parseJson<T>(response: Response): Promise<T> {
  const text = await response.text();
  if (!text) {
    return {} as T;
  }
  try {
    return JSON.parse(text) as T;
  } catch {
    return {} as T;
  }
}

interface WorldChatErrorPayload {
  message?: string;
  error?: {
    code?: string;
    message?: string;
  };
}

const WORLD_CHAT_ERROR_MESSAGES: Record<string, string> = {
  WORLD_CHAT_MODERATION_REJECTED: "消息未通过世界频道安全检查，请调整内容后再发送。",
  WORLD_CHAT_MUTED: "当前星系已被禁言，暂时无法发送消息。",
  WORLD_CHAT_RATE_LIMITED: "发送太快了，请稍后再试。",
  WORLD_CHAT_STICKER_LIMIT_EXCEEDED: "每个星系最多上传 10 个表情包。",
  WORLD_CHAT_INVALID_STICKER: "表情包格式、尺寸或内容不符合要求，请使用 PNG、JPG 或 GIF。",
  WORLD_CHAT_STORAGE_FAILURE: "世界频道数据保存失败，请稍后重试。",
  WORLD_CHAT_MESSAGE_NOT_FOUND: "消息不存在或已被删除。",
  WORLD_CHAT_MEMBER_NOT_FOUND: "星链成员不存在或尚未接入。",
  WORLD_CHAT_RETRACT_EXPIRED: "消息已超过 3 分钟，不能撤回。",
  WORLD_CHAT_CONSENT_REQUIRED: "请先阅读并同意星际通讯功能使用协议。"
};

function isReadableServerMessage(message: string): boolean {
  const trimmed = message.trim();
  if (!trimmed) return false;
  if (/world chat request failed/i.test(trimmed)) return false;
  if (/failed to/i.test(trimmed)) return false;
  if (/not found/i.test(trimmed)) return false;
  if (/storage/i.test(trimmed)) return false;
  return /[\u4e00-\u9fa5]/.test(trimmed);
}

function buildWorldChatErrorMessage(response: Response, payload: WorldChatErrorPayload): string {
  const code = payload.error?.code?.trim() || "";
  const serverMessage = (payload.error?.message || payload.message || "").trim();
  if (isReadableServerMessage(serverMessage)) {
    return serverMessage;
  }
  if (code && WORLD_CHAT_ERROR_MESSAGES[code]) {
    return WORLD_CHAT_ERROR_MESSAGES[code];
  }
  if (response.status === 400) {
    return "请求内容不符合世界频道规则，请检查后重试。";
  }
  if (response.status === 401 || response.status === 403) {
    return "当前接入身份无权执行该操作，请检查星链接入配置。";
  }
  if (response.status === 404) {
    return "世界频道资源不存在或已失效，请刷新后重试。";
  }
  if (response.status === 413) {
    return "上传内容过大，请压缩后再试。";
  }
  if (response.status === 429) {
    return "操作过于频繁，请稍后再试。";
  }
  if (response.status >= 500) {
    return "世界频道服务暂时异常，请稍后重试。";
  }
  return "世界频道请求失败，请稍后重试。";
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const url = new URL(`${HUB_BASE}${path}`, "https://hub.invalid");
  const query = Object.fromEntries(url.searchParams.entries());
  const hubPath = url.pathname;
  const method = String(init.method || "GET").toUpperCase();
  const payload = init.body ? JSON.parse(String(init.body)) as Record<string, unknown> : {};
  const response = method === "GET"
    ? await api.post<T>("/hub/get", { path: hubPath, query })
    : await api.post<T>("/hub/post", { path: hubPath, payload });
  if (!response.success) {
    throw new Error(response.message || "世界频道请求失败，请稍后重试。");
  }
  return response.data;
}

export function hasWorldChatAccess(siteId: string, hasApiKey: boolean) {
  return Boolean(String(siteId || "").trim() && hasApiKey);
}

export async function fetchWorldChatBootstrap() {
  return request<WorldChatBootstrapResponse>("/bootstrap-v2");
}

export async function fetchWorldChatConsent() {
  return request<WorldChatConsentState>("/consent");
}

export async function saveWorldChatConsent(version: string, accepted: boolean) {
  return request<WorldChatConsentSaveResponse>("/consent", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({ version, accepted })
  });
}

export async function fetchWorldChatMessages(params: { beforeMessageId?: string; afterMessageId?: string; limit?: number } = {}) {
  const query = new URLSearchParams();
  if (params.beforeMessageId) {
    query.set("beforeMessageId", params.beforeMessageId);
  }
  if (params.afterMessageId) {
    query.set("afterMessageId", params.afterMessageId);
  }
  if (params.limit) {
    query.set("limit", String(params.limit));
  }
  return request<WorldChatMessagesResponse>(`/messages${query.toString() ? `?${query.toString()}` : ""}`);
}

export async function fetchWorldChatUnread() {
  return request<WorldChatUnreadResponse>("/unread");
}

export async function markWorldChatRead(messageId = "") {
  return request<WorldChatReadResponse>("/read", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify(messageId ? { messageId } : {})
  });
}

export async function sendWorldChatMessage(encrypted: WorldChatEncryptedPayload, clientMessageId: string, mentions: WorldChatMentionTarget[] = [], replyToMessageId = "") {
  return request<{ message: WorldChatMessage; created: boolean }>("/messages", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({
      clientMessageId,
      encrypted,
      replyToMessageId,
      mentions
    })
  });
}

export async function retractWorldChatMessage(messageId: string) {
  return request<{ message: WorldChatMessage; retracted: boolean }>(`/messages/${encodeURIComponent(messageId)}/retract`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: "{}"
  });
}

export async function fetchWorldChatMembers(offset = 0, limit = 40) {
  const query = new URLSearchParams();
  query.set("offset", String(offset));
  query.set("limit", String(limit));
  return request<WorldChatMembersResponse>(`/members?${query.toString()}`);
}

export async function fetchWorldChatMentionCandidates(query = "", offset = 0, limit = 20) {
  const params = new URLSearchParams();
  if (query.trim()) {
    params.set("query", query.trim());
  }
  params.set("offset", String(offset));
  params.set("limit", String(limit));
  return request<WorldChatMentionCandidatesResponse>(`/mention-candidates?${params.toString()}`);
}

export async function fetchWorldChatMember(siteId: string) {
  return request<WorldChatMemberDetail>(`/members/${encodeURIComponent(siteId)}`);
}

export async function fetchWorldChatMemberRecentPosts(siteId: string, limit = 5) {
  return request<WorldChatRecentPostsResponse>(`/members/${encodeURIComponent(siteId)}/recent-posts?limit=${limit}`);
}

export function worldChatStickerFileUrl(stickerId: string) {
  return restResourceUrl(`/world-chat/stickers/${encodeURIComponent(stickerId)}/file`);
}

export async function fetchWorldChatStickers() {
  return request<WorldChatStickersResponse>("/stickers");
}

export async function uploadWorldChatSticker(fileName: string, mimeType: string, dataBase64: string) {
  return request<{ sticker: WorldChatSticker }>("/stickers", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({
      fileName,
      mimeType,
      dataBase64
    })
  });
}

export async function deleteWorldChatSticker(stickerId: string) {
  return request<{ deleted: boolean }>(`/stickers/${encodeURIComponent(stickerId)}/delete`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: "{}"
  });
}

export async function reportWorldChatMessage(messageId: string, reason: string) {
  return request<{ reportId: string; created: boolean }>(`/messages/${encodeURIComponent(messageId)}/reports`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({ reason })
  });
}
