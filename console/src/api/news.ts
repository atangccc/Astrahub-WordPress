// 星链资讯（RSS 深空）API：经插件 /hub/get 代理签名转发 Hub /v1/planet/rss-deep-space/*。
// 对应 Halo 端 useNewsHub.ts 的 browse / search / discover 三个入口。
import { api } from "./client";

export interface NewsItem {
  id: string;
  sourceId: string;
  title: string;
  summary: string;
  url: string;
  publishedAt: string;
  blogTitle: string;
  blogUrl: string;
  blogLogo: string;
  nodeName: string;
  tags: string[];
  mentionCount: number;
  sourceSiteCount: number;
}

export interface NewsBrowseResponse {
  generatedAt: string;
  refreshedAt: string;
  indexedBlogs: number;
  indexedFeeds: number;
  indexedItems: number;
  total: number;
  page: number;
  cursor: string;
  nextCursor: string;
  hasMore: boolean;
  refreshing: boolean;
  items: NewsItem[];
}

export interface NewsDiscoverItem {
  sourceId: string;
  blogTitle: string;
  blogUrl: string;
  blogLogo: string;
  rssUrl: string;
  itemCount: number;
  latestPublishedAt: string;
  latestTitle: string;
}

export interface NewsDiscoverResponse {
  generatedAt: string;
  total: number;
  cursor: string;
  nextCursor: string;
  hasMore: boolean;
  refreshing: boolean;
  items: NewsDiscoverItem[];
}

export interface BrowseQuery {
  pageSize?: number;
  cursor?: string;
  onlyMyGalaxy?: boolean;
}

export interface SearchQuery {
  q: string;
  page?: number;
  pageSize?: number;
  cursor?: string;
  onlyMyGalaxy?: boolean;
}

export interface DiscoverQuery {
  size?: number;
  cursor?: string;
}

async function hubGet<T>(path: string, query: Record<string, string>): Promise<T> {
  const resp = await api.post<T>("/hub/get", { path, query });
  if (!resp.success) {
    throw new Error(resp.message || "读取资讯失败");
  }
  return (resp.data || {}) as T;
}

function clean(query: Record<string, string | number | boolean | undefined>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null) continue;
    const t = String(v).trim();
    if (t) out[k] = t;
  }
  return out;
}

// 全站/我的星系浏览：Hub 用 cursor + size 翻页。
export async function fetchNewsBrowse(opts: BrowseQuery = {}): Promise<NewsBrowseResponse> {
  return hubGet<NewsBrowseResponse>("/v1/planet/rss-deep-space/browse", clean({
    size: opts.pageSize,
    cursor: opts.cursor,
    onlyMyGalaxy: opts.onlyMyGalaxy ? "true" : ""
  }));
}

// 关键词/单源搜索：Hub 用 q + page + size 翻页。
export async function fetchNewsSearch(opts: SearchQuery): Promise<NewsBrowseResponse> {
  return hubGet<NewsBrowseResponse>("/v1/planet/rss-deep-space/search", clean({
    q: opts.q,
    page: opts.page,
    size: opts.pageSize,
    onlyMyGalaxy: opts.onlyMyGalaxy ? "true" : ""
  }));
}

// RSS 源列表：Hub 用 cursor + size 翻页。
export async function fetchNewsDiscover(opts: DiscoverQuery = {}): Promise<NewsDiscoverResponse> {
  return hubGet<NewsDiscoverResponse>("/v1/planet/rss-deep-space/discover", clean({
    size: opts.size,
    cursor: opts.cursor
  }));
}
