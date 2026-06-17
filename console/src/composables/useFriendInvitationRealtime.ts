// 友链邀请实时通道：浏览器直连 Hub /v1/ws，按 siteId 过滤后回调相关事件。
// 对应 Halo 端 useFriendInvitationRealtime.ts。WebSocket 不受 CORS 限制，
// 长连接由浏览器持有（不经 PHP），断线 3s 自动重连。
import { onBeforeUnmount, watch, type Ref } from "vue";
import { buildHubWsUrl, issueRealtimeToken } from "../api/realtime";
import type { FriendInvitationItem } from "../api/friend";

const RECONNECT_DELAY_MS = 3000;

export type HubInvitationRealtimeEventType =
  | "friend_invitation_created"
  | "friend_invitation_reviewed"
  | "friend_invitation_acked"
  | "friend_invitation_cancelled"
  | "friend_invitation_deleted"
  | "friend_relation_removed"
  | "site_relation_updated"
  | "site_profile_updated";

export interface HubRealtimeEvent<T = unknown> {
  id?: string;
  type: string;
  timestamp?: string;
  data?: T;
}

export interface HubSiteRelationUpdatedPayload {
  sourceSiteId?: string;
  impactedSiteIds?: string[];
  trigger?: string;
  inviteId?: string;
}

const HUB_INVITATION_REALTIME_EVENT_TYPES = new Set<HubInvitationRealtimeEventType>([
  "friend_invitation_created",
  "friend_invitation_reviewed",
  "friend_invitation_acked",
  "friend_invitation_cancelled",
  "friend_invitation_deleted",
  "friend_relation_removed",
  "site_relation_updated",
  "site_profile_updated"
]);

// 与 Hub 服务端 isRealtimeEventVisibleToSite 同口径：事件是否与本站相关。
function isRelevantHubEvent(event: HubRealtimeEvent<unknown>, currentSiteId: string): boolean {
  const type = event.type as HubInvitationRealtimeEventType;
  if (!HUB_INVITATION_REALTIME_EVENT_TYPES.has(type)) {
    return false;
  }
  const siteId = String(currentSiteId || "").trim();
  if (!siteId) {
    return false;
  }
  if (type === "site_relation_updated") {
    const data = (event.data || {}) as HubSiteRelationUpdatedPayload;
    if (String(data.sourceSiteId || "").trim() === siteId) {
      return true;
    }
    const impacted = Array.isArray(data.impactedSiteIds) ? data.impactedSiteIds : [];
    return impacted.some((id) => String(id || "").trim() === siteId);
  }
  if (type === "site_profile_updated") {
    // Hub 只把该事件路由给 impactedSiteIds（与改资料站点有关系的站），收到即相关。
    const data = (event.data || {}) as { impactedSiteIds?: string[] };
    const impacted = Array.isArray(data.impactedSiteIds) ? data.impactedSiteIds : [];
    return impacted.length === 0 || impacted.some((id) => String(id || "").trim() === siteId);
  }
  if (type === "friend_relation_removed") {
    const data = (event.data || {}) as { actorSiteId?: string; peerSiteId?: string };
    return (
      String(data.actorSiteId || "").trim() === siteId ||
      String(data.peerSiteId || "").trim() === siteId
    );
  }
  // 友链邀请事件：data 是 FriendInvitationItem，按 fromSite/toSite 路由。
  const invitation = event.data as FriendInvitationItem | undefined;
  if (!invitation) {
    return false;
  }
  return (
    String(invitation.fromSite?.siteId || "").trim() === siteId ||
    String(invitation.toSite?.siteId || "").trim() === siteId
  );
}

/**
 * @param hubBaseUrl 响应式 Hub 基址（https://astra.aobp.cn）。
 * @param siteId 响应式本站 siteId（凭据）。
 * @param onRelevantEvent 命中本站的事件回调。
 */
export function useFriendInvitationRealtime(
  hubBaseUrl: Ref<string>,
  siteId: Ref<string>,
  onRelevantEvent: (event: HubRealtimeEvent<unknown>) => void
) {
  let socket: WebSocket | null = null;
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  let stopped = false;

  const clearReconnectTimer = () => {
    if (!reconnectTimer) {
      return;
    }
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
  };

  const closeSocket = () => {
    if (!socket) {
      return;
    }
    socket.onopen = null;
    socket.onclose = null;
    socket.onerror = null;
    socket.onmessage = null;
    try {
      socket.close();
    } catch {
      /* ignore */
    }
    socket = null;
  };

  const scheduleReconnect = () => {
    clearReconnectTimer();
    reconnectTimer = setTimeout(() => {
      void connect();
    }, RECONNECT_DELAY_MS);
  };

  const connect = async () => {
    if (stopped || socket) {
      return;
    }
    const base = String(hubBaseUrl.value || "").trim();
    const currentSiteId = String(siteId.value || "").trim();
    if (!base || !currentSiteId) {
      return;
    }
    let token = "";
    try {
      const result = await issueRealtimeToken();
      token = String(result.token || "").trim();
    } catch {
      if (!stopped) {
        scheduleReconnect();
      }
      return;
    }
    if (stopped || socket || !token) {
      if (!token && !stopped) {
        scheduleReconnect();
      }
      return;
    }
    const wsUrl = buildHubWsUrl(base, token);
    if (!wsUrl) {
      scheduleReconnect();
      return;
    }

    const ws = new WebSocket(wsUrl);
    socket = ws;

    ws.onmessage = (messageEvent) => {
      try {
        const event = JSON.parse(String(messageEvent.data)) as HubRealtimeEvent<unknown>;
        if (isRelevantHubEvent(event, currentSiteId)) {
          onRelevantEvent(event);
        }
      } catch {
        /* ignore non-JSON frames (e.g. ws_ready) */
      }
    };

    ws.onclose = () => {
      socket = null;
      if (!stopped) {
        scheduleReconnect();
      }
    };

    ws.onerror = () => {
      closeSocket();
    };
  };

  const reconnect = () => {
    stopped = false;
    clearReconnectTimer();
    closeSocket();
    void connect();
  };

  const stop = () => {
    stopped = true;
    clearReconnectTimer();
    closeSocket();
  };

  // siteId / hubBaseUrl 变化（登舱/退出/换站）时重连。
  watch(
    () => [hubBaseUrl.value, siteId.value].join("|"),
    () => {
      reconnect();
    },
    { immediate: true }
  );

  onBeforeUnmount(stop);

  return { reconnect, stop };
}
