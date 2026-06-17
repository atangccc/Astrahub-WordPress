<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { usePlanetLinks, type PlanetLinkItem } from "../composables/usePlanetLinks";
import { HERO_MASCOT_DATA_URI } from "../data/heroMascot";
import {
  createFriendInvitation,
  fetchLinkGroups,
  removeFriendRelation,
  type FriendInvitationItem,
  type LinkGroupOption
} from "../api/friend";
import type { HubRealtimeEvent, HubSiteRelationUpdatedPayload } from "../composables/useFriendInvitationRealtime";
import { useStatus } from "../composables/useStatus";

const toast = ref<{ kind: "ok" | "warn" | "err"; text: string }>({ kind: "ok", text: "" });
function showToast(kind: "ok" | "warn" | "err", text: string) {
  toast.value = { kind, text };
  window.setTimeout(() => {
    if (toast.value.text === text) toast.value.text = "";
  }, 3500);
}
const Toast = {
  success: (text: string) => showToast("ok", text),
  warning: (text: string) => showToast("warn", text),
  error: (text: string) => showToast("err", text)
};

const props = defineProps<{
  activeFilter?: "all" | "mutual" | "following" | "pendingBack" | "favorites";
  searchQuery?: string;
  realtimeEvent?: HubRealtimeEvent<unknown> | null;
}>();

const { credentials, connection, hubBaseUrl } = useStatus();


const ROW_HEIGHT = 76;
const ROW_GAP = 8;
const OVERSCAN = 6;

const DEFAULT_AVATAR_DATA_URI = `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(`<svg viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg"><path d="M512 512m-512 0a512 512 0 1 0 1024 0 512 512 0 1 0-1024 0Z" fill="#1A4066"/><path d="M675.623007 719.427534H348.369612a169.86709 169.86709 0 0 0-169.859709 169.867089v11.026784a511.431685 511.431685 0 0 0 666.965432 0v-11.026784a169.86709 169.86709 0 0 0-169.852328-169.867089zM786.783912 461.892345a273.602998 273.602998 0 0 1-74.323771 187.912931H311.539859a274.776532 274.776532 0 1 1 475.244053-187.912931z" fill="#CBD5D8"/><path d="M727.738215 477.731354a215.125616 215.125616 0 0 1-48.631512 136.484128H344.9302A215.716073 215.716073 0 1 1 727.738215 477.731354z" fill="#0E243A"/><path d="M755.342079 684.612714a34.726251 34.726251 0 0 1-10.332997 24.629437 35.737408 35.737408 0 0 1-24.97633 10.185383H303.959867a35.110048 35.110048 0 0 1-35.375753-34.81482 34.549113 34.549113 0 0 1 10.406804-24.629436 35.641459 35.641459 0 0 1 24.97633-10.178002h416.072885a35.043621 35.043621 0 0 1 35.301946 34.807438z" fill="#AD382B"/><path d="M398.624881 487.761741l-51.664985-0.915208a218.779069 218.779069 0 0 1 1.476143-22.28237l51.288568 6.192418a188.222921 188.222921 0 0 0-1.099726 17.00516zM403.437105 451.699582L353.536111 438.244544a149.090385 149.090385 0 0 1 102.673086-106.666052l11.978896 50.26265-5.993138-25.131325 6.214559 25.094421a97.587776 97.587776 0 0 0-64.972409 69.895344z" fill="#CBD5D8"/><path d="M383.58299 780.554591m15.02713 0l226.77238 0q15.02713 0 15.02713 15.02713l0 119.973476q0 15.02713-15.02713 15.02713l-226.77238 0q-15.02713 0-15.02713-15.02713l0-119.973476q0-15.02713 15.02713-15.02713Z" fill="#F7F7F7"/><path d="M449.92083 855.572149m-36.822372 0a36.822373 36.822373 0 1 0 73.644745 0 36.822373 36.822373 0 1 0-73.644745 0Z" fill="#D8D8D8"/><path d="M449.92083 855.572149m-22.511172 0a22.511172 22.511172 0 1 0 45.022344 0 22.511172 22.511172 0 1 0-45.022344 0Z" fill="#C6817B"/></svg>`)}`;

const {
  loading,
  loadingMore,
  error,
  items,
  visibleItems,
  hasMore,
  fetchLinks,
  loadMore,
  markOutboxActive,
  setQuery
} = usePlanetLinks();

const favoriteUrls = ref<string[]>([]);

function isFavorite(item: PlanetLinkItem): boolean {
  return favoriteUrls.value.includes(item.url);
}

function toggleFavorite(item: PlanetLinkItem) {
  const urls = favoriteUrls.value;
  const idx = urls.indexOf(item.url);
  if (idx >= 0) {
    favoriteUrls.value = urls.filter((url) => url !== item.url);
  } else {
    favoriteUrls.value = [...urls, item.url];
  }
}

const orderedItems = computed(() => {

  const favorites: PlanetLinkItem[] = [];
  const rest: PlanetLinkItem[] = [];
  const pinned = favoriteUrls.value;
  for (const item of items.value) {
    if (pinned.includes(item.url)) {
      favorites.push(item);
    } else {
      rest.push(item);
    }
  }
  return favorites.length ? [...favorites, ...rest] : rest;
});

type RelationFilter = "all" | "mutual" | "following" | "pendingBack" | "favorites";

const relationFilter = computed<RelationFilter>(() => props.activeFilter || "all");

function relationKindOf(item: PlanetLinkItem) {
  return String(item.relationKind || "").trim().toLowerCase();
}

const filteredItems = computed(() => {

  return orderedItems.value.filter((item) => {
    if (isSelfLink(item)) {
      return relationFilter.value === "all";
    }
    if (relationFilter.value === "favorites") {
      return isFavorite(item);
    }
    return true;
  });
});


const relationParam = computed(() => {
  switch (relationFilter.value) {
    case "mutual":
      return "mutual";
    case "following":
      return "following";
    case "pendingBack":
      return "pendingBack";
    default:
      return "";
  }
});


const renderItems = computed(() => filteredItems.value.slice(0, visibleItems.value.length));

const scrollTop = ref(0);
const viewportHeight = ref(0);
const heroHeight = ref(0);

const listSpacerHeight = computed(() => {
  const count = renderItems.value.length;
  if (count <= 0) {
    return 0;
  }
  return Math.max(0, count * ROW_HEIGHT - ROW_GAP);
});

const visibleRange = computed(() => {
  const count = renderItems.value.length;
  if (count <= 0) {
    return { start: 0, end: 0 };
  }
  const innerScroll = Math.max(0, scrollTop.value - heroHeight.value);
  const rawStart = Math.floor(innerScroll / ROW_HEIGHT);
  const start = Math.max(0, rawStart - OVERSCAN);
  const visibleCount = Math.ceil(viewportHeight.value / ROW_HEIGHT);
  const end = Math.min(count, rawStart + visibleCount + OVERSCAN);
  return { start, end };
});

const windowedItems = computed(() => {
  const { start, end } = visibleRange.value;
  const list = renderItems.value;
  const result: Array<{ item: PlanetLinkItem; top: number }> = [];
  for (let i = start; i < end; i++) {
    result.push({ item: list[i], top: i * ROW_HEIGHT });
  }
  return result;
});

const invitingTargets = ref<string[]>([]);
const inviteDialogVisible = ref(false);
const inviteTarget = ref<PlanetLinkItem | null>(null);
const inviteMessage = ref("");
const inviteLinkGroupName = ref("");
const inviteLinkGroups = ref<LinkGroupOption[]>([]);
const inviteGroupDropdownOpen = ref(false);

const removingTargets = ref<string[]>([]);
const removeDialogVisible = ref(false);
const removeTarget = ref<PlanetLinkItem | null>(null);
const removeReason = ref("");

const SIMPLE_STATUS_MAP = {
  relation: {
    self: "我的站点",
    mutual: "互相关注",
    oneWayOut: "我已关注",
    oneWayIn: "他已关注",
    invitable: "可发起邀请",
    sentInvite: "已邀请",
    none: "没有关系",
    unknown: "暂未接入"
  }
} as const;

async function reload(options?: { silent?: boolean }) {

  setQuery({ keyword: props.searchQuery || "", relation: relationParam.value });
  await fetchLinks(options);
}

function displayHost(rawUrl: string) {
  const value = String(rawUrl || "").trim();
  if (!value) {
    return "-";
  }
  try {
    return new URL(value).host || value;
  } catch {
    return value;
  }
}

function externalLinkHref(rawUrl: string) {
  const value = String(rawUrl || "").trim();
  if (!value) {
    return "";
  }
  return /^https?:\/\//i.test(value) ? value : `https://${value}`;
}

function galaxyName(item: PlanetLinkItem) {
  return String(item.galaxyName || "").trim();
}

function galaxyDisplayName(item: PlanetLinkItem) {
  const name = galaxyName(item);
  if (!name) return "";
  const suffix = "星系";
  const base = name.endsWith(suffix) ? name.slice(0, -suffix.length).trim() : name;
  const chars = Array.from(base);
  const displayBase = chars.length > 10 ? `${chars.slice(0, 10).join("")}...` : base;
  return `${displayBase} ${suffix}`;
}

function sourceSiteCount(item: PlanetLinkItem) {
  const count = Number(item.sourceSiteCount || 0);
  return Number.isFinite(count) && count > 0 ? Math.floor(count) : 0;
}

function hotRank(item: PlanetLinkItem) {
  const rank = Number(item.hotRank || 0);
  return Number.isFinite(rank) && rank > 0 ? Math.floor(rank) : 0;
}

function hotRankTooltip(item: PlanetLinkItem) {
  const rank = hotRank(item);
  const prefix = rank > 0 ? `当前星系排行第 ${rank} 名。` : "当前暂无星系排行。";
  return `${prefix}计算方法：关联星系数 x 40% + 本星系下星球数 x 20% + 被邀请数（同意和拒绝）x 40%，按总分从高到低排序。`;
}

function hotRankClass(item: PlanetLinkItem) {
  const rank = hotRank(item);
  if (rank === 1) return "rank-gold";
  if (rank === 2) return "rank-silver";
  if (rank === 3) return "rank-bronze";
  return "rank-normal";
}

function shortDescription(value: string) {
  const text = String(value || "").trim();
  if (!text) return "暂无简介";
  const chars = Array.from(text);
  return chars.length > 15 ? `${chars.slice(0, 15).join("")}...` : text;
}

function formatUpdatedAt(value: string) {
  const raw = String(value || "").trim();
  if (!raw) {
    return "-";
  }
  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) {
    return raw;
  }
  return date.toLocaleString("zh-CN", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit"
  });
}

function currentSiteId() {
  return String(credentials.value.siteId || "").trim();
}

function comparableSiteRoot(rawUrl: string) {
  const value = String(rawUrl || "").trim();
  if (!value) {
    return "";
  }
  try {
    const parsed = new URL(value);
    return `${parsed.protocol}//${parsed.host}`.replace(/\/+$/, "").toLowerCase();
  } catch {
    return normalizeComparableUrl(value);
  }
}

function sameComparableSiteUrl(leftRawUrl: string, rightRawUrl: string) {
  const left = normalizeComparableUrl(leftRawUrl);
  const right = normalizeComparableUrl(rightRawUrl);
  if (left && right && left === right) {
    return true;
  }
  const leftRoot = comparableSiteRoot(leftRawUrl);
  const rightRoot = comparableSiteRoot(rightRawUrl);
  return Boolean(leftRoot) && Boolean(rightRoot) && leftRoot === rightRoot;
}

function isSelfLink(item: PlanetLinkItem) {
  if (String(item.relationStatus || "").trim() === "self") {
    return true;
  }
  if (String(item.targetInvitationState || "").trim() === "self_site") {
    return true;
  }
  const selfSiteId = currentSiteId();
  const targetSiteId = String(item.targetSiteId || "").trim();
  if (selfSiteId && targetSiteId && selfSiteId === targetSiteId) {
    return true;
  }
  return sameComparableSiteUrl(connection.value.siteUrl, item.url);
}

function normalizeComparableUrl(rawUrl: string) {
  return String(rawUrl || "").trim().replace(/\/+$/, "").toLowerCase();
}

function hasLocalLink(item: PlanetLinkItem) {
  const kind = relationKindOf(item);
  return kind === "mutual" || kind === "one_way_out";
}

function invitationStateText(invitationState: string) {
  switch (String(invitationState || "").trim()) {
    case "self_site":
      return "本站";
    case "site_not_found":
      return "尚未注册";
    case "site_id_missing":
      return "缺少编号";
    case "credential_missing":
      return "凭据缺失";
    case "site_inactive":
      return "尚未激活";
    case "contact_email_missing":
      return "缺少邮箱";
    case "invalid_site_url":
      return "地址无效";
    default:
      return "不可邀请";
  }
}

function invitationStateTone(invitationState: string) {
  switch (String(invitationState || "").trim()) {
    case "self_site":
      return "self";
    case "site_not_found":
    case "site_id_missing":
      return "unregistered";
    case "credential_missing":
      return "credential";
    case "site_inactive":
      return "inactive";
    case "contact_email_missing":
      return "warning";
    case "invalid_site_url":
      return "warning";
    default:
      return "muted";
  }
}

function relationSummary(item: PlanetLinkItem) {
  if (isSelfLink(item)) {
    return { text: SIMPLE_STATUS_MAP.relation.self, tone: "muted" };
  }

  const status = String(item.relationStatus || "").trim();
  switch (status) {
    case "self":
      return { text: SIMPLE_STATUS_MAP.relation.self, tone: "self" };
    case "mutual":
      return { text: SIMPLE_STATUS_MAP.relation.mutual, tone: "mutual" };
    case "following":
      return { text: SIMPLE_STATUS_MAP.relation.oneWayOut, tone: "one-way-out" };
    case "follower":
      return { text: SIMPLE_STATUS_MAP.relation.oneWayIn, tone: "one-way-in" };
    case "invite_sent":
      return { text: SIMPLE_STATUS_MAP.relation.sentInvite, tone: "pending" };
    case "invitable":
      return { text: SIMPLE_STATUS_MAP.relation.invitable, tone: "muted" };
    case "none":

      return item.targetRegistered
        ? { text: SIMPLE_STATUS_MAP.relation.none, tone: "muted" }
        : { text: SIMPLE_STATUS_MAP.relation.unknown, tone: "muted" };
  }


  const relationKind = relationKindOf(item);
  if (relationKind === "mutual") {
    return { text: SIMPLE_STATUS_MAP.relation.mutual, tone: "mutual" };
  }
  if (relationKind === "one_way_out") {
    return { text: SIMPLE_STATUS_MAP.relation.oneWayOut, tone: "one-way-out" };
  }
  if (relationKind === "one_way_in") {
    return { text: SIMPLE_STATUS_MAP.relation.oneWayIn, tone: "one-way-in" };
  }
  if (item.outboxInvitationActive) {
    return { text: SIMPLE_STATUS_MAP.relation.sentInvite, tone: "pending" };
  }
  if (!item.targetRegistered) {
    return { text: SIMPLE_STATUS_MAP.relation.unknown, tone: "muted" };
  }
  return { text: SIMPLE_STATUS_MAP.relation.none, tone: "muted" };
}

function hasActiveOutboxInvitation(item: PlanetLinkItem) {
  return Boolean(item.outboxInvitationActive);
}

function canInvite(item: PlanetLinkItem) {
  return (
    !isSelfLink(item) &&
    Boolean(item.targetRegistered) &&
    Boolean(item.targetSupportsInvitation) &&
    !hasLocalLink(item) &&
    !hasActiveOutboxInvitation(item) &&
    Boolean(String(item.url || "").trim())
  );
}

function isInviting(item: PlanetLinkItem) {
  const targetUrl = normalizeComparableUrl(item.url);
  return invitingTargets.value.includes(targetUrl);
}

function inviteButtonText(item: PlanetLinkItem) {
  if (isSelfLink(item)) {
    return "本站";
  }
  const invitationState = String(item.targetInvitationState || "").trim();
  if (!item.targetRegistered) {
    return invitationStateText(invitationState);
  }
  if (!item.targetSupportsInvitation) {
    return invitationStateText(invitationState);
  }
  if (isInviting(item)) {
    return "正在邀请";
  }
  if (hasActiveOutboxInvitation(item)) {
    return "已邀请";
  }
  if (!canInvite(item)) {
    return invitationStateText(invitationState);
  }
  return "邀请";
}

function inviteButtonTone(item: PlanetLinkItem) {
  if (isSelfLink(item)) {
    return "plain";
  }
  const invitationState = String(item.targetInvitationState || "").trim();
  if (!item.targetRegistered) {
    return invitationStateTone(invitationState);
  }
  if (!item.targetSupportsInvitation) {
    return invitationStateTone(invitationState);
  }
  if (isInviting(item)) {
    return "loading";
  }
  if (hasLocalLink(item)) {
    return "linked";
  }
  if (hasActiveOutboxInvitation(item)) {
    return "pending";
  }
  if (!canInvite(item)) {
    return invitationStateTone(invitationState);
  }
  return "action";
}

function rowTone(item: PlanetLinkItem) {
  return isSelfLink(item) || Boolean(item.targetRegistered) ? "linked" : inviteButtonTone(item);
}

async function inviteLink(item: PlanetLinkItem) {
  try {
    inviteLinkGroups.value = await fetchLinkGroups();
  } catch (e) {
    inviteLinkGroups.value = [];
    Toast.error(e instanceof Error ? e.message : "读取友链分组失败");
  }
  inviteLinkGroupName.value = "";
  inviteGroupDropdownOpen.value = false;
  inviteTarget.value = item;
  inviteMessage.value = "";
  inviteDialogVisible.value = true;
}

function closeInviteDialog() {
  inviteDialogVisible.value = false;
  inviteTarget.value = null;
  inviteMessage.value = "";
  inviteLinkGroupName.value = "";
  inviteGroupDropdownOpen.value = false;
}

function toggleInviteGroupDropdown() {
  inviteGroupDropdownOpen.value = !inviteGroupDropdownOpen.value;
}

function selectInviteGroup(groupName: string) {
  inviteLinkGroupName.value = groupName;
  inviteGroupDropdownOpen.value = false;
}

function selectedInviteGroupLabel() {
  if (!inviteLinkGroupName.value) {
    return "不预设分组";
  }
  return inviteLinkGroups.value.find((group) => group.name === inviteLinkGroupName.value)?.displayName || "不预设分组";
}

async function submitInvite() {
  const item = inviteTarget.value;
  if (!item) {
    return;
  }
  const targetUrl = normalizeComparableUrl(item.url);
  if (!targetUrl) {
    Toast.warning("当前友链没有可邀请的目标地址");
    return;
  }
  if (isSelfLink(item)) {
    Toast.warning("不能邀请当前站点自己");
    return;
  }
  if (invitingTargets.value.includes(targetUrl)) {
    return;
  }

  invitingTargets.value = [...invitingTargets.value, targetUrl];
  try {
    const siteId = String(item.targetSiteId || "").trim();
    if (!siteId) {
      Toast.warning("该站点未生成 AstraHub 站点标识，当前不能邀请");
      return;
    }

    await createFriendInvitation(siteId, inviteMessage.value.trim(), inviteLinkGroupName.value.trim());
    markOutboxActive(item.url);
    closeInviteDialog();
    Toast.success("已向该站点发起 AstraHub 邀请");
  } catch (e) {
    Toast.error(e instanceof Error ? e.message : "友链邀请失败");
  } finally {
    invitingTargets.value = invitingTargets.value.filter((url) => url !== targetUrl);
  }
}

function canRemoveRelation(item: PlanetLinkItem) {
  if (isSelfLink(item)) {
    return false;
  }
  if (!item.targetRegistered || !item.targetSupportsInvitation) {
    return false;
  }
  if (!hasLocalLink(item)) {
    return false;
  }
  if (isInviting(item)) {
    return false;
  }
  return true;
}

function isRemoving(item: PlanetLinkItem) {
  const id = String(item.targetSiteId || "").trim();
  return id !== "" && removingTargets.value.includes(id);
}

function openRemoveDialog(item: PlanetLinkItem) {
  if (!canRemoveRelation(item)) {
    return;
  }
  removeTarget.value = item;
  removeReason.value = "";
  removeDialogVisible.value = true;
}

function closeRemoveDialog() {
  if (removeTarget.value && isRemoving(removeTarget.value)) {
    return;
  }
  removeDialogVisible.value = false;
  removeTarget.value = null;
  removeReason.value = "";
}

async function submitRemove() {
  const item = removeTarget.value;
  if (!item) return;
  const peerSiteId = String(item.targetSiteId || "").trim();
  if (!peerSiteId) {
    Toast.warning("缺少对端站点编号，无法解除");
    return;
  }
  if (removingTargets.value.includes(peerSiteId)) {
    return;
  }

  removingTargets.value = [...removingTargets.value, peerSiteId];
  try {
    const result = await removeFriendRelation(peerSiteId, removeReason.value);
    if (result.removed) {
      Toast.success("已解除友链关系");
    } else {
      Toast.success("鍏崇郴宸茶В闄わ紙鏃犲彉鍖栵級");
    }
    removeDialogVisible.value = false;
    removeTarget.value = null;
    removeReason.value = "";

    scheduleSilentReload();
  } catch (e) {
    Toast.error(e instanceof Error ? e.message : "解除友链关系失败");
  } finally {
    removingTargets.value = removingTargets.value.filter((id) => id !== peerSiteId);
  }
}

const isScrolling = ref(false);
let scrollEndTimer: ReturnType<typeof setTimeout> | null = null;
const scrollWrapEl = ref<HTMLElement | null>(null);
const heroEl = ref<HTMLElement | null>(null);

function onScroll(event: Event) {
  isScrolling.value = true;
  if (scrollEndTimer) {
    clearTimeout(scrollEndTimer);
  }
  scrollEndTimer = setTimeout(() => {
    isScrolling.value = false;
    scrollEndTimer = null;
  }, 150);

  const target = event.target as HTMLElement | null;
  if (!target) {
    return;
  }

  scrollTop.value = target.scrollTop;
  if (viewportHeight.value !== target.clientHeight) {
    viewportHeight.value = target.clientHeight;
  }
  if (heroEl.value) {
    heroHeight.value = heroEl.value.offsetHeight;
  }

  if (loading.value || loadingMore.value || !hasMore.value) {
    return;
  }
  const distanceToBottom = target.scrollHeight - target.scrollTop - target.clientHeight;
  if (distanceToBottom < 80) {
    void loadMore();
  }
}

function measureViewport() {
  const wrap = scrollWrapEl.value;
  if (wrap) {
    viewportHeight.value = wrap.clientHeight;
    scrollTop.value = wrap.scrollTop;
  }
  if (heroEl.value) {
    heroHeight.value = heroEl.value.offsetHeight;
  }
}

function onViewportResize() {
  measureViewport();
}

function handleDocumentClick(event: MouseEvent) {
  const target = event.target;
  if (!(target instanceof Element)) {
    return;
  }
  if (!target.closest(".invite-selectbox")) {
    inviteGroupDropdownOpen.value = false;
  }
}

let resizeObserver: ResizeObserver | null = null;

onMounted(() => {
  void reload();
  document.addEventListener("click", handleDocumentClick);
  window.addEventListener("resize", onViewportResize);

  const wrap = scrollWrapEl.value;
  if (wrap && typeof ResizeObserver !== "undefined") {
    resizeObserver = new ResizeObserver(() => onViewportResize());
    resizeObserver.observe(wrap);
  }
  requestAnimationFrame(measureViewport);
});


let realtimeReloadTimer: ReturnType<typeof setTimeout> | null = null;
const realtimeReloadTimers: Array<ReturnType<typeof setTimeout>> = [];
function scheduleSilentReload() {
  if (realtimeReloadTimer) {
    clearTimeout(realtimeReloadTimer);
  }
  while (realtimeReloadTimers.length) {
    const t = realtimeReloadTimers.pop();
    if (t) clearTimeout(t);
  }
  for (const delay of [500, 2000, 5000]) {
    const timer = setTimeout(() => {
      void reload({ silent: true });
    }, delay);
    realtimeReloadTimers.push(timer);
  }
  realtimeReloadTimer = realtimeReloadTimers[0];
}

function applyRealtimeEvent(event: HubRealtimeEvent<unknown>) {
  const myId = currentSiteId();
  if (!myId) {
    return;
  }
 
  if (event.type === "friend_relation_removed") {
    const data = (event.data || {}) as {
      actorSiteId?: string;
      peerSiteId?: string;
    };
    const actorId = String(data.actorSiteId || "").trim();
    const peerId = String(data.peerSiteId || "").trim();
    if (actorId !== myId && peerId !== myId) {
      return;
    }
    scheduleSilentReload();
    return;
  }

  if (event.type === "site_relation_updated") {
    const data = (event.data || {}) as HubSiteRelationUpdatedPayload;
    const sourceId = String(data.sourceSiteId || "").trim();
    const impacted = Array.isArray(data.impactedSiteIds) ? data.impactedSiteIds : [];
    let touched = sourceId === myId;
    if (!touched) {
      for (const raw of impacted) {
        if (String(raw || "").trim() === myId) {
          touched = true;
          break;
        }
      }
    }
    if (!touched) {
      return;
    }
    scheduleSilentReload();
    return;
  }
 
  const invitation = event.data as FriendInvitationItem | undefined;
  if (!invitation) return;
  const fromSiteId = String(invitation.fromSite?.siteId || "").trim();
  const toSiteId = String(invitation.toSite?.siteId || "").trim();
  if (fromSiteId !== myId && toSiteId !== myId) {
    return;
  }
  scheduleSilentReload();
}

watch(
  () => props.realtimeEvent,
  (event) => {
    if (event) {
      applyRealtimeEvent(event);
    }
  }
);

onBeforeUnmount(() => {
  document.removeEventListener("click", handleDocumentClick);
  window.removeEventListener("resize", onViewportResize);
  if (resizeObserver) {
    resizeObserver.disconnect();
    resizeObserver = null;
  }
  if (scrollEndTimer) {
    clearTimeout(scrollEndTimer);
    scrollEndTimer = null;
  }
  if (realtimeReloadTimer) {
    clearTimeout(realtimeReloadTimer);
    realtimeReloadTimer = null;
  }
  if (searchReloadTimer) {
    clearTimeout(searchReloadTimer);
    searchReloadTimer = null;
  }
});

watch(
  () => loading.value,
  (isLoading) => {

    if (!isLoading) {
      requestAnimationFrame(measureViewport);
    }
  }
);

watch(
  () => hubBaseUrl.value,
  () => {
    void reload();
  }
);

watch(
  () => credentials.value.siteId,
  () => {
    void reload();
  }
);

watch(
  () => props.activeFilter,
  () => {

    void reload();
    const wrap = scrollWrapEl.value;
    const hero = heroEl.value;
    if (!wrap || !hero) {
      return;
    }
    wrap.scrollTo({ top: hero.offsetHeight, behavior: "smooth" });
  }
);

let searchReloadTimer: ReturnType<typeof setTimeout> | null = null;
watch(
  () => props.searchQuery,
  () => {
    if (searchReloadTimer) {
      clearTimeout(searchReloadTimer);
    }
    searchReloadTimer = setTimeout(() => {
      searchReloadTimer = null;
      void reload();
    }, 300);
  }
);
</script>

<template>
  <div class="planet-links-wrap">
    <div class="pl-main-content">
      <div ref="scrollWrapEl" class="planet-links-table-wrap" :class="{ 'is-scrolling': isScrolling }" @scroll="onScroll">
        <div v-if="loading" class="loading-overlay">
          <div class="uv-loader"><span class="uv-loader-text">loading</span><span class="uv-load"></span></div>
        </div>

        <section ref="heroEl" class="planet-hero">
          <img
            :src="HERO_MASCOT_DATA_URI"
            alt=""
            aria-hidden="true"
            class="planet-hero-mascot"
            draggable="false"
          />
          <div class="planet-hero-scroll-hint" aria-hidden="true">
            <span class="planet-hero-scroll-text">下滑探索星球</span>
            <svg viewBox="0 0 24 24" fill="none" class="planet-hero-scroll-icon">
              <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </div>
          <div class="planet-hero-inner">
            <h2 class="planet-hero-title">
              探索
              <br />
              <span class="planet-hero-title-accent">创作者星系</span>
            </h2>
            <p class="planet-hero-desc">
              欢迎来到 AstraHub 生态系统的中央情报枢纽。在这里，每一个博客都是一颗发光恒星，
              通过通用数字协议互联，共同构建辽阔的知识银河。
            </p>
          </div>
        </section>

        <div class="planet-links-table">
          <div v-if="error" class="planet-links-empty">
            <div class="sp-empty-state">
              <div class="sp-empty-state-text">{{ error }}</div>
              <div class="sp-empty-state-hint">请检查 Hub 地址配置或网络连接</div>
            </div>
          </div>

          <div v-else-if="!loading && !renderItems.length" class="planet-links-empty">
            <div class="sp-empty-state">
              <div class="sp-empty-state-text">暂无可展示的友链数据</div>
              <div class="sp-empty-state-hint">接入星链并完成同步后，友链数据将在此展示</div>
            </div>
          </div>

          <div
            v-else
            class="planet-links-virtual"
            :style="{ height: listSpacerHeight + 'px' }"
          >
            <div
              v-for="row in windowedItems"
              :key="row.item.url"
              class="planet-links-vrow" 
              :style="{ transform: `translateY(${row.top}px)` }"
            >
              <div class="planet-links-row"
                :class="`planet-links-row--${rowTone(row.item)}`"
              >
                <div class="link-main">
                  <span
                    class="hot-rank-badge"
                    :class="hotRankClass(row.item)"
                    :title="hotRankTooltip(row.item)"
                    :aria-label="hotRankTooltip(row.item)"
                  >
                    <svg class="hot-rank-icon" viewBox="0 0 1024 1024" aria-hidden="true">
                      <path d="M984.064 459.776a115.7632 115.7632 0 0 0-124.928-38.741333l-161.28 49.322666-57.685333-113.322666a143.291733 143.291733 0 0 0 36.352-95.914667c0-79.872-65.024-144.896-144.896-144.896s-144.896 65.024-144.896 144.896c0 36.010667 12.970667 69.632 36.352 95.914667l-57.685334 113.322666-161.28-49.322666c-45.909333-14.165333-94.890667 1.194667-124.928 38.741333-29.866667 37.546667-33.792 88.746667-9.557333 130.901333l115.541333 191.829334c9.728 16.213333 30.72 21.333333 46.933334 11.605333s21.333333-30.72 11.605333-46.933333l-115.2-191.146667c-15.018667-26.282667-0.682667-47.786667 4.096-53.76 4.778667-5.973333 22.528-24.746667 51.541333-15.872l188.586667 57.685333c15.872 4.778667 32.938667-2.389333 40.448-17.066666l82.432-161.962667c7.509333-14.848 3.413333-32.768-9.898667-42.837333a76.219733 76.219733 0 0 1-30.72-61.098667c0-42.154667 34.304-76.629333 76.629334-76.629333 42.154667 0 76.629333 34.304 76.629333 76.629333 0 24.234667-11.264 46.421333-30.72 61.098667a34.133333 34.133333 0 0 0-9.898667 42.837333l82.432 161.962667c7.509333 14.848 24.576 22.016 40.448 17.066666l188.586667-57.685333c29.184-8.874667 46.762667 9.898667 51.541333 15.872 4.778667 5.973333 19.114667 27.477333 3.925334 53.930667L734.037333 909.312H262.826667c-18.773333 0-34.133333 15.36-34.133334 34.133333s15.36 34.133333 34.133334 34.133334h491.178666c12.288 0 23.552-6.656 29.696-17.237334l210.432-370.346666a115.882667 115.882667 0 0 0-10.069333-130.218667z" />
                    </svg>
                    <span class="hot-rank-number">{{ hotRank(row.item) || "-" }}</span>
                  </span>
                  <img v-if="row.item.logo" :src="row.item.logo" alt="" class="link-logo" @error="($event.target as HTMLImageElement).src = DEFAULT_AVATAR_DATA_URI" />
                  <div v-else class="link-logo link-logo-fallback">
                    <img :src="DEFAULT_AVATAR_DATA_URI" alt="" class="link-logo" />
                  </div>
                  <div class="link-main-text">
                    <div class="link-title-row">
                      <div class="link-title">{{ row.item.title || row.item.url }}</div>
                    </div>
                    <div class="link-desc" :title="row.item.description || ''">{{ shortDescription(row.item.description) }}</div>
                  </div>
                </div>

                <div class="galaxy-name-cell">
                  <span v-if="galaxyDisplayName(row.item)" class="galaxy-name">{{ galaxyDisplayName(row.item) }}</span>
                  <span v-else class="galaxy-empty">-</span>
                </div>

                <div class="galaxy-count-cell">星系{{ sourceSiteCount(row.item) }}</div>

                <div class="relation-text">
                  <span class="relation-pill relation-summary-pill" :class="relationSummary(row.item).tone">
                    {{ relationSummary(row.item).text }}
                  </span>
                </div>

                <a
                  v-if="externalLinkHref(row.item.url)"
                  class="link-url link-url-anchor"
                  :href="externalLinkHref(row.item.url)"
                  target="_blank"
                  rel="noopener noreferrer"
                  @click.stop
                >{{ displayHost(row.item.url) }}</a>
                <div v-else class="link-url">{{ displayHost(row.item.url) }}</div>

                <div class="rss-time">{{ formatUpdatedAt(row.item.updatedAt) }}</div>

                <div class="action-cell">
                  <button
                    class="fav-btn"
                    :class="{ 'fav-btn--active': isFavorite(row.item) }"
                    :title="isFavorite(row.item) ? '取消收藏' : '收藏'"
                    @click.stop="toggleFavorite(row.item)"
                  >
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 2.8l2.86 5.8 6.4.93-4.63 4.51 1.09 6.37L12 17.4l-5.72 3.01 1.09-6.37-4.63-4.51 6.4-.93L12 2.8z" :fill="isFavorite(row.item) ? '#FCD62C' : '#d1d5db'" /></svg>
                  </button>
                  <button
                    v-if="canRemoveRelation(row.item) || isRemoving(row.item)"
                    class="invite-btn invite-btn--remove"
                    :disabled="isRemoving(row.item)"
                    @click.stop="openRemoveDialog(row.item)"
                  >
                    {{ isRemoving(row.item) ? "处理中..." : "删除" }}
                  </button>
                  <button
                    v-else-if="isSelfLink(row.item)"
                    class="invite-btn invite-btn--plain"
                    disabled
                  >
                    {{ inviteButtonText(row.item) }}
                  </button>
                  <button
                    v-else
                    class="invite-btn"
                    :class="`invite-btn--${inviteButtonTone(row.item)}`"
                    :disabled="!canInvite(row.item) || isInviting(row.item)"
                    @click="inviteLink(row.item)"
                  >
                    {{ inviteButtonText(row.item) }}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div v-if="hasMore" class="planet-links-more">
            上滑继续加载更多友链
          </div>
        </div>
      </div>

    <div v-if="inviteDialogVisible" class="invite-mask" @click.self="closeInviteDialog">
      <div class="invite-dialog">
        <div class="invite-dialog-title">发起站点邀请</div>
        <div class="invite-dialog-sub">
          {{ inviteTarget?.title || inviteTarget?.url || "-" }}
        </div>

        <div class="invite-field">
          <label class="invite-label">友链分组</label>
          <div class="invite-selectbox">
            <button type="button" class="invite-select-trigger" @click.stop="toggleInviteGroupDropdown">
              <span>{{ selectedInviteGroupLabel() }}</span>
              <svg
                viewBox="0 0 20 20"
                fill="none"
                class="invite-select-arrow"
                :class="{ open: inviteGroupDropdownOpen }"
              >
                <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </button>
            <div v-if="inviteGroupDropdownOpen" class="invite-select-menu">
              <button
                type="button"
                class="invite-select-option"
                :class="{ active: !inviteLinkGroupName }"
                @click.stop="selectInviteGroup('')"
              >
                <span>不预设分组</span>
                <span class="invite-option-hint">手动</span>
              </button>
              <button
                v-for="group in inviteLinkGroups"
                :key="group.name"
                type="button"
                class="invite-select-option"
                :class="{ active: inviteLinkGroupName === group.name }"
                @click.stop="selectInviteGroup(group.name)"
              >
                <span>{{ group.displayName }}</span>
              </button>
            </div>
          </div>
          <div class="invite-field-hint">不预设时，由对方在审核时手动选择分组。</div>
        </div>

        <div class="invite-field">
          <label class="invite-label">留言</label>
          <textarea
            v-model="inviteMessage"
            class="invite-textarea"
            placeholder="可选，给对方留一句话"
          ></textarea>
        </div>

        <div class="invite-actions">
          <button class="invite-btn-ghost" :disabled="inviteTarget ? isInviting(inviteTarget) : false" @click="closeInviteDialog">
            取消
          </button>
          <button class="invite-btn-primary" :disabled="inviteTarget ? isInviting(inviteTarget) : true" @click="submitInvite">
            {{ inviteTarget && isInviting(inviteTarget) ? "发送中..." : "确认发送" }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="removeDialogVisible" class="invite-mask" @click.self="closeRemoveDialog">
      <div class="invite-dialog">
        <div class="invite-dialog-title">解除友链关系</div>
        <div class="invite-dialog-sub">
          {{ removeTarget?.title || removeTarget?.url || "-" }}
        </div>

        <p class="remove-warning">
          解除后将立即删除本站对该友链的本地链接，并通过邮件通知对方。此操作不可恢复。
        </p>

        <div class="invite-field">
          <label class="invite-label">解除原因（可选）</label>
          <textarea
            v-model="removeReason"
            class="invite-textarea"
            placeholder="留空则邮件中不展示原因"
            maxlength="300"
          ></textarea>
        </div>

        <div class="invite-actions">
          <button
            class="invite-btn-ghost"
            :disabled="removeTarget ? isRemoving(removeTarget) : false"
            @click="closeRemoveDialog"
          >
            取消
          </button>
          <button
            class="invite-btn-primary invite-btn-primary--danger"
            :disabled="removeTarget ? isRemoving(removeTarget) : true"
            @click="submitRemove"
          >
            {{ removeTarget && isRemoving(removeTarget) ? "解除中..." : "确认解除" }}
          </button>
        </div>
      </div>
    </div>

    </div>
  </div>
</template>

<style scoped>
.planet-links-wrap{flex:1;display:flex;flex-direction:column;padding:16px 20px;overflow-y:auto;position:relative}
.pl-main-content{flex:1;display:flex;flex-direction:column;min-height:0;position:relative}
.planet-links-table-wrap{position:relative;flex:1;min-height:0;overflow:auto;scrollbar-width:none;-ms-overflow-style:none}
.planet-links-table-wrap::-webkit-scrollbar{display:none}
.planet-links-table-wrap.is-scrolling .planet-links-row,.planet-links-table-wrap.is-scrolling .invite-btn{pointer-events:none}
.planet-hero{position:relative;display:flex;align-items:center;justify-content:center;min-height:100%;padding:40px 24px 56px;box-sizing:border-box;margin-bottom:16px}
.planet-hero-mascot{position:absolute;right:calc(50% + 240px);left:auto;top:50%;transform:translateY(-54%);z-index:0;width:clamp(160px,18vw,300px);height:auto;object-fit:contain;opacity:.92;pointer-events:none;user-select:none;-webkit-user-drag:none}
@media (max-width:1100px){.planet-hero-mascot{display:none}}
.planet-hero-inner{position:relative;z-index:1;max-width:680px;width:100%;display:flex;flex-direction:column;align-items:center;text-align:center;gap:24px}
.planet-hero-title{margin:0;font-family:"STHupo","鍗庢枃鐞ョ弨","Chalkboard SE","Yuanti SC","STYuanti","鍗庢枃鍦嗕綋","Comic Sans MS","Microsoft YaHei UI","PingFang SC",system-ui,sans-serif;font-size:clamp(40px,7vw,76px);line-height:1.2;font-weight:normal;letter-spacing:.04em;color:#0f172a;padding-bottom:8px;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.planet-hero-title-accent{display:inline-block;background:linear-gradient(90deg,#38bdf8 0%,#a78bfa 50%,#f472b6 100%);-webkit-background-clip:text;background-clip:text;color:transparent;padding:0 .08em .12em .08em}
.planet-hero-desc{margin:0;max-width:560px;font-size:15px;line-height:1.75;color:#64748b;letter-spacing:.01em}
.planet-hero-scroll-hint{position:absolute;bottom:16px;left:50%;transform:translateX(-50%);display:flex;flex-direction:column;align-items:center;gap:4px;color:#64748b;font-size:12px;font-weight:600;z-index:2}
.planet-hero-scroll-icon{width:18px;height:18px;color:#64748b;animation:planet-hero-bounce 2.4s ease-in-out infinite}
@keyframes planet-hero-bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(4px)}}
.planet-links-table{display:flex;flex-direction:column;min-width:0;min-height:100%;gap:8px;padding:4px 0}

.planet-links-virtual{position:relative;width:100%}
.planet-links-vrow{position:absolute;left:0;right:0;top:0;height:68px;will-change:transform}
.planet-links-row{display:grid;grid-template-columns:minmax(240px,1.7fr) minmax(130px,.9fr) 82px 110px minmax(140px,.9fr) minmax(128px,.8fr) 126px;gap:10px;align-items:center;height:100%;box-sizing:border-box;padding:10px 14px;border-radius:20px;background:transparent;border:1px solid rgba(0,0,0,.05);box-shadow:0 2px 8px rgba(0,0,0,.03);overflow:hidden}
.planet-links-row:hover{box-shadow:0 4px 14px rgba(0,0,0,.06)}
.planet-links-row--action{border-color:rgba(147,197,253,.4)}
.planet-links-row--linked{background:linear-gradient(90deg,rgba(236,253,245,.9),rgba(220,252,231,.72) 60%,rgba(209,250,229,.72))}
.planet-links-row--pending{background:linear-gradient(90deg,rgba(255,247,237,.9),rgba(255,243,224,.72) 60%,rgba(255,237,213,.72))}
.planet-links-row--unregistered{background:linear-gradient(90deg,rgba(248,250,252,.94),rgba(241,245,249,.78) 60%,rgba(226,232,240,.72))}
.planet-links-row--credential{background:linear-gradient(90deg,rgba(245,243,255,.92),rgba(243,240,255,.74) 60%,rgba(237,233,254,.74))}
.planet-links-row--inactive{background:linear-gradient(90deg,rgba(254,242,242,.92),rgba(254,235,235,.74) 60%,rgba(254,226,226,.74))}
.planet-links-row--warning{background:linear-gradient(90deg,rgba(255,251,235,.92),rgba(254,249,221,.74) 60%,rgba(254,243,199,.74))}
.planet-links-row--loading{background:linear-gradient(90deg,rgba(239,246,255,.92),rgba(228,240,255,.74) 60%,rgba(219,234,254,.74))}
.planet-links-row--muted{background:linear-gradient(90deg,rgba(255,241,242,.92),rgba(255,235,236,.74) 60%,rgba(255,228,230,.74))}
.planet-links-row:last-child{border-bottom:none}
.link-main{display:flex;align-items:center;gap:10px;min-width:0}
.hot-rank-badge{position:relative;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:36px;height:34px;color:#2563eb;background:transparent;border:none}
.hot-rank-icon{width:24px;height:24px;fill:currentColor}
.hot-rank-number{position:absolute;right:2px;bottom:3px;display:flex;align-items:center;justify-content:center;width:12px;height:12px;border-radius:999px;background:#fff;color:currentColor;border:1px solid rgba(148,163,184,.3);font-size:7px;font-weight:800;line-height:1;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.hot-rank-badge.rank-gold{color:#eab308}
.hot-rank-badge.rank-silver{color:#94a3b8}
.hot-rank-badge.rank-bronze{color:#cd7f32}
.hot-rank-badge.rank-normal{color:#2563eb}
.link-logo{width:34px;height:34px;border-radius:10px;object-fit:cover;background:#f1f5f9;border:1px solid #e2e8f0;flex-shrink:0}
.link-logo-fallback{display:flex;align-items:center;justify-content:center}
.link-main-text{min-width:0}
.link-title-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}
.link-title{font-size:13px;font-weight:600;color:#0f172a;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.link-desc{margin-top:1px;font-size:12px;color:#64748b;line-height:1.45;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
.relation-pill{display:inline-flex;align-items:center;height:22px;padding:0 8px;border-radius:999px;font-size:11px;font-weight:700}
.relation-text{display:flex;align-items:center;justify-content:center;min-width:0;text-align:center}
.relation-pill.ok{background:#ecfdf5;color:#047857}
.relation-pill.muted{background:#f8fafc;color:#64748b}
.relation-summary-pill.mutual{background:#ecfdf5;color:#047857}
.relation-summary-pill.one-way-out{background:#fff7ed;color:#c2410c}
.relation-summary-pill.one-way-in{background:#eff6ff;color:#2563eb}
.relation-summary-pill.pending{background:#fff7ed;color:#c2410c}
.link-url{display:flex;align-items:center;justify-content:center;min-width:0;font-size:12px;color:#334155;line-height:1.45;word-break:break-all;text-align:center}
.link-url-anchor{text-decoration:none;color:#075985;font-weight:600}
.link-url-anchor:hover{text-decoration:underline}
.self-card-meta-link{text-decoration:none}
.self-card-meta-link:hover{text-decoration:underline}
.galaxy-name-cell,.galaxy-count-cell{display:flex;align-items:center;justify-content:center;min-width:0;text-align:center}
.galaxy-name{max-width:100%;font-size:12px;font-weight:700;color:#0f766e;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.galaxy-empty{font-size:12px;color:#94a3b8}
.galaxy-count-cell{font-size:11px;font-weight:700;color:#64748b;line-height:1.35;white-space:nowrap}
.rss-time{display:flex;align-items:center;justify-content:center;font-size:12px;color:#475569;line-height:1.45;word-break:break-word;text-align:center}
.action-cell{display:flex;align-items:center;justify-content:center;gap:6px;min-width:0}

.fav-btn{display:inline-flex;align-items:center;justify-content:center;width:26px;min-width:26px;height:26px;box-sizing:border-box;border:none;border-radius:0;background:transparent;color:#d1d5db;cursor:pointer;transition:color .15s,filter .15s,transform .15s;padding:0}
.fav-btn:hover{color:#FCD62C;filter:drop-shadow(0 0 4px rgba(252,214,44,.42));transform:translateY(-1px)}
.fav-btn--active{color:#FCD62C;filter:drop-shadow(0 0 4px rgba(252,214,44,.5))}
.invite-btn{display:inline-flex;align-items:center;justify-content:center;outline:none;width:72px;min-width:72px;height:32px;box-sizing:border-box;padding:0 6px;border:2px dashed #64748b;border-radius:15px;background-color:#f1f5f9;color:#64748b;font-size:11px;font-weight:600;cursor:pointer;transition:box-shadow .2s ease,filter .2s ease;box-shadow:0 0 0 3px #f1f5f9,1.5px 1.5px 3px 1px rgba(0,0,0,.15);white-space:nowrap}
.invite-btn:hover{box-shadow:0 0 0 3px #f1f5f9,2px 5px 0 0 currentColor;filter:brightness(.96)}
.invite-btn:active{box-shadow:0 0 0 3px #f1f5f9,0 0 0 0 currentColor;filter:brightness(.92)}
.invite-btn--action{border-color:#075985;color:#075985;background-color:#f0f9ff;box-shadow:0 0 0 3px #f0f9ff,1.5px 1.5px 3px 1px rgba(0,0,0,.15)}
.invite-btn--action:hover{box-shadow:0 0 0 3px #f0f9ff,2px 5px 0 0 #075985}
.invite-btn--linked{border-color:#047857;color:#047857;background-color:#ecfdf5;box-shadow:0 0 0 3px #ecfdf5,1.5px 1.5px 3px 1px rgba(0,0,0,.15)}
.invite-btn--linked:hover{box-shadow:0 0 0 3px #ecfdf5,2px 5px 0 0 #047857}
.invite-btn--pending{border-color:#fdba74;background:linear-gradient(180deg,#fff7ed 0%,#ffedd5 100%);color:#c2410c}
.invite-btn--unregistered{border-color:#cbd5e1;background:linear-gradient(180deg,#f8fafc 0%,#e2e8f0 100%);color:#475569}
.invite-btn--credential{border-color:#c4b5fd;background:linear-gradient(180deg,#f5f3ff 0%,#ede9fe 100%);color:#6d28d9}
.invite-btn--inactive{border-color:#fca5a5;background:linear-gradient(180deg,#fef2f2 0%,#fee2e2 100%);color:#b91c1c}
.invite-btn--warning{border-color:#fcd34d;background:linear-gradient(180deg,#fffbeb 0%,#fef3c7 100%);color:#b45309}
.invite-btn--loading{border-color:#93c5fd;background:linear-gradient(180deg,#eff6ff 0%,#dbeafe 100%);color:#1d4ed8}
.invite-btn--muted{border-color:#fda4af;background:linear-gradient(180deg,#fff1f2 0%,#ffe4e6 100%);color:#be123c}
.invite-btn--plain{border-color:#cbd5e1;background:#f8fafc;color:#64748b;box-shadow:0 0 0 3px #f8fafc,1.5px 1.5px 3px 1px rgba(0,0,0,.08)}
.invite-btn--remove{border-color:#fca5a5;background:linear-gradient(180deg,#fef2f2 0%,#fee2e2 100%);color:#b91c1c}
.invite-btn--remove:hover{box-shadow:0 0 0 3px #fef2f2,2px 5px 0 0 #b91c1c}
.invite-btn:disabled{cursor:not-allowed;opacity:1}
.sp-empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;text-align:center;padding:24px 16px}
.sp-empty-state-text{font-size:14px;font-weight:600;color:#64748b}
.sp-empty-state-hint{font-size:12px;color:#94a3b8;line-height:1.5}
.planet-links-empty{flex:1;display:flex;align-items:center;justify-content:center;min-height:200px}
.planet-links-more{padding:14px 16px;text-align:center;font-size:12px;color:#94a3b8;background:#fff}
.loading-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#ffffff;z-index:5}
.uv-loader{width:80px;height:50px;position:relative}
.uv-loader-text{position:absolute;top:0;padding:0;margin:0;color:#C8B6FF;animation:uvtext 3.5s ease both infinite;font-size:.8rem;letter-spacing:1px}
.uv-load{background-color:#9A79FF;border-radius:50px;display:block;height:16px;width:16px;bottom:0;position:absolute;transform:translateX(64px);animation:uvloading 3.5s ease both infinite}
.uv-load::before{position:absolute;content:"";width:100%;height:100%;background-color:#D1C2FF;border-radius:inherit;animation:uvloading2 3.5s ease both infinite}
@keyframes uvtext{0%{letter-spacing:1px;transform:translateX(0px)}40%{letter-spacing:2px;transform:translateX(26px)}80%{letter-spacing:1px;transform:translateX(32px)}90%{letter-spacing:2px;transform:translateX(0px)}100%{letter-spacing:1px;transform:translateX(0px)}}
@keyframes uvloading{0%{width:16px;transform:translateX(0px)}40%{width:100%;transform:translateX(0px)}80%{width:16px;transform:translateX(64px)}90%{width:100%;transform:translateX(0px)}100%{width:16px;transform:translateX(0px)}}
@keyframes uvloading2{0%{transform:translateX(0px);width:16px}40%{transform:translateX(0%);width:80%}80%{width:100%;transform:translateX(0px)}90%{width:80%;transform:translateX(15px)}100%{transform:translateX(0px);width:16px}}

.sp-header-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border:1px solid rgba(125,211,252,.72);border-radius:9px;font-size:12px;font-weight:600;background:linear-gradient(180deg,#f0f9ff 0%,#e0f2fe 100%);color:#0369a1;cursor:pointer;transition:all .15s;white-space:nowrap}
.sp-header-btn:disabled{opacity:.5;cursor:not-allowed}
.invite-mask{position:fixed;inset:0;background:rgba(15,23,42,.32);display:flex;align-items:center;justify-content:center;z-index:120;padding:20px}
.invite-dialog{width:100%;max-width:420px;background:#ffffff;border:2px dashed #64748b;border-radius:18px;box-shadow:0 0 0 3px #ffffff,4px 6px 0 0 rgba(15,23,42,.12);padding:20px}
.invite-dialog-title{font-size:15px;font-weight:700;color:#0f172a}
.invite-dialog-sub{margin-top:4px;font-size:12px;color:#64748b;line-height:1.6}
.invite-field{margin-top:14px}
.invite-label{display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#475569}
.invite-selectbox{position:relative}
.invite-select-trigger{width:100%;height:42px;padding:0 14px;border:1px solid #dbe3ee;border-radius:12px;background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:13px;font-weight:600;color:#0f172a;cursor:pointer;box-shadow:0 8px 20px rgba(148,163,184,.12);transition:border-color .15s,box-shadow .15s}
.invite-select-trigger:hover{border-color:#bae6fd;box-shadow:0 10px 24px rgba(14,116,144,.12)}
.invite-select-arrow{width:16px;height:16px;color:#0369a1;transition:transform .16s}
.invite-select-arrow.open{transform:rotate(180deg)}
.invite-select-menu{position:absolute;left:0;right:0;top:calc(100% + 8px);padding:8px;background:#fff;border:1px solid #dbe3ee;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.14);display:flex;flex-direction:column;gap:4px;z-index:4}
.invite-select-option{width:100%;padding:10px 12px;border:none;border-radius:10px;background:transparent;display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:13px;font-weight:600;color:#334155;cursor:pointer;transition:background .15s,color .15s}
.invite-select-option:hover{background:#f8fafc;color:#0f172a}
.invite-select-option.active{background:#eff6ff;color:#0369a1}
.invite-option-hint{font-size:11px;font-weight:700;color:#94a3b8}
.invite-field-hint{margin-top:8px;font-size:12px;line-height:1.5;color:#94a3b8}
.invite-textarea{width:100%;min-height:100px;padding:10px 12px;border:1px solid #dbe3ee;border-radius:10px;background:#fff;font-size:13px;color:#0f172a;box-sizing:border-box;resize:vertical}
.invite-actions{display:flex;justify-content:flex-end;gap:14px;margin-top:18px}
.invite-btn-ghost,.invite-btn-primary{display:inline-flex;align-items:center;justify-content:center;outline:none;padding:5px 14px;border:2px dashed #64748b;border-radius:15px;background-color:#f1f5f9;color:#64748b;font-size:11px;font-weight:600;cursor:pointer;transition:transform .2s ease-out;box-shadow:0 0 0 3px #f1f5f9,1.5px 1.5px 3px 1px rgba(0,0,0,.15)}
.invite-btn-ghost:hover,.invite-btn-primary:hover{transform:translateY(-4px) translateX(-2px);box-shadow:0 0 0 3px #f1f5f9,2px 5px 0 0 currentColor}
.invite-btn-ghost:active,.invite-btn-primary:active{transform:translateY(1px) translateX(1px);box-shadow:0 0 0 3px #f1f5f9,0 0 0 0 currentColor}
.invite-btn-primary{border-color:#075985;color:#075985;background-color:#f0f9ff;box-shadow:0 0 0 3px #f0f9ff,1.5px 1.5px 3px 1px rgba(0,0,0,.15)}
.invite-btn-primary--danger{border-color:#b91c1c;color:#b91c1c;background-color:#fef2f2;box-shadow:0 0 0 3px #fef2f2,1.5px 1.5px 3px 1px rgba(0,0,0,.15)}
.invite-btn-primary--danger:hover{box-shadow:0 0 0 3px #fef2f2,2px 5px 0 0 #b91c1c}
.remove-warning{margin:14px 0 0;padding:10px 12px;border-radius:10px;border:1px solid #fecaca;background:#fff1f2;color:#9f1239;font-size:12px;line-height:1.6}
</style>




