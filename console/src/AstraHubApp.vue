<script lang="ts" setup>
import { computed, onMounted, ref } from "vue";
import { useStatus } from "./composables/useStatus";
import { fetchFriendInvitations, dispatchSelfCleanupEvent, reconcileFriendInvitation, ackFriendInvitation, type FriendInvitationItem } from "./api/friend";
import { useFriendInvitationRealtime, type HubRealtimeEvent } from "./composables/useFriendInvitationRealtime";
import ConnectionPanel from "./components/ConnectionPanel.vue";
import PlanetLinksPanel from "./components/PlanetLinksPanel.vue";
import FriendInvitationPanel from "./components/FriendInvitationPanel.vue";
import NewsHubPanel from "./components/NewsHubPanel.vue";
import RelationGraphPanel from "./components/RelationGraphPanel.vue";
import StarCommunicationsPanel from "./components/StarCommunicationsPanel.vue";

type NavId = "planetLinks" | "friendManagement" | "news" | "relationGraph" | "starComms" | "maintenance";
type FriendTab = "all" | "pending" | "accepted" | "rejected" | "outbox";
type PlanetFilter = "all" | "favorites" | "pendingBack" | "following" | "mutual";

const activeNav = ref<NavId>("planetLinks");
const friendTab = ref<FriendTab>("all");
const planetFilter = ref<PlanetFilter>("all");
const planetSearch = ref("");
const newsSearch = ref("");
const relationRefreshSignal = ref(0);
const pendingInboxCount = ref(0);
// 透传给友链管理面板的最新实时事件（面板据此做卡片 upsert/remove）。
const friendRealtimeEvent = ref<HubRealtimeEvent<unknown> | null>(null);

const { loading, registered, connection, credentials, hubBaseUrl, fetchStatus } = useStatus();

const pageTitleMap: Record<NavId, string> = {
  maintenance: "星链<span class='ah-kw'>接入配置</span>",
  planetLinks: "<span class='ah-kw'>友链</span>星球",
  friendManagement: "<span class='ah-kw'>友链</span>管理",
  relationGraph: "<span class='ah-kw'>关系</span>图",
  starComms: "<span class='ah-kw'>星际</span>通讯",
  news: "星链<span class='ah-kw'>资讯</span>"
};

const worldChatSettings = computed(() => ({
  credentials: {
    siteId: String(credentials.value.siteId || ""),
    apiKey: credentials.value.hasApiKey ? "server-managed" : ""
  },
  connection: { hubBaseUrl: realtimeHubBaseUrl.value }
}));

const planetFilters: { id: PlanetFilter; label: string }[] = [
  { id: "all", label: "全部" },
  { id: "favorites", label: "已收藏" },
  { id: "pendingBack", label: "未关注" },
  { id: "following", label: "已关注" },
  { id: "mutual", label: "互相关注" }
];

const friendTabs: { id: FriendTab; label: string }[] = [
  { id: "all", label: "全部" },
  { id: "pending", label: "待审核" },
  { id: "accepted", label: "已通过" },
  { id: "rejected", label: "已拒绝" },
  { id: "outbox", label: "发出的" }
];

function refreshRelationGraph() {
  relationRefreshSignal.value += 1;
}

// 顶栏「保存设置」：触发 ConnectionPanel 保存当前连接表单（对齐 Halo 顶栏保存按钮）。
const connectionSaveSignal = ref(0);
function saveConnectionSettings() {
  connectionSaveSignal.value += 1;
}

// 拉取收件箱待审核数量，更新红点。
async function refreshPendingCount() {
  try {
    const resp = await fetchFriendInvitations("inbox", "pending", 1, 0);
    pendingInboxCount.value = resp.total;
  } catch {
    pendingInboxCount.value = 0;
  }
}

// 红点权威纠偏：乐观增减只为即时反馈，可能因 WS 重放/断线/本地与事件双扣而漂移。
// 任何与收件箱相关的事件后，800ms 防抖向服务端重取一次真实待审数，消除累计误差。
let pendingReconcileTimer: ReturnType<typeof setTimeout> | null = null;
function schedulePendingReconcile() {
  if (pendingReconcileTimer) clearTimeout(pendingReconcileTimer);
  pendingReconcileTimer = setTimeout(() => { void refreshPendingCount(); }, 800);
}

function onPendingRemove() {
  if (pendingInboxCount.value > 0) {
    pendingInboxCount.value -= 1;
  }
  // 本地乐观减一后也做一次权威纠偏，避免与 WS 事件重复扣减导致偏小。
  schedulePendingReconcile();
}

// ——— 实时通道：浏览器直连 Hub WS，事件驱动红点与卡片更新（不刷新整页）———
const realtimeSiteId = computed(() => credentials.value.siteId || "");
const realtimeHubBaseUrl = computed(() => hubBaseUrl.value || window.WP_ASTRAHUB_BOOTSTRAP?.hubBaseUrl || "");

function siteIdOf(invitation: { fromSite?: { siteId?: string }; toSite?: { siteId?: string } } | undefined, field: "fromSite" | "toSite") {
  return String(invitation?.[field]?.siteId || "").trim();
}

// 邀请方收到 reviewed=accepted 时，立即在本地把对端写进友链并回执 Hub。
// 对齐 Halo autoReconcileAcceptedOutboxInvitation：去重集合避免 WS 重放重复建链；
// 失败时把原因 ack 回 Hub（用户在「发出的」tab 可见），并从去重集合移除以便后续重试。
const reconciledOutboxIds = new Set<string>();
async function autoReconcileAcceptedOutboxInvitation(invitation: FriendInvitationItem) {
  const inviteId = String(invitation.inviteId || "").trim();
  if (!inviteId || reconciledOutboxIds.has(inviteId)) {
    return;
  }
  reconciledOutboxIds.add(inviteId);
  const mySiteId = String(credentials.value.siteId || "").trim();
  try {
    await reconcileFriendInvitation(invitation, mySiteId);
    await ackFriendInvitation(inviteId, "");
  } catch (error) {
    const message = error instanceof Error ? error.message : "本地建链失败";
    try {
      await ackFriendInvitation(inviteId, message);
    } catch {
      /* ignore：fallback ack 失败仅日志层面损失，不影响主流程 */
    }
    reconciledOutboxIds.delete(inviteId);
  }
}

// 收到与本站相关的实时事件：更新收件箱红点，并透传给面板做卡片增删。
function onRealtimeEvent(event: HubRealtimeEvent<unknown>) {
  const myId = String(credentials.value.siteId || "").trim();

  // 世界频道事件：透传给星际通讯面板
  if (event.type === "world_chat_message_created" || event.type === "world_chat_message_updated" || event.type === "world_chat_mute_updated") {
    friendRealtimeEvent.value = event;
    return;
  }

  // 链路 B 实时分支：对端解除关系 / 改资料 → 把事件原样回传插件，由插件按本站凭据
  // 直接处理本地友链（删/改），100% 对齐 Halo 端 HubRealtimeBridge 的服务端处理。
  if (event.type === "friend_relation_removed" || event.type === "site_profile_updated") {
    void dispatchSelfCleanupEvent(event.type, event.data);
    // 仍透传给面板：友链星球需据此静默刷新关系态（红点逻辑与这两类事件无关）。
    friendRealtimeEvent.value = event;
    return;
  }

  const invitation = event.data as { fromSite?: { siteId?: string }; toSite?: { siteId?: string }; status?: string } | undefined;
  const isInbox = Boolean(myId) && invitation ? siteIdOf(invitation, "toSite") === myId : false;
  const isOutbox = Boolean(myId) && invitation ? siteIdOf(invitation, "fromSite") === myId : false;

  // 邀请方收到 reviewed=accepted：立即本地建链 + ack 回执（不依赖用户切到友链管理页）。
  if (
    isOutbox &&
    event.type === "friend_invitation_reviewed" &&
    String(invitation?.status || "") === "accepted"
  ) {
    void autoReconcileAcceptedOutboxInvitation(event.data as FriendInvitationItem);
  }

  // 红点：仅统计「收件箱待审核」数量的增减。
  if (event.type === "friend_invitation_created" && isInbox) {
    pendingInboxCount.value += 1;
    schedulePendingReconcile();
  } else if (
    isInbox &&
    (event.type === "friend_invitation_reviewed" ||
      event.type === "friend_invitation_cancelled" ||
      event.type === "friend_invitation_deleted")
  ) {
    // 收件箱里某条不再 pending（被审核/撤回/删除），红点减一（不低于 0）。
    if (pendingInboxCount.value > 0 && String(invitation?.status || "") !== "pending") {
      pendingInboxCount.value -= 1;
    }
    schedulePendingReconcile();
  }

  // 透传最新事件给面板（即使当前没在友链管理页，挂载后也能据此渲染）。
  friendRealtimeEvent.value = event;
}

useFriendInvitationRealtime(realtimeHubBaseUrl, realtimeSiteId, onRealtimeEvent);

onMounted(() => {
  fetchStatus();
  refreshPendingCount();
});
</script>

<template>
  <div class="ah-page">
    <div class="ah-card">
      <!-- 左侧悬浮导航 -->
      <div class="ah-float-nav">
        <button class="ah-float-btn" :class="{ active: activeNav === 'planetLinks' }" title="友链星球" @click="activeNav = 'planetLinks'">
          <svg viewBox="0 0 24 24" fill="none" width="16" height="16"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
        </button>
        <button class="ah-float-btn" :class="{ active: activeNav === 'friendManagement' }" title="友链管理" @click="activeNav = 'friendManagement'">
          <svg viewBox="0 0 24 24" fill="none" width="16" height="16"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" /><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2" /><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2" /></svg>
          <span v-if="pendingInboxCount > 0" class="ah-nav-badge">{{ pendingInboxCount > 99 ? '99+' : pendingInboxCount }}</span>
        </button>
        <button class="ah-float-btn" :class="{ active: activeNav === 'news' }" title="资讯" @click="activeNav = 'news'">
          <svg viewBox="0 0 1024 1024" fill="currentColor" width="16" height="16"><path d="M891.099429 580.900571c-25.490286 0-47.506286 13.897143-59.392 34.486858h-126.098286l-99.84-199.497143-76.8 281.6-51.565714-567.808-67.584-5.778286-131.035429 491.300571H64v68.900572h267.702857L426.313143 329.142857l51.273143 564.699429 67.620571 5.924571 79.579429-292.022857 38.107428 76.214857h168.813715c11.885714 20.48 33.901714 34.486857 59.392 34.486857a68.790857 68.790857 0 1 0 0-137.581714z" /></svg>
        </button>
        <button class="ah-float-btn" :class="{ active: activeNav === 'relationGraph' }" title="关系图" @click="activeNav = 'relationGraph'">
          <svg viewBox="0 0 1024 1024" fill="currentColor" width="16" height="16"><path d="M825.137 881.283a38.598 38.598 0 0 1 11.48 3.978l25.445-72.991a72.623 72.623 0 0 1-12.082-2.249l-24.843 71.262z m-680.189-219.26a38.87 38.87 0 0 1-6.149 10.487l74.684 40.425a72.539 72.539 0 0 1 5.388-10.899l-73.923-40.013z m508.99-518.16l59.891 11.9a38.63 38.63 0 0 1 4.283-11.536l-64.089-12.734c0.142 1.859 0.237 3.731 0.237 5.627 0 2.275-0.118 4.521-0.322 6.743z m-58.603 194.801v-130a73.159 73.159 0 0 1-13.971 1.353 73.44 73.44 0 0 1-10.328-0.742v129.113c3.416-0.22 6.857-0.343 10.328-0.343 4.709 0 9.366 0.217 13.971 0.619z m-267.507 344.98a73.249 73.249 0 0 1 15.864 18.423l114.44-105.334a159.056 159.056 0 0 1-13.958-20.178L327.828 683.644z m492.365 2.223l-101.239-109.99a159.002 159.002 0 0 1-14.111 20.548l100.206 108.87a73.17 73.17 0 0 1 15.144-19.428z" /><path d="M740.062 496.744c0-82.938-63.626-151.004-144.728-158.08a160.558 160.558 0 0 0-13.971-0.619c-3.471 0-6.912 0.124-10.328 0.343-82.832 5.323-148.371 74.179-148.371 158.355 0 29.099 7.839 56.364 21.509 79.811a159.056 159.056 0 0 0 13.958 20.178c29.098 35.817 73.488 58.71 123.232 58.71 49.885 0 94.386-23.024 123.479-59.017a159.002 159.002 0 0 0 14.111-20.548c13.427-23.294 21.109-50.316 21.109-79.133zM327.828 683.644c-12.628-10.493-28.853-16.808-46.556-16.808-26.463 0-49.63 14.102-62.402 35.2a72.73 72.73 0 0 0-5.388 10.899 72.673 72.673 0 0 0-5.107 26.798c0 40.26 32.637 72.897 72.897 72.897s72.897-32.637 72.897-72.897c0-13.784-3.829-26.673-10.477-37.666a73.249 73.249 0 0 0-15.864-18.423z m541.478-16.808c-18.921 0-36.156 7.211-49.113 19.031a73.202 73.202 0 0 0-15.144 19.427c-5.509 10.257-8.64 21.981-8.64 34.438 0 33.566 22.694 61.815 53.57 70.287a72.52 72.52 0 0 0 12.082 2.249c2.383 0.235 4.799 0.36 7.244 0.36 40.26 0 72.897-32.637 72.897-72.897 0-40.258-32.637-72.895-72.896-72.895zM595.335 208.664c31.421-6.101 55.627-32.373 58.603-64.801 0.204-2.222 0.322-4.468 0.322-6.743 0-1.896-0.095-3.768-0.237-5.627-2.876-37.627-34.295-67.27-72.659-67.27-40.26 0-72.897 32.637-72.897 72.897 0 36.752 27.203 67.137 62.569 72.155a73.43 73.43 0 0 0 10.328 0.742c4.78 0 9.448-0.474 13.971-1.353zM713.085 163.341c0 21.472 17.406 38.878 38.878 38.878s38.878-17.406 38.878-38.878-17.406-38.878-38.878-38.878c-14.522 0-27.176 7.968-33.851 19.765a38.62 38.62 0 0 0-4.283 11.536 39.071 39.071 0 0 0-0.744 7.577z m-565.457 484.5c0-21.472-17.407-38.878-38.878-38.878-21.472 0-38.878 17.406-38.878 38.878s17.406 38.878 38.878 38.878c12.106 0 22.918-5.534 30.049-14.21a38.845 38.845 0 0 0 6.149-10.487 38.763 38.763 0 0 0 2.68-14.181z m670.651 232.827c-21.472 0-38.878 17.406-38.878 38.878s17.406 38.878 38.878 38.878 38.878-17.406 38.878-38.878c0-14.84-8.317-27.733-20.541-34.285a38.626 38.626 0 0 0-11.48-3.978 39.008 39.008 0 0 0-6.857-0.615z" /></svg>
        </button>
        <button class="ah-float-btn" :class="{ active: activeNav === 'starComms' }" title="星际通讯" @click="activeNav = 'starComms'">
          <svg viewBox="0 0 24 24" fill="none" width="16" height="16"><path d="M8 10h8M8 14h5M5 19V6.8C5 5.81 5.81 5 6.8 5h10.4c.99 0 1.8.81 1.8 1.8v7.4c0 .99-.81 1.8-1.8 1.8H10l-5 3z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
        </button>
        <button class="ah-float-btn" :class="{ active: activeNav === 'maintenance' }" title="接入配置" @click="activeNav = 'maintenance'">
          <svg viewBox="0 0 24 24" fill="none" width="16" height="16"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z" stroke="currentColor" stroke-width="2" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68 1.65 1.65 0 0 0 10 3.17V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" stroke="currentColor" stroke-width="2" /></svg>
        </button>
      </div>

      <!-- 四角装饰 -->
      <div class="ah-corner ah-corner-tl"></div>
      <div class="ah-corner ah-corner-tr"></div>
      <div class="ah-corner ah-corner-bl"></div>
      <div class="ah-corner ah-corner-br"></div>

      <!-- 顶部栏 -->
      <div class="ah-topbar">
        <div class="ah-topbar-left">
          <svg viewBox="0 0 1024 1024" width="26" height="26" aria-hidden="true">
            <path d="M970.474667 521.813333a14.08 14.08 0 0 0-10.24 0l-49.92 29.013334a13.653333 13.653333 0 0 0-5.12 18.346666 13.653333 13.653333 0 0 0 18.346666 4.693334l49.92-29.013334a13.226667 13.226667 0 0 0 5.12-17.92 13.226667 13.226667 0 0 0-8.106666-5.12zM754.581333 218.026667l23.466667-13.653334a29.013333 29.013333 0 0 0 10.666667-39.68 28.586667 28.586667 0 0 0-39.68-10.666666L576.234667 256a28.586667 28.586667 0 0 1-15.786667 0 29.013333 29.013333 0 0 1-17.92-13.653333 29.866667 29.866667 0 0 1 12.373333-40.96l85.333334-50.346667a390.826667 390.826667 0 0 0-526.933334 368.213333 394.24 394.24 0 0 0 37.973334 170.666667l-56.32 32.853333a20.053333 20.053333 0 0 0-7.253334 27.306667 19.626667 19.626667 0 0 0 12.373334 9.386667 20.053333 20.053333 0 0 0 14.933333-2.133334L243.434667 682.666667a32 32 0 0 1 34.56 16.213333 33.28 33.28 0 0 1-3.413334 38.826667L71.488 853.333333a29.44 29.44 0 0 0-10.666667 39.68 30.72 30.72 0 0 0 17.92 13.653334 29.866667 29.866667 0 0 0 21.76-2.986667L388.501333 738.133333a14.506667 14.506667 0 0 1 8.96 6.826667 15.36 15.36 0 0 1-5.546666 20.48l-119.466667 69.546667A389.12 389.12 0 0 0 834.794667 725.333333l-39.68 23.04a15.36 15.36 0 0 1-11.52 0 14.506667 14.506667 0 0 1-8.96-6.826666 14.933333 14.933333 0 0 1 5.546666-20.48l81.066667-47.36a395.093333 395.093333 0 0 0-106.666667-459.093334zM1016.981333 494.933333a12.373333 12.373333 0 0 0-13.226666 0 11.946667 11.946667 0 0 0-6.4 11.52 12.373333 12.373333 0 0 0 6.4 11.52 12.373333 12.373333 0 0 0 13.226666 0 13.226667 13.226667 0 0 0 6.4-11.52 12.373333 12.373333 0 0 0-6.4-11.52zM81.301333 357.12L85.568 354.133333a14.08 14.08 0 0 0 0-5.12v-2.986666A14.08 14.08 0 0 0 85.568 341.333333l-4.266667-3.413333h-27.733333v-23.466667a14.08 14.08 0 0 0 0-5.12l-2.986667-2.986666L42.901333 305.493333H37.781333a6.826667 6.826667 0 0 0-2.986666 2.986667 8.533333 8.533333 0 0 0 0 5.12v23.466667h-29.866667L0.234667 341.333333a14.08 14.08 0 0 0 0 5.12v2.986667a14.08 14.08 0 0 0 0 4.693333l2.986666 2.986667a8.533333 8.533333 0 0 0 5.12 0h24.32v22.613333a8.533333 8.533333 0 0 0 0.853334 4.266667 6.826667 6.826667 0 0 0 2.986666 2.986667H42.901333a14.08 14.08 0 0 0 5.12 0A9.813333 9.813333 0 0 0 52.714667 384a14.08 14.08 0 0 0 0-5.12v-20.906667h22.613333a8.533333 8.533333 0 0 0 5.973333-0.853333zM810.901333 165.12a27.733333 27.733333 0 0 0 28.16 0 28.16 28.16 0 1 0-28.16 0zM876.608 853.333333h-16.64v-12.8a6.826667 6.826667 0 0 0 0-3.84 3.84 3.84 0 0 0-2.56-2.56 6.826667 6.826667 0 0 0-3.84 0 5.12 5.12 0 0 0-3.84 0 5.546667 5.546667 0 0 0-2.986667 2.56 12.373333 12.373333 0 0 0 0 3.84v12.8h-16.64l-2.986666 2.986667a10.24 10.24 0 0 0 0 3.84 12.373333 12.373333 0 0 0 0 3.84l2.986666 2.986667h16.64v12.8a12.373333 12.373333 0 0 0 0 3.84 5.546667 5.546667 0 0 0 2.986667 2.56 5.12 5.12 0 0 0 3.84 0 6.826667 6.826667 0 0 0 3.84 0 3.84 3.84 0 0 0 2.56-2.56 6.826667 6.826667 0 0 0 0-3.84v-12.8h16.64s2.133333 0 2.56-2.986667a6.826667 6.826667 0 0 0 0-3.84 5.12 5.12 0 0 0 0-3.84s-1.706667-2.986667-2.56-2.986667z" fill="#8081FF" />
          </svg>
          <span class="ah-topbar-brand">ASTRA<span class="ah-topbar-accent">HUB</span></span>
          <span class="ah-topbar-page-title" v-html="pageTitleMap[activeNav]"></span>
        </div>
        <div class="ah-topbar-right">
          <template v-if="activeNav === 'planetLinks'">
            <button v-for="t in planetFilters" :key="t.id" class="ah-topbar-tab" :class="{ active: planetFilter === t.id }" @click="planetFilter = t.id">{{ t.label }}</button>
            <div class="ah-topbar-search">
              <svg viewBox="0 0 24 24" fill="none" class="ah-topbar-search-icon" aria-hidden="true">
                <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2" />
                <path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
              </svg>
              <input v-model="planetSearch" type="search" class="ah-topbar-search-input" placeholder="搜索友链" />
            </div>
          </template>
          <template v-if="activeNav === 'friendManagement'">
            <button v-for="t in friendTabs" :key="t.id" class="ah-topbar-tab ah-topbar-tab--rel" :class="{ active: friendTab === t.id }" @click="friendTab = t.id">
              {{ t.label }}
              <span v-if="t.id === 'pending' && pendingInboxCount > 0" class="ah-tab-badge">{{ pendingInboxCount > 99 ? '99+' : pendingInboxCount }}</span>
            </button>
          </template>
          <template v-if="activeNav === 'news'">
            <div class="ah-topbar-search">
              <svg viewBox="0 0 24 24" fill="none" class="ah-topbar-search-icon" aria-hidden="true">
                <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2" />
                <path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
              </svg>
              <input v-model="newsSearch" type="search" class="ah-topbar-search-input" placeholder="搜索资讯" />
            </div>
          </template>
          <template v-if="activeNav === 'relationGraph'">
            <button class="ah-topbar-tab" @click="refreshRelationGraph">刷新</button>
          </template>
          <template v-if="activeNav === 'maintenance'">
            <button class="ah-topbar-tab active" @click="saveConnectionSettings">保存设置</button>
          </template>
        </div>
      </div>

      <!-- 内容体 -->
      <div class="ah-body">
        <div class="ah-content">
          <div v-if="loading" class="ah-loading">
            <div class="uv-loader"><span class="uv-loader-text">loading</span><span class="uv-load"></span></div>
          </div>

          <ConnectionPanel
            v-if="!loading && activeNav === 'maintenance'"
            :connection="connection"
            :credentials="credentials"
            :registered="registered"
            :hub-base-url="hubBaseUrl"
            :save-signal="connectionSaveSignal"
            @refresh="fetchStatus"
          />
          <PlanetLinksPanel v-if="!loading && activeNav === 'planetLinks'" :active-filter="planetFilter" :search-query="planetSearch" :realtime-event="friendRealtimeEvent" />
          <FriendInvitationPanel v-if="!loading && activeNav === 'friendManagement'" :active-tab="friendTab" :realtime-event="friendRealtimeEvent" @pending-inbox-remove="onPendingRemove" />
          <NewsHubPanel v-if="!loading && activeNav === 'news'" :search-query="newsSearch" />
          <RelationGraphPanel v-if="!loading && activeNav === 'relationGraph'" :refresh-signal="relationRefreshSignal" />
          <StarCommunicationsPanel v-if="!loading && activeNav === 'starComms'" :settings="worldChatSettings" :realtime-event="friendRealtimeEvent" />
        </div>
      </div>
    </div>
  </div>
</template>
