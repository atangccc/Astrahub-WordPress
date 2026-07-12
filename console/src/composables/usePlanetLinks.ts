import { computed, ref } from "vue";
import { api } from "../api/client";

// 友链星球本地分页（游标 + 去重），复刻 Halo 端 usePlanetLinksLocal。
// 经插件代理 GET /planet/links 拉取，插件签名转发 Hub /v1/planet/links。

const PAGE_SIZE = 50;

export interface PlanetLinkItem {
  url: string;
  title: string;
  description: string;
  logo?: string;
  updatedAt: string;
  sourceSiteCount?: number;
  galaxyName?: string;
  acceptedInvitationCount?: number;
  hotRank?: number;
  relationKind?: string;
  relationStatus?: string;
  targetSiteId?: string;
  targetRegistered?: boolean;
  targetSupportsInvitation?: boolean;
  targetInvitationState?: string;
  targetInvitationMessage?: string;
  outboxInvitationActive?: boolean;
}

interface PlanetLinksPayload {
  generatedAt?: string;
  total?: number;
  size?: number;
  hasMore?: boolean;
  nextCursor?: string;
  items?: PlanetLinkItem[];
}

export function usePlanetLinks() {
  const loading = ref(false);
  const loadingMore = ref(false);
  const error = ref("");
  const items = ref<PlanetLinkItem[]>([]);
  const nextCursor = ref("");
  const hasMore = ref(false);

  const keyword = ref("");
  const relation = ref("");

  const visibleItems = computed(() => items.value);

  async function fetchPage(cursor: string): Promise<PlanetLinksPayload> {
    const query: Record<string, string> = { size: String(PAGE_SIZE) };
    if (cursor) query.cursor = cursor;
    if (keyword.value) query.keyword = keyword.value;
    if (relation.value) query.relation = relation.value;
    const resp = await api.get<PlanetLinksPayload>("/planet/links", query);
    if (!resp.success) {
      throw new Error(resp.message || "读取星球友链失败");
    }
    // 代理把 Hub body 放在 data 里。
    return (resp.data || {}) as PlanetLinksPayload;
  }

  async function fetchLinks(options?: { silent?: boolean }) {
    const silent = Boolean(options?.silent);
    if (!silent) {
      loading.value = true;
      error.value = "";
    }
    try {
      const payload = await fetchPage("");
      items.value = Array.isArray(payload.items) ? payload.items : [];
      nextCursor.value = String(payload.nextCursor || "").trim();
      hasMore.value = Boolean(payload.hasMore) && nextCursor.value !== "";
      if (silent) error.value = "";
    } catch (e) {
      if (silent) return;
      error.value = e instanceof Error ? e.message : "读取星球友链失败";
      items.value = [];
      nextCursor.value = "";
      hasMore.value = false;
    } finally {
      if (!silent) loading.value = false;
    }
  }

  async function loadMore() {
    if (loading.value || loadingMore.value || !hasMore.value || !nextCursor.value) {
      return;
    }
    loadingMore.value = true;
    try {
      const payload = await fetchPage(nextCursor.value);
      const more = Array.isArray(payload.items) ? payload.items : [];
      const seen = new Set(items.value.map((it) => it.url));
      const appended: PlanetLinkItem[] = [];
      for (const it of more) {
        const url = String(it.url || "").trim();
        if (!url || seen.has(url)) continue;
        seen.add(url);
        appended.push(it);
      }
      if (appended.length) {
        items.value.push(...appended);
      }
      nextCursor.value = String(payload.nextCursor || "").trim();
      hasMore.value = Boolean(payload.hasMore) && nextCursor.value !== "";
    } catch (e) {
      error.value = e instanceof Error ? e.message : "加载更多友链失败";
      hasMore.value = false;
    } finally {
      loadingMore.value = false;
    }
  }

  function markOutboxActive(targetUrl: string) {
    const url = String(targetUrl || "").trim();
    if (!url) return;
    const target = items.value.find((entry) => entry.url === url);
    if (target) {
      target.outboxInvitationActive = true;
    }
  }

  function markRelationRemoved(target: { targetSiteId?: string; url?: string }) {
    const targetSiteId = String(target.targetSiteId || "").trim();
    const targetUrl = normalizeComparableUrl(String(target.url || ""));
    items.value = items.value.map((entry) => {
      const entrySiteId = String(entry.targetSiteId || "").trim();
      const entryUrl = normalizeComparableUrl(entry.url);
      const matchedBySiteId = Boolean(targetSiteId) && entrySiteId === targetSiteId;
      const matchedByUrl = Boolean(targetUrl) && entryUrl === targetUrl;
      if (!matchedBySiteId && !matchedByUrl) {
        return entry;
      }
      return {
        ...entry,
        relationKind: "none",
        relationStatus: entry.targetRegistered ? "invitable" : "none",
        outboxInvitationActive: false
      };
    });
  }

  function normalizeComparableUrl(rawUrl: string) {
    return String(rawUrl || "").trim().replace(/\/+$/, "").toLowerCase();
  }

  function setQuery(next: { keyword?: string; relation?: string }): boolean {
    const nextKeyword = String(next.keyword ?? keyword.value).trim();
    const nextRelation = String(next.relation ?? relation.value).trim();
    const changed = nextKeyword !== keyword.value || nextRelation !== relation.value;
    keyword.value = nextKeyword;
    relation.value = nextRelation;
    return changed;
  }

  return {
    loading,
    loadingMore,
    error,
    items,
    visibleItems,
    hasMore,
    fetchLinks,
    loadMore,
    markOutboxActive,
    markRelationRemoved,
    setQuery
  };
}

