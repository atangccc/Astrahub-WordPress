// 关系图数据流：多种子 BFS 爬取 Hub 图谱，构造画布节点/边。
// 对应 Halo 端 useRelationGraph.ts，端点改为经插件 /hub/get 代理。
import { computed, ref, shallowRef } from "vue";
import {
  fetchGraphNode,
  fetchGraphNodes,
  fetchMySite,
  type GraphNodeDetailResponse,
  type GraphNodeFriendLink,
  type GraphNodeListItem,
  type GraphSiteDetailResponse
} from "../api/graph";

const FRIEND_LINK_PAGE_SIZE = 100;
const NODES_FETCH_PAGE_SIZE = 100;
const BFS_MAX_NODES = 1000;
const BFS_MAX_CONCURRENCY = 4;

export interface GraphCanvasNode {
  id: string;
  kind: "self" | "registered" | "unregistered";
  nodeId?: string;
  siteId?: string;
  title: string;
  galaxyName?: string;
  subtitle?: string;
  url?: string;
  rssUrl?: string;
  description?: string;
  avatar?: string;
  raw?: GraphNodeFriendLink;
}

export interface GraphCanvasEdge {
  id: string;
  source: string;
  target: string;
}

export interface GraphLoadProgress {
  expanded: number;
  pending: number;
  inflight: number;
  total: number;
  capped: boolean;
}

export function useRelationGraph() {
  const loading = ref(false);
  const error = ref("");
  const mySite = shallowRef<GraphSiteDetailResponse | null>(null);
  const nodeCache = new Map<string, GraphNodeDetailResponse>();
  const nodes = ref(new Map<string, GraphCanvasNode>());
  const edges = ref(new Map<string, GraphCanvasEdge>());
  const focusedId = ref<string>("");
  const progress = ref<GraphLoadProgress>({
    expanded: 0,
    pending: 0,
    inflight: 0,
    total: 0,
    capped: false
  });

  const focusedNode = computed(() => nodes.value.get(focusedId.value) ?? null);

  async function bootstrap(siteId: string) {
    loading.value = true;
    error.value = "";
    progress.value = { expanded: 0, pending: 0, inflight: 0, total: 0, capped: false };
    try {
      const site = await fetchMySite(siteId, FRIEND_LINK_PAGE_SIZE);
      mySite.value = site;
      const summary = site.summary;
      if (!summary || !summary.nodeId) {
        throw new Error("当前站点尚未建立主星节点，请先在接入配置完成首次同步");
      }
      const selfId = summary.nodeId;
      const selfCanvasNode: GraphCanvasNode = {
        id: selfId,
        kind: "self",
        nodeId: selfId,
        siteId: summary.siteId,
        title: summary.name,
        galaxyName: summary.nodeName || summary.name,
        subtitle: summary.url,
        url: summary.url,
        avatar: summary.avatar
      };

      const allNodes = await fetchAllNodes();
      const seededMap = new Map<string, GraphCanvasNode>();
      seededMap.set(selfId, selfCanvasNode);
      const seedIds: string[] = [selfId];
      for (const item of allNodes) {
        const node = nodeListItemToCanvasNode(item);
        if (!node.id) continue;
        if (node.id === selfId) continue;
        if (seededMap.has(node.id)) continue;
        seededMap.set(node.id, node);
        seedIds.push(node.id);
      }
      nodes.value = seededMap;
      focusedId.value = selfId;
      bumpProgress();

      await crawlAll(seedIds);
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e);
    } finally {
      loading.value = false;
    }
  }

  function focusOn(canvasNodeId: string) {
    if (!nodes.value.has(canvasNodeId)) return;
    focusedId.value = canvasNodeId;
  }

  async function reset(siteId: string) {
    nodeCache.clear();
    nodes.value = new Map();
    edges.value = new Map();
    focusedId.value = "";
    mySite.value = null;
    progress.value = { expanded: 0, pending: 0, inflight: 0, total: 0, capped: false };
    await bootstrap(siteId);
  }

  // 分页拉完 /v1/graph/nodes 的所有节点（按推荐度排序，活跃节点排前）。
  async function fetchAllNodes(): Promise<GraphNodeListItem[]> {
    const collected: GraphNodeListItem[] = [];
    let page = 1;
    while (collected.length < BFS_MAX_NODES) {
      const payload = await fetchGraphNodes(page, NODES_FETCH_PAGE_SIZE, "recommendation");
      const items = payload.items ?? [];
      if (items.length === 0) break;
      collected.push(...items);
      const total = typeof payload.total === "number" ? payload.total : collected.length;
      if (collected.length >= total) break;
      page += 1;
    }
    return collected.slice(0, BFS_MAX_NODES);
  }

  // 多种子 BFS：所有 seed 入队，逐个展开 friend links。
  async function crawlAll(seedNodeIds: string[]) {
    const queue: string[] = [];
    const queued = new Set<string>();
    for (const id of seedNodeIds) {
      if (!id || queued.has(id)) continue;
      queued.add(id);
      queue.push(id);
    }
    let inflight = 0;
    progress.value = { ...progress.value, pending: queue.length, inflight };

    return new Promise<void>((resolve) => {
      const tick = () => {
        if (queue.length === 0 && inflight === 0) {
          progress.value = { ...progress.value, pending: 0, inflight: 0 };
          resolve();
          return;
        }
        if (nodes.value.size >= BFS_MAX_NODES) {
          progress.value = { ...progress.value, capped: true, pending: 0 };
          if (inflight === 0) resolve();
          return;
        }
        while (inflight < BFS_MAX_CONCURRENCY && queue.length > 0) {
          const next = queue.shift();
          if (!next) break;
          inflight += 1;
          progress.value = { ...progress.value, pending: queue.length, inflight };
          expandFriendLinks(next)
            .then((newNeighbors) => {
              for (const id of newNeighbors) {
                if (queued.has(id)) continue;
                if (nodes.value.size >= BFS_MAX_NODES) break;
                queued.add(id);
                queue.push(id);
              }
              progress.value = {
                ...progress.value,
                pending: queue.length,
                expanded: progress.value.expanded + 1,
                total: nodes.value.size
              };
            })
            .catch((err) => {
              console.warn("[RelationGraph] expand failed", next, err);
            })
            .finally(() => {
              inflight -= 1;
              progress.value = { ...progress.value, inflight };
              tick();
            });
        }
      };
      tick();
    });
  }

  async function expandFriendLinks(canvasNodeId: string): Promise<string[]> {
    const canvasNode = nodes.value.get(canvasNodeId);
    if (!canvasNode || !canvasNode.nodeId) return [];
    if (nodeCache.has(canvasNode.nodeId)) return [];
    const detail = await fetchGraphNode(canvasNode.nodeId, FRIEND_LINK_PAGE_SIZE);
    nodeCache.set(canvasNode.nodeId, detail);
    const nextNodes = new Map(nodes.value);
    const nextEdges = new Map(edges.value);
    const newRegistered: string[] = [];
    for (const link of detail.friendLinks ?? []) {
      const friendNode = friendLinkToCanvasNode(link);
      const existing = nextNodes.get(friendNode.id);
      if (!existing) {
        nextNodes.set(friendNode.id, friendNode);
        if (friendNode.kind === "registered") newRegistered.push(friendNode.id);
      } else if (existing.kind === "unregistered" && friendNode.kind === "registered") {
        nextNodes.set(friendNode.id, friendNode);
        newRegistered.push(friendNode.id);
      }
      const edgeKey = friendEdgeKey(canvasNodeId, friendNode.id);
      if (!nextEdges.has(edgeKey)) {
        nextEdges.set(edgeKey, { id: edgeKey, source: canvasNodeId, target: friendNode.id });
      }
    }
    nodes.value = nextNodes;
    edges.value = nextEdges;
    return newRegistered;
  }

  function bumpProgress() {
    progress.value = { ...progress.value, total: nodes.value.size };
  }

  return {
    loading,
    error,
    nodes,
    edges,
    focusedId,
    focusedNode,
    mySite,
    progress,
    bootstrap,
    focusOn,
    reset
  };
}

function friendLinkToCanvasNode(link: GraphNodeFriendLink): GraphCanvasNode {
  if (link.targetRegistered && link.targetNodeId) {
    return {
      id: link.targetNodeId,
      kind: "registered",
      nodeId: link.targetNodeId,
      siteId: link.targetSiteId,
      title: link.title || link.targetNodeName || link.url,
      galaxyName: link.targetNodeName,
      subtitle: link.url,
      url: link.url,
      rssUrl: link.rssUrl,
      description: link.description,
      avatar: link.targetAvatar || link.logo,
      raw: link
    };
  }
  return {
    id: `url:${normalizeUrl(link.url)}`,
    kind: "unregistered",
    title: link.title || link.url,
    subtitle: link.url,
    url: link.url,
    rssUrl: link.rssUrl,
    description: link.description,
    avatar: link.logo,
    raw: link
  };
}

function nodeListItemToCanvasNode(item: GraphNodeListItem): GraphCanvasNode {
  const summary = item.summary;
  return {
    id: summary.nodeId,
    kind: "registered",
    nodeId: summary.nodeId,
    siteId: summary.primarySite?.siteId,
    title: summary.name,
    galaxyName: summary.name,
    subtitle: summary.primarySite?.url,
    url: summary.primarySite?.url,
    avatar: summary.avatar
  };
}

function friendEdgeKey(a: string, b: string): string {
  const [x, y] = a < b ? [a, b] : [b, a];
  return `friend|${x}|${y}`;
}

function normalizeUrl(raw: string): string {
  return String(raw || "").trim().toLowerCase().replace(/\/+$/, "");
}
