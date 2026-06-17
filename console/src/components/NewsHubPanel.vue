<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import {
  fetchNewsBrowse,
  fetchNewsDiscover,
  fetchNewsSearch,
  type NewsDiscoverItem,
  type NewsItem
} from "../api/news";

const props = defineProps<{ searchQuery: string }>();

const PAGE_SIZE = 40;
const SOURCE_LIMIT = 80;
const SOURCE_ROW_HEIGHT = 64;
const SOURCE_OVERSCAN = 6;

const DEFAULT_AVATAR_DATA_URI = `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(
  `<svg viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg"><path d="M512 512m-512 0a512 512 0 1 0 1024 0 512 512 0 1 0-1024 0Z" fill="#1A4066"/><path d="M512 300a150 150 0 1 0 0 300 150 150 0 0 0 0-300zM330 760a182 182 0 0 1 364 0z" fill="#CBD5D8"/></svg>`
)}`;

// ——— 稍后阅读（本地 localStorage，对应 Halo 端 settings.readLater）———
interface ReadLaterItem {
  url: string;
  title: string;
  summary: string;
  blogTitle: string;
  blogLogo: string;
  publishedAt: string;
  savedAt: string;
}
const READ_LATER_KEY = "wp_astrahub_read_later";
const readLater = ref<ReadLaterItem[]>(loadReadLater());
function loadReadLater(): ReadLaterItem[] {
  try {
    const raw = window.localStorage.getItem(READ_LATER_KEY);
    return raw ? (JSON.parse(raw) as ReadLaterItem[]) : [];
  } catch {
    return [];
  }
}
function saveReadLater() {
  try {
    window.localStorage.setItem(READ_LATER_KEY, JSON.stringify(readLater.value));
  } catch {
    /* ignore */
  }
}
const readLaterCount = computed(() => readLater.value.length);

const items = ref<NewsItem[]>([]);
const sources = ref<NewsDiscoverItem[]>([]);
const selectedSourceId = ref("");
const refreshedAt = ref("");
const indexedItems = ref(0);
const nextCursor = ref("");
const searchPage = ref(1);
const hasMore = ref(false);
const loading = ref(false);
const loadingMore = ref(false);
const sourceLoading = ref(false);
const sourceLoadingMore = ref(false);
const sourcesNextCursor = ref("");
const sourcesHasMore = ref(false);
const error = ref("");
const initialized = ref(false);
const showReadLater = ref(false);
const onlyMyGalaxy = ref(false);

const sourcesScrollRef = ref<HTMLElement | null>(null);
const sourcesScrollTop = ref(0);
const sourcesViewportH = ref(0);

const scrollWrapRef = ref<HTMLElement | null>(null);
const loadMoreSentinelRef = ref<HTMLElement | null>(null);
let loadMoreObserver: IntersectionObserver | null = null;
let scrollEndTimer: ReturnType<typeof setTimeout> | null = null;
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;
const isScrolling = ref(false);

const toast = ref("");
function showToast(text: string) {
  toast.value = text;
  window.setTimeout(() => {
    if (toast.value === text) toast.value = "";
  }, 3000);
}

const appliedSearch = computed(() => String(props.searchQuery || "").trim());

function formatTime(value: string) {
  const raw = String(value || "").trim();
  if (!raw) return "未提供";
  const ts = Date.parse(raw);
  if (!Number.isFinite(ts)) return raw;
  return new Date(ts).toLocaleString("zh-CN", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit"
  });
}

function plainSummary(value: string) {
  return String(value || "")
    .replace(/<[^>]+>/g, "")
    .replace(/\s+/g, " ")
    .trim();
}

function openItem(item: NewsItem) {
  const link = String(item.url || "").trim();
  if (!link) {
    showToast("该资讯没有可访问的链接");
    return;
  }
  window.open(link, "_blank", "noreferrer");
}

function openUrl(url: string) {
  window.open(url, "_blank", "noreferrer");
}

function isBookmarked(item: NewsItem): boolean {
  return readLater.value.some((b) => b.url === item.url);
}

function toggleBookmark(item: NewsItem) {
  const idx = readLater.value.findIndex((b) => b.url === item.url);
  if (idx >= 0) {
    readLater.value.splice(idx, 1);
  } else {
    readLater.value.push({
      url: item.url,
      title: item.title || "无标题",
      summary: item.summary || "",
      blogTitle: item.blogTitle || "",
      blogLogo: item.blogLogo || "",
      publishedAt: item.publishedAt || "",
      savedAt: new Date().toISOString()
    });
  }
  saveReadLater();
}

function removeBookmark(bookmark: ReadLaterItem) {
  const idx = readLater.value.indexOf(bookmark);
  if (idx >= 0) {
    readLater.value.splice(idx, 1);
    saveReadLater();
  }
}

function toggleMyGalaxy() {
  showReadLater.value = false;
  selectedSourceId.value = "";
  onlyMyGalaxy.value = !onlyMyGalaxy.value;
  if (scrollWrapRef.value) scrollWrapRef.value.scrollTop = 0;
  void reloadItems();
}

function searchKeyword() {
  if (appliedSearch.value) return appliedSearch.value;
  if (selectedSourceId.value) {
    const source = sources.value.find((s) => s.sourceId === selectedSourceId.value);
    return source?.blogTitle || source?.blogUrl || "";
  }
  return "";
}

function isBrowseMode() {
  return !appliedSearch.value && !selectedSourceId.value;
}

function deduplicateAppend(existing: NewsItem[], incoming: NewsItem[]): NewsItem[] {
  const seen = new Set(existing.map((item) => item.id));
  const merged = [...existing];
  for (const item of incoming) {
    if (!item.id || seen.has(item.id)) continue;
    seen.add(item.id);
    merged.push(item);
  }
  return merged;
}

async function reloadItems() {
  loading.value = true;
  error.value = "";
  nextCursor.value = "";
  searchPage.value = 1;
  try {
    let response;
    if (isBrowseMode()) {
      response = await fetchNewsBrowse({ pageSize: PAGE_SIZE, onlyMyGalaxy: onlyMyGalaxy.value });
    } else {
      const keyword = searchKeyword();
      response = keyword
        ? await fetchNewsSearch({ q: keyword, pageSize: PAGE_SIZE, onlyMyGalaxy: onlyMyGalaxy.value })
        : await fetchNewsBrowse({ pageSize: PAGE_SIZE, onlyMyGalaxy: onlyMyGalaxy.value });
    }
    items.value = Array.isArray(response.items) ? response.items : [];
    nextCursor.value = String(response.nextCursor || "").trim();
    hasMore.value = Boolean(response.hasMore);
    refreshedAt.value = response.refreshedAt || "";
    indexedItems.value = response.indexedItems || 0;
    searchPage.value = response.page || 1;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "读取资讯失败";
    items.value = [];
    hasMore.value = false;
  } finally {
    loading.value = false;
    initialized.value = true;
  }
}

async function loadMoreItems() {
  if (loadingMore.value || loading.value || !hasMore.value) return;
  loadingMore.value = true;
  try {
    let response;
    if (isBrowseMode()) {
      if (!nextCursor.value) {
        hasMore.value = false;
        return;
      }
      response = await fetchNewsBrowse({ pageSize: PAGE_SIZE, cursor: nextCursor.value, onlyMyGalaxy: onlyMyGalaxy.value });
    } else {
      const keyword = searchKeyword();
      const nextPage = searchPage.value + 1;
      response = keyword
        ? await fetchNewsSearch({ q: keyword, page: nextPage, pageSize: PAGE_SIZE, onlyMyGalaxy: onlyMyGalaxy.value })
        : await fetchNewsBrowse({ pageSize: PAGE_SIZE, cursor: nextCursor.value, onlyMyGalaxy: onlyMyGalaxy.value });
      searchPage.value = nextPage;
    }
    const more = Array.isArray(response.items) ? response.items : [];
    items.value = deduplicateAppend(items.value, more);
    nextCursor.value = String(response.nextCursor || "").trim();
    hasMore.value = Boolean(response.hasMore) && more.length > 0;
  } catch (e) {
    showToast(e instanceof Error ? e.message : "加载更多资讯失败");
  } finally {
    loadingMore.value = false;
  }
}

async function reloadSources() {
  sourceLoading.value = true;
  try {
    const response = await fetchNewsDiscover({ size: SOURCE_LIMIT });
    sources.value = Array.isArray(response.items) ? response.items : [];
    sourcesNextCursor.value = String(response.nextCursor || "").trim();
    sourcesHasMore.value = Boolean(response.hasMore) && sourcesNextCursor.value !== "";
    if (sourcesScrollRef.value) {
      sourcesScrollRef.value.scrollTop = 0;
      sourcesScrollTop.value = 0;
    }
  } catch (e) {
    sources.value = [];
    sourcesNextCursor.value = "";
    sourcesHasMore.value = false;
    showToast(e instanceof Error ? e.message : "读取站点列表失败");
  } finally {
    sourceLoading.value = false;
  }
}

async function loadMoreSources() {
  if (sourceLoading.value || sourceLoadingMore.value || !sourcesHasMore.value || !sourcesNextCursor.value) return;
  sourceLoadingMore.value = true;
  try {
    const response = await fetchNewsDiscover({ size: SOURCE_LIMIT, cursor: sourcesNextCursor.value });
    const more = Array.isArray(response.items) ? response.items : [];
    const seen = new Set(sources.value.map((s) => s.sourceId));
    const appended: NewsDiscoverItem[] = [];
    for (const item of more) {
      const id = String(item.sourceId || "").trim();
      if (!id || seen.has(id)) continue;
      seen.add(id);
      appended.push(item);
    }
    if (appended.length) sources.value.push(...appended);
    sourcesNextCursor.value = String(response.nextCursor || "").trim();
    sourcesHasMore.value = Boolean(response.hasMore) && sourcesNextCursor.value !== "";
  } catch (e) {
    showToast(e instanceof Error ? e.message : "加载更多站点失败");
    sourcesHasMore.value = false;
  } finally {
    sourceLoadingMore.value = false;
  }
}

const sourcesWindow = computed(() => {
  const total = sources.value.length;
  if (total === 0 || sourcesViewportH.value === 0) {
    return { start: 0, end: Math.min(total, 20), offsetY: 0 };
  }
  const start = Math.max(0, Math.floor(sourcesScrollTop.value / SOURCE_ROW_HEIGHT) - SOURCE_OVERSCAN);
  const visibleCount = Math.ceil(sourcesViewportH.value / SOURCE_ROW_HEIGHT) + SOURCE_OVERSCAN * 2;
  const end = Math.min(total, start + visibleCount);
  return { start, end, offsetY: start * SOURCE_ROW_HEIGHT };
});

const visibleSources = computed(() => sources.value.slice(sourcesWindow.value.start, sourcesWindow.value.end));

function onSourcesScroll(event: Event) {
  const el = event.currentTarget as HTMLElement;
  sourcesScrollTop.value = el.scrollTop;
  if (
    sourcesHasMore.value &&
    !sourceLoading.value &&
    !sourceLoadingMore.value &&
    el.scrollHeight - el.scrollTop - el.clientHeight < el.clientHeight * 2
  ) {
    void loadMoreSources();
  }
}

function selectSource(sourceId: string) {
  showReadLater.value = false;
  onlyMyGalaxy.value = false;
  selectedSourceId.value = selectedSourceId.value === sourceId ? "" : sourceId;
  if (scrollWrapRef.value) scrollWrapRef.value.scrollTop = 0;
  void reloadItems();
}

function onScroll() {
  isScrolling.value = true;
  if (scrollEndTimer) clearTimeout(scrollEndTimer);
  scrollEndTimer = setTimeout(() => {
    isScrolling.value = false;
    scrollEndTimer = null;
  }, 150);
}

function setupLoadMoreObserver() {
  if (loadMoreObserver) {
    loadMoreObserver.disconnect();
    loadMoreObserver = null;
  }
  const sentinel = loadMoreSentinelRef.value;
  const root = scrollWrapRef.value;
  if (!sentinel || !root) return;
  loadMoreObserver = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        if (loading.value || loadingMore.value || !hasMore.value) continue;
        void loadMoreItems();
      }
    },
    { root, rootMargin: "0px 0px 200px 0px", threshold: 0 }
  );
  loadMoreObserver.observe(sentinel);
}

function measureSourcesViewport() {
  if (sourcesScrollRef.value) {
    sourcesViewportH.value = sourcesScrollRef.value.clientHeight;
  }
}

onMounted(() => {
  measureSourcesViewport();
  window.addEventListener("resize", measureSourcesViewport);
  void reloadSources();
  void reloadItems();
});

onBeforeUnmount(() => {
  window.removeEventListener("resize", measureSourcesViewport);
  if (scrollEndTimer) clearTimeout(scrollEndTimer);
  if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
  if (loadMoreObserver) {
    loadMoreObserver.disconnect();
    loadMoreObserver = null;
  }
});

watch([hasMore, loadMoreSentinelRef], async () => {
  await nextTick();
  if (hasMore.value && loadMoreSentinelRef.value && scrollWrapRef.value) {
    setupLoadMoreObserver();
  } else if (loadMoreObserver) {
    loadMoreObserver.disconnect();
    loadMoreObserver = null;
  }
});

watch(
  () => props.searchQuery,
  () => {
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
      void reloadItems();
    }, 300);
  }
);
</script>

<template>
  <div class="news-wrap">
    <div v-if="toast" class="news-toast">{{ toast }}</div>

    <!-- 首次加载 -->
    <div v-if="!initialized && (loading || sourceLoading)" class="news-global-empty">
      <div class="uv-loader"><span class="uv-loader-text">loading</span><span class="uv-load"></span></div>
    </div>

    <!-- 全局空态 -->
    <div
      v-else-if="initialized && !selectedSourceId && !showReadLater && !onlyMyGalaxy && items.length === 0 && sources.length === 0"
      class="news-global-empty"
    >
      <div class="ah-empty sp-empty-state">
        <svg class="sp-empty-state-icon" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" width="120" height="120"><path d="M217.0088 482.0912l315.36-64.8v133.2z" fill="#416191"/><path d="M851.3288 482.0912l-318.96-64.8v133.2zM211.2488 881.6912l321.12 76.32v-419.76l-321.12-52.56z" fill="#5074AE"/><path d="M853.4888 881.6912l-321.12 76.32v-419.76l321.12-52.56z" fill="#40608F"/><path d="M532.3688 538.2512l88.56 169.92 318.96-92.88-88.56-133.2z" fill="#4B6F9B"/><path d="M535.9688 538.2512l-88.56 169.92-318.96-92.88 88.56-133.2z" fill="#6A90C0"/></svg>
        <div class="sp-empty-state-text">{{ error || "暂无资讯" }}</div>
        <div class="sp-empty-state-hint">{{ error ? "请检查接入状态或网络连接" : "主星还未聚合到任何 RSS 源内容" }}</div>
      </div>
    </div>

    <template v-else>
      <!-- 左侧源列表 -->
      <aside class="news-sidebar">
        <div class="news-sidebar-title">
          <svg viewBox="0 0 24 24" fill="none" width="16" height="16"><path d="M4 11a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" /><path d="M4 4a16 16 0 0 1 16 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" /><circle cx="5" cy="19" r="1.6" fill="currentColor" /></svg>
          <span>RSS 列表</span>
        </div>
        <div class="news-sidebar-fixed">
          <button type="button" class="news-source-item news-source-item--readlater" :class="{ active: showReadLater }" @click="onlyMyGalaxy = false; showReadLater = !showReadLater">
            <div class="news-source-avatar news-source-avatar--readlater">
              <svg viewBox="0 0 1024 1024" width="18" height="18" xmlns="http://www.w3.org/2000/svg"><path d="M959.24224 401.32608c-7.36256-24.57088-28.78976-43.22304-63.6928-55.43936a15.36 15.36 0 0 0-1.3824-0.43008l-189.54752-51.4304c-41.09824-62.68416-82.25792-125.34272-123.40736-188.01664-0.17408-0.24576-0.54272-0.8192-0.7168-1.06496-19.79904-27.68896-44.48256-42.94656-69.46304-42.94656-18.06336 0-44.544 7.78752-68.38784 45.2096L337.08032 278.69184c-47.27296 14.32576-94.54592 28.71808-141.84448 43.10016L126.1056 342.81984C93.91104 353.28 73.56928 370.2016 65.64864 393.12896c-8.30976 24.0896-2.46784 52.1728 17.87904 85.87264 0.49152 0.82432 1.03424 1.60768 1.61792 2.34496a203168.54272 203168.54272 0 0 1 124.05248 157.1584c-2.11968 75.5712-4.2752 151.2192-6.47168 226.87232-3.15392 36.21888 2.08896 61.74208 16 78.0288 10.54208 12.3392 25.20064 18.59072 43.5712 18.59072 14.07488 0 30.7712-3.67616 53.2992-11.86816l182.52288-74.2912a116757.43744 116757.43744 0 0 0 207.60064 77.18912c13.62432 4.38784 26.23488 6.60992 37.49888 6.60992 25.68192 0 69.27872-11.81184 72.76544-90.96192a21.24288 21.24288 0 0 0-0.02048-2.42176c-3.81952-67.45088-7.68-134.74304-11.53536-202.08128l-0.0512-0.88576 134.43584-180.78208c20.74112-29.82912 27.61728-57.15968 20.4288-81.1776z" fill="#FCD62C" /><path d="M905.0112 455.04l-139.04896 186.95168a23.63904 23.63904 0 0 0-4.55168 15.43168l0.5376 9.51808c3.82976 66.90304 7.67488 133.7856 11.4688 200.8064-2.35008 46.63296-20.44416 46.63296-30.20288 46.63296-7.08096 0-15.54432-1.56672-24.31488-4.36736a142424.4224 142424.4224 0 0 1-214.05696-79.63136 20.1984 20.1984 0 0 0-14.63808 0.21504l-189.06624 76.9792c-16.9984 6.1696-29.70624 9.1648-38.8352 9.1648-8.85248 0-11.20256-2.74944-12.08832-3.77344-2.45248-2.87744-7.85408-12.90752-5.05344-44.02688 0.03584-0.4864 0.07168-0.96768 0.08704-1.4592 2.2784-78.78656 4.52608-157.55264 6.7328-236.23168a23.6032 23.6032 0 0 0-4.97152-15.21664 163892.8896 163892.8896 0 0 0-128.37376-162.66752c-11.77088-19.80928-16.2816-35.2256-13.02016-44.63616 3.82976-11.10528 20.0192-18.432 32.5632-22.50752L206.9504 365.312c49.8432-15.16544 99.62496-30.3104 149.43232-45.40928a21.36064 21.36064 0 0 0 11.96032-9.3696l109.7216-178.2272c7.27552-11.42272 18.91328-25.05216 32.98304-25.05216 11.37664 0 24.01792 8.91904 35.29216 24.6784 42.65472 64.95744 85.31456 129.90464 127.91296 194.87744a21.28896 21.28896 0 0 0 12.1856 8.98048L882.90816 389.12c20.21888 7.17312 32.91648 16.37376 35.78368 25.93792 2.7392 9.14432-2.2784 23.54176-13.68064 39.98208z" fill="#FCD62C" /></svg>
            </div>
            <div class="news-source-meta">
              <div class="news-source-name">稍后阅读</div>
              <div class="news-source-sub">{{ readLaterCount }} 篇</div>
            </div>
          </button>
          <button type="button" class="news-source-item news-source-item--galaxy" :class="{ active: onlyMyGalaxy }" @click="toggleMyGalaxy">
            <div class="news-source-avatar news-source-avatar--galaxy">
              <svg viewBox="0 0 1024 1024" width="18" height="18" xmlns="http://www.w3.org/2000/svg"><path d="M249.6 723.2c19.2 0 38.4 0 64-6.4h12.8c51.2 44.8 115.2 76.8 192 76.8 153.6 0 275.2-121.6 275.2-275.2 0-12.8 0-32-6.4-44.8l19.2-19.2c57.6-57.6 76.8-108.8 57.6-147.2-19.2-38.4-89.6-44.8-192-19.2-44.8-38.4-96-51.2-153.6-51.2-153.6 0-281.6 121.6-281.6 275.2v6.4c-70.4 70.4-96 128-76.8 166.4 12.8 25.6 44.8 38.4 89.6 38.4z m268.8 32c-57.6 0-108.8-19.2-147.2-51.2 64-19.2 140.8-44.8 211.2-89.6 70.4-38.4 128-76.8 179.2-121.6V512c-6.4 134.4-115.2 243.2-243.2 243.2z m332.8-441.6c12.8 25.6-6.4 70.4-57.6 128l-6.4 6.4c-19.2-64-51.2-115.2-96-153.6 83.2-19.2 140.8-12.8 160 19.2z m-332.8-44.8c121.6 0 224 89.6 236.8 204.8-51.2 44.8-115.2 89.6-185.6 128-76.8 38.4-153.6 70.4-217.6 89.6-51.2-44.8-76.8-108.8-76.8-179.2 0-134.4 108.8-243.2 243.2-243.2zM236.8 544c6.4 57.6 32 115.2 70.4 153.6-70.4 12.8-115.2 6.4-134.4-19.2-12.8-32 12.8-76.8 64-134.4z" fill="currentColor"></path></svg>
            </div>
            <div class="news-source-meta">
              <div class="news-source-name">我的星系</div>
              <div class="news-source-sub">仅看友链</div>
            </div>
          </button>
          <button type="button" class="news-source-item" :class="{ active: !selectedSourceId && !showReadLater && !onlyMyGalaxy }" @click="selectSource('')">
            <div class="news-source-avatar news-source-avatar--all">
              <svg viewBox="0 0 24 24" fill="none" width="16" height="16"><path d="M4 11a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" /><path d="M4 4a16 16 0 0 1 16 16" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" /><circle cx="5" cy="19" r="1.8" fill="currentColor" /></svg>
            </div>
            <div class="news-source-meta">
              <div class="news-source-name">全站 RSS</div>
              <div class="news-source-sub">{{ refreshedAt ? formatTime(refreshedAt) : "" }}</div>
            </div>
          </button>
        </div>

        <div ref="sourcesScrollRef" class="news-sidebar-scroll" @scroll="onSourcesScroll">
          <div v-if="sourceLoading && sources.length === 0" class="news-sidebar-loading">
            <span class="uv-loader-text">loading</span>
          </div>
          <div v-else class="news-sources-virtual" :style="{ height: sources.length * SOURCE_ROW_HEIGHT + 'px' }">
            <div class="news-sources-window" :style="{ transform: 'translateY(' + sourcesWindow.offsetY + 'px)' }">
              <button
                v-for="source in visibleSources"
                :key="source.sourceId"
                type="button"
                class="news-source-item news-source-item--virtual"
                :class="{ active: selectedSourceId === source.sourceId }"
                @click="selectSource(source.sourceId)"
              >
                <img class="news-source-avatar" :src="source.blogLogo || DEFAULT_AVATAR_DATA_URI" alt="" loading="lazy" @error="($event.target as HTMLImageElement).src = DEFAULT_AVATAR_DATA_URI" />
                <div class="news-source-meta">
                  <div class="news-source-name">{{ source.blogTitle || source.blogUrl }}</div>
                  <div class="news-source-sub">{{ source.latestPublishedAt ? formatTime(source.latestPublishedAt) : "未提供发布时间" }}</div>
                </div>
              </button>
            </div>
          </div>
          <div v-if="sourceLoadingMore" class="news-sidebar-loading">
            <span class="uv-loader-text">loading</span>
          </div>
        </div>
      </aside>

      <!-- 右侧文章流 -->
      <section ref="scrollWrapRef" class="news-feed" :class="{ 'is-scrolling': isScrolling }" @scroll="onScroll">
        <div v-if="loading" class="news-loading-overlay">
          <div class="uv-loader"><span class="uv-loader-text">loading</span><span class="uv-load"></span></div>
        </div>

        <div class="news-feed-header">
          <span class="news-feed-title-main">{{ showReadLater ? "稍后阅读" : (onlyMyGalaxy ? "我的星系" : "星链资讯") }}</span>
          <span v-if="!showReadLater && !onlyMyGalaxy && indexedItems > 0" class="news-feed-title-meta">已聚合 {{ indexedItems }} 条 · {{ formatTime(refreshedAt) }}</span>
          <span v-if="!showReadLater && onlyMyGalaxy" class="news-feed-title-meta">仅展示友链对端的 RSS 资讯</span>
          <span v-if="showReadLater" class="news-feed-title-meta">{{ readLaterCount }} 篇已收藏</span>
        </div>

        <!-- 稍后阅读视图 -->
        <template v-if="showReadLater">
          <div v-if="readLaterCount === 0" class="news-feed-empty">
            <div class="ah-empty sp-empty-state">
              <svg class="sp-empty-state-icon" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" width="120" height="120"><path d="M217.0088 482.0912l315.36-64.8v133.2z" fill="#416191"/><path d="M851.3288 482.0912l-318.96-64.8v133.2zM211.2488 881.6912l321.12 76.32v-419.76l-321.12-52.56z" fill="#5074AE"/><path d="M853.4888 881.6912l-321.12 76.32v-419.76l321.12-52.56z" fill="#40608F"/><path d="M532.3688 538.2512l88.56 169.92 318.96-92.88-88.56-133.2z" fill="#4B6F9B"/><path d="M535.9688 538.2512l-88.56 169.92-318.96-92.88 88.56-133.2z" fill="#6A90C0"/></svg>
              <div class="sp-empty-state-text">暂无收藏</div>
              <div class="sp-empty-state-hint">点击资讯卡片上的"稍后阅读"按钮添加</div>
            </div>
          </div>
          <article v-for="bookmark in readLater" :key="bookmark.url" class="news-card">
            <div class="news-card-head">
              <img class="news-card-avatar" :src="bookmark.blogLogo || DEFAULT_AVATAR_DATA_URI" alt="" @error="($event.target as HTMLImageElement).src = DEFAULT_AVATAR_DATA_URI" />
              <span class="news-card-blog">{{ bookmark.blogTitle || "未知来源" }}</span>
              <span class="news-card-title">{{ bookmark.title }}</span>
              <span class="news-card-time">{{ formatTime(bookmark.publishedAt || bookmark.savedAt) }}</span>
            </div>
            <div class="news-card-desc">{{ plainSummary(bookmark.summary) || "暂无摘要" }}</div>
            <div class="news-card-footer">
              <div class="news-card-tags">
                <span class="news-card-tag">收藏于 {{ formatTime(bookmark.savedAt) }}</span>
              </div>
              <div class="news-card-actions">
                <button type="button" class="news-card-btn" @click="removeBookmark(bookmark)">取消收藏</button>
                <button type="button" class="news-card-btn" @click="openUrl(bookmark.url)">打开原文</button>
              </div>
            </div>
          </article>
        </template>

        <!-- 正常资讯流 -->
        <template v-else>
          <div v-if="error" class="news-feed-empty">
            <div class="ah-empty sp-empty-state">
              <svg class="sp-empty-state-icon" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" width="120" height="120"><path d="M217.0088 482.0912l315.36-64.8v133.2z" fill="#416191"/><path d="M851.3288 482.0912l-318.96-64.8v133.2zM211.2488 881.6912l321.12 76.32v-419.76l-321.12-52.56z" fill="#5074AE"/><path d="M853.4888 881.6912l-321.12 76.32v-419.76l321.12-52.56z" fill="#40608F"/><path d="M532.3688 538.2512l88.56 169.92 318.96-92.88-88.56-133.2z" fill="#4B6F9B"/><path d="M535.9688 538.2512l-88.56 169.92-318.96-92.88 88.56-133.2z" fill="#6A90C0"/></svg>
              <div class="sp-empty-state-text">{{ error }}</div>
              <div class="sp-empty-state-hint">请检查接入状态或网络连接</div>
            </div>
          </div>
          <div v-else-if="!loading && items.length === 0" class="news-feed-empty">
            <div v-if="onlyMyGalaxy" class="ah-empty sp-empty-state">
              <svg class="sp-empty-state-icon" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" width="120" height="120"><path d="M217.0088 482.0912l315.36-64.8v133.2z" fill="#416191"/><path d="M851.3288 482.0912l-318.96-64.8v133.2zM211.2488 881.6912l321.12 76.32v-419.76l-321.12-52.56z" fill="#5074AE"/><path d="M853.4888 881.6912l-321.12 76.32v-419.76l321.12-52.56z" fill="#40608F"/><path d="M532.3688 538.2512l88.56 169.92 318.96-92.88-88.56-133.2z" fill="#4B6F9B"/><path d="M535.9688 538.2512l-88.56 169.92-318.96-92.88 88.56-133.2z" fill="#6A90C0"/></svg>
              <div class="sp-empty-state-text">我的星系暂无资讯</div>
              <div class="sp-empty-state-hint">可能是你的友链还未接入 RSS，或当前都没有新文章。</div>
            </div>
            <div v-else class="ah-empty sp-empty-state">
              <svg class="sp-empty-state-icon" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" width="120" height="120"><path d="M217.0088 482.0912l315.36-64.8v133.2z" fill="#416191"/><path d="M851.3288 482.0912l-318.96-64.8v133.2zM211.2488 881.6912l321.12 76.32v-419.76l-321.12-52.56z" fill="#5074AE"/><path d="M853.4888 881.6912l-321.12 76.32v-419.76l321.12-52.56z" fill="#40608F"/><path d="M532.3688 538.2512l88.56 169.92 318.96-92.88-88.56-133.2z" fill="#4B6F9B"/><path d="M535.9688 538.2512l-88.56 169.92-318.96-92.88 88.56-133.2z" fill="#6A90C0"/></svg>
              <div class="sp-empty-state-text">暂无资讯</div>
              <div class="sp-empty-state-hint">主星还未聚合到符合条件的内容</div>
            </div>
          </div>

          <article v-for="item in items" :key="item.id || `${item.sourceId}-${item.url}`" class="news-card">
            <div class="news-card-head">
              <img class="news-card-avatar" :src="item.blogLogo || DEFAULT_AVATAR_DATA_URI" alt="" @error="($event.target as HTMLImageElement).src = DEFAULT_AVATAR_DATA_URI" />
              <span class="news-card-blog">{{ item.blogTitle || item.blogUrl }}</span>
              <span class="news-card-title">{{ item.title || "暂无标题" }}</span>
              <span class="news-card-time">{{ formatTime(item.publishedAt) }}</span>
            </div>
            <div class="news-card-desc">{{ plainSummary(item.summary) || "暂无摘要" }}</div>
            <div class="news-card-footer">
              <div class="news-card-tags">
                <span v-if="item.sourceSiteCount" class="news-card-tag">来源 {{ item.sourceSiteCount }} 个星链</span>
                <span v-if="item.mentionCount" class="news-card-tag">被收录 {{ item.mentionCount }} 次</span>
                <span v-for="tag in (item.tags || []).slice(0, 3)" :key="tag" class="news-card-tag">{{ tag }}</span>
              </div>
              <div class="news-card-actions">
                <button type="button" class="news-card-btn" @click="toggleBookmark(item)">{{ isBookmarked(item) ? "取消收藏" : "稍后阅读" }}</button>
                <button type="button" class="news-card-btn" @click="openItem(item)">打开原文</button>
              </div>
            </div>
          </article>

          <div v-if="hasMore && items.length > 0" ref="loadMoreSentinelRef" class="news-feed-sentinel" aria-hidden="true"></div>
          <div v-if="hasMore && items.length > 0" class="news-feed-more">{{ loadingMore ? "加载更多资讯中…" : "滚动加载更多" }}</div>
        </template>
      </section>
    </template>
  </div>
</template>

<style scoped>
.news-wrap{flex:1;display:flex;min-height:0;gap:14px;padding:16px 20px;overflow:hidden;position:relative}
.news-toast{position:absolute;top:10px;left:50%;transform:translateX(-50%);z-index:200;padding:8px 16px;border-radius:10px;background:#0f172a;color:#fff;font-size:12px;font-weight:600;box-shadow:0 8px 24px rgba(15,23,42,.2)}
.news-global-empty{flex:1;display:flex;align-items:center;justify-content:center}
.news-sidebar{flex:0 0 260px;min-width:0;display:flex;flex-direction:column;overflow:hidden}
.news-sidebar-title{display:flex;align-items:center;gap:8px;padding:14px 16px 10px;font-size:13px;font-weight:700;color:#0369a1;letter-spacing:.06em}
.news-sidebar-title svg{width:16px;height:16px;color:#0ea5e9}
.news-sidebar-scroll{flex:1;min-height:0;overflow-y:auto;padding:0 10px 10px;display:flex;flex-direction:column;gap:8px;scrollbar-width:none;-ms-overflow-style:none}
.news-sidebar-scroll::-webkit-scrollbar{display:none}
.news-sidebar-fixed{padding:0 10px;display:flex;flex-direction:column;gap:8px;margin-bottom:8px}
.news-sources-virtual{position:relative;width:100%}
.news-sources-window{position:absolute;top:0;left:0;right:0;display:flex;flex-direction:column;gap:8px}
.news-source-item--virtual{height:56px}
.news-sidebar-loading{padding:14px;text-align:center;color:#94a3b8;font-size:11px}
.news-source-item{display:flex;align-items:center;gap:12px;width:100%;padding:10px 14px;border:1px solid rgba(0,0,0,.06);background:#fff;cursor:pointer;border-radius:14px;text-align:left;color:#0f172a;box-shadow:0 1px 4px rgba(15,23,42,.04);transition:background .15s,border-color .15s}
.news-source-item:hover{background:#f8fafc;border-color:rgba(14,165,233,.2)}
.news-source-item.active{background:#ecfeff;border-color:#67e8f9;box-shadow:0 0 0 2px rgba(103,232,249,.25)}
.news-source-avatar{width:36px;height:36px;border-radius:50%;flex-shrink:0;object-fit:cover;background:#e2e8f0}
.news-source-avatar--all,.news-source-avatar--readlater,.news-source-avatar--galaxy{background:#f1f5f9;border:1px solid #e2e8f0;color:#1d4ed8;display:flex;align-items:center;justify-content:center;border-radius:50%;width:36px;height:36px}
.news-source-avatar--galaxy{color:#7c3aed}
.news-source-meta{flex:1;min-width:0;display:flex;align-items:center;justify-content:space-between;gap:8px}
.news-source-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.news-source-sub{font-size:11px;color:#94a3b8;white-space:nowrap;flex-shrink:0}
.news-feed{flex:1;min-width:0;display:flex;flex-direction:column;overflow-y:auto;padding:0 4px;position:relative;scrollbar-width:none;-ms-overflow-style:none}
.news-feed::-webkit-scrollbar{display:none}
.news-feed.is-scrolling .news-card{pointer-events:none}
.news-loading-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.7);z-index:5}
.news-feed-header{display:flex;align-items:center;justify-content:space-between;padding:6px 12px 12px;flex-shrink:0;position:sticky;top:0;z-index:2;background:#ffffff}
.news-feed-title-main{font-size:14px;font-weight:700;color:#0f172a}
.news-feed-title-meta{font-size:11px;color:#94a3b8;letter-spacing:.04em}
.news-feed-empty{flex:1;display:flex;align-items:center;justify-content:center;min-height:240px}
.sp-empty-state{padding:64px 16px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:12px}
.sp-empty-state-icon{opacity:.4}
.sp-empty-state-text{font-size:14px;font-weight:600;color:#64748b}
.sp-empty-state-hint{font-size:12px;color:#94a3b8}
.news-card{display:flex;flex-direction:column;gap:8px;padding:12px 14px;margin:0 8px 10px;border:1px solid rgba(0,0,0,.06);border-radius:18px;background:rgba(255,255,255,.85);box-shadow:0 2px 8px rgba(15,23,42,.04)}
.news-card-head{display:flex;align-items:center;gap:8px;min-width:0}
.news-card-avatar{width:24px;height:24px;border-radius:50%;object-fit:cover;background:#e2e8f0;flex-shrink:0}
.news-card-blog{font-size:12px;font-weight:600;color:#0369a1;white-space:nowrap;flex-shrink:0;max-width:140px;overflow:hidden;text-overflow:ellipsis}
.news-card-title{font-size:13px;font-weight:600;color:#0f172a;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.news-card-time{font-size:11px;color:#94a3b8;flex-shrink:0}
.news-card-desc{font-size:12px;color:#475569;line-height:1.6;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.news-card-footer{display:flex;align-items:center;justify-content:space-between;gap:8px}
.news-card-tags{display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-width:0}
.news-card-tag{display:inline-flex;align-items:center;height:20px;padding:0 8px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:600;white-space:nowrap}
.news-card-actions{display:flex;align-items:center;gap:6px;flex-shrink:0}
.news-card-btn{height:26px;padding:0 12px;border-radius:999px;border:1px solid rgba(14,165,233,.3);background:#fff;color:#0369a1;font-size:12px;font-weight:600;cursor:pointer;transition:background .15s,border-color .15s}
.news-card-btn:hover{background:#ecfeff;border-color:#67e8f9}
.news-feed-sentinel{height:1px}
.news-feed-more{padding:14px;text-align:center;color:#94a3b8;font-size:12px}
.uv-loader{width:80px;height:50px;position:relative}
.uv-loader-text{position:absolute;top:0;padding:0;margin:0;color:#C8B6FF;animation:uvtext 3.5s ease both infinite;font-size:.8rem;letter-spacing:1px}
.uv-load{background-color:#9A79FF;border-radius:50px;display:block;height:16px;width:16px;bottom:0;position:absolute;transform:translateX(64px);animation:uvloading 3.5s ease both infinite}
.uv-load::before{position:absolute;content:"";width:100%;height:100%;background-color:#D1C2FF;border-radius:inherit;animation:uvloading2 3.5s ease both infinite}
@keyframes uvtext{0%{letter-spacing:1px;transform:translateX(0px)}40%{letter-spacing:2px;transform:translateX(26px)}80%{letter-spacing:1px;transform:translateX(32px)}90%{letter-spacing:2px;transform:translateX(0px)}100%{letter-spacing:1px;transform:translateX(0px)}}
@keyframes uvloading{0%{width:16px;transform:translateX(0px)}40%{width:100%;transform:translateX(0px)}80%{width:16px;transform:translateX(64px)}90%{width:100%;transform:translateX(0px)}100%{width:16px;transform:translateX(0px)}}
@keyframes uvloading2{0%{transform:translateX(0px);width:16px}40%{transform:translateX(0%);width:80%}80%{width:100%;transform:translateX(0px)}90%{width:80%;transform:translateX(15px)}100%{transform:translateX(0px);width:16px}}
</style>
