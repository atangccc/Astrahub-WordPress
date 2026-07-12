// 友链邀请 + 世界频道实时通道：浏览器直连 Hub WS（首选）/ SSE（备用），
// 按 siteId 过滤后回调相关事件。WS 不受 CORS 限制，断线 3s 自动重连；
// 当 WS 连接超时（5s）时自动回退到 SSE（EventSource）。
import { onBeforeUnmount, watch, type Ref } from "vue";
import { buildHubWsUrl, buildHubSseUrl, issueRealtimeToken } from "../api/realtime";

const RECONNECT_DELAY_MS = 3000;
const WS_TIMEOUT_MS = 5000;

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

// 世界频道事件类型：本站直接可见，不再检查 siteId 匹配。
const WORLD_CHAT_EVENT_TYPES = new Set([
  "world_chat_message_created",
  "world_chat_message_updated",
  "world_chat_mute_updated"
]);

// 与 Hub 服务端 isRealtimeEventVisibleToSite 同口径：事件是否与本站相关。
function isRelevantHubEvent(event: HubRealtimeEvent<unknown>, currentSiteId: string): boolean {
  const type = event.type as HubInvitationRealtimeEventType;

  // 世界频道事件：本站直接可见
  if (WORLD_CHAT_EVENT_TYPES.has(event.type)) {
    return true;
  }

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
  const invitation = event.data as { fromSite?: { siteId?: string }; toSite?: { siteId?: string } } | undefined;
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
  let eventSource: EventSource | null = null;
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  let wsTimeoutTimer: ReturnType<typeof setTimeout> | null = null;
  let stopped = false;
  let lastEventId = "";
  let useFallback = false; // true = 使用 SSE 模式

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

  const closeEventSource = () => {
    if (!eventSource) {
      return;
    }
    eventSource.onopen = null;
    eventSource.onerror = null;
    eventSource.onmessage = null;
    try {
      eventSource.close();
    } catch {
      /* ignore */
    }
    eventSource = null;
  };

  const scheduleReconnect = () => {
    clearReconnectTimer();
    clearWsTimeoutTimer();
    reconnectTimer = setTimeout(() => {
      void connect();
    }, RECONNECT_DELAY_MS);
  };

  const clearWsTimeoutTimer = () => {
    if (wsTimeoutTimer) {
      clearTimeout(wsTimeoutTimer);
      wsTimeoutTimer = null;
    }
  };

  const onEventData = (raw: string) => {
    try {
      const event = JSON.parse(raw) as HubRealtimeEvent<unknown>;
      if (event.id) {
        lastEventId = event.id;
      }
      const currentSiteId = String(siteId.value || "").trim();
      if (isRelevantHubEvent(event, currentSiteId)) {
        onRelevantEvent(event);
      }
    } catch {
      /* ignore non-JSON frames */
    }
  };

  const connectSSE = async (base: string, currentSiteId: string) => {
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
    if (stopped || !token) {
      if (!token && !stopped) {
        scheduleReconnect();
      }
      return;
    }
    const sseUrl = buildHubSseUrl(base, token, lastEventId);
    if (!sseUrl) {
      scheduleReconnect();
      return;
    }
    const es = new EventSource(sseUrl);
    eventSource = es;
    es.onmessage = (messageEvent) => {
      onEventData(String(messageEvent.data));
    };
    es.onerror = () => {
      eventSource = null;
      es.close();
      if (!stopped) {
        useFallback = true;
        scheduleReconnect();
      }
    };
  };

  const connectWS = async (base: string, currentSiteId: string) => {
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
    if (stopped || !token) {
      if (!token && !stopped) {
        scheduleReconnect();
      }
      return;
    }
    const wsUrl = buildHubWsUrl(base, token, lastEventId);
    if (!wsUrl) {
      scheduleReconnect();
      return;
    }

    const ws = new WebSocket(wsUrl);
    socket = ws;

    // WS 超时计时器：5 秒内未 open 则回退到 SSE
    wsTimeoutTimer = setTimeout(() => {
      if (ws.readyState !== WebSocket.OPEN) {
        ws.close();
        socket = null;
        useFallback = true;
        void connect();
      }
    }, WS_TIMEOUT_MS);

    ws.onopen = () => {
      clearWsTimeoutTimer();
    };

    ws.onmessage = (messageEvent) => {
      onEventData(String(messageEvent.data));
    };

    ws.onclose = () => {
      socket = null;
      clearWsTimeoutTimer();
      if (!stopped) {
        scheduleReconnect();
      }
    };

    ws.onerror = () => {
      clearWsTimeoutTimer();
      closeSocket();
    };
  };

  const connect = async () => {
    if (stopped) {
      return;
    }
    // 避免重复连接
    if (socket || eventSource) {
      return;
    }
    const base = String(hubBaseUrl.value || "").trim();
    const currentSiteId = String(siteId.value || "").trim();
    if (!base || !currentSiteId) {
      return;
    }

    if (useFallback) {
      await connectSSE(base, currentSiteId);
    } else {
      await connectWS(base, currentSiteId);
    }
  };

  const reconnect = () => {
    stopped = false;
    useFallback = false;
    lastEventId = "";
    clearReconnectTimer();
    clearWsTimeoutTimer();
    closeSocket();
    closeEventSource();
    void connect();
  };

  const stop = () => {
    stopped = true;
    clearReconnectTimer();
    clearWsTimeoutTimer();
    closeSocket();
    closeEventSource();
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
