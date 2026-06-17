<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import ForceGraph3D, { type ForceGraph3DInstance } from "3d-force-graph";
import * as THREE from "three";
import { graphAvatarProxyUrl } from "../api/client";
import { useStatus } from "../composables/useStatus";
import {
  useRelationGraph,
  type GraphCanvasEdge,
  type GraphCanvasNode,
} from "../composables/useRelationGraph";

// 默认头像（深蓝星球 SVG），对齐 Halo 端 data/defaultAvatar 的内联常量。
const DEFAULT_AVATAR_DATA_URI = `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(
  `<svg viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg"><path d="M512 512m-512 0a512 512 0 1 0 1024 0 512 512 0 1 0-1024 0Z" fill="#1A4066"/><path d="M512 300a150 150 0 1 0 0 300 150 150 0 0 0 0-300zM330 760a182 182 0 0 1 364 0z" fill="#CBD5D8"/></svg>`
)}`;

const props = defineProps<{
  refreshSignal?: number;
}>();

const { credentials } = useStatus();

const {
  loading,
  error,
  nodes,
  edges,
  focusedId,
  progress,
  focusOn,
  reset,
} = useRelationGraph();

interface ForceNode {
  id: string;
  raw: GraphCanvasNode;
  x?: number;
  y?: number;
  z?: number;
  fx?: number;
  fy?: number;
  fz?: number;
  // 节点连接度；0 = 孤立节点（球外壳环绕），>=1 = 收在球内。
  __degree?: number;
}

interface ForceLink {
  source: string;
  target: string;
  raw: GraphCanvasEdge;
}

const canvasRef = ref<HTMLDivElement | null>(null);
const wrapRef = ref<HTMLDivElement | null>(null);
let graph: ForceGraph3DInstance<ForceNode, ForceLink> | null = null;
let resizeObserver: ResizeObserver | null = null;
let decorStars: THREE.Points | null = null;
const NODE_BOUNDARY_RADIUS = 200;
const NODE_SPAWN_RADIUS = NODE_BOUNDARY_RADIUS * 0.45;

const credentialReady = computed(() => Boolean(credentials.value.siteId));
const isFullscreen = ref(false);
const autoRotate = ref(true);
const selectedNode = ref<GraphCanvasNode | null>(null);
const detailScreenPos = ref<{ x: number; y: number; visible: boolean } | null>(null);
let detailRafHandle: number | null = null;

const searchQuery = ref("");
const searchOpen = ref(false);
const searchWrapRef = ref<HTMLDivElement | null>(null);

const showProgress = computed(() => {
  return loading.value || progress.value.inflight > 0 || progress.value.pending > 0;
});

const searchSuggestions = computed<GraphCanvasNode[]>(() => {
  const keyword = searchQuery.value.trim().toLowerCase();
  if (!keyword) return [];
  const out: GraphCanvasNode[] = [];
  for (const node of nodes.value.values()) {
    const title = (node.title || "").toLowerCase();
    const url = (node.url || "").toLowerCase();
    const galaxy = (node.galaxyName || "").toLowerCase();
    if (title.includes(keyword) || url.includes(keyword) || galaxy.includes(keyword)) {
      out.push(node);
      if (out.length >= 30) break;
    }
  }
  out.sort((a, b) => {
    if (a.kind !== b.kind) {
      if (a.kind === "unregistered") return 1;
      if (b.kind === "unregistered") return -1;
    }
    return (a.title || "").localeCompare(b.title || "");
  });
  return out;
});

function buildSuggestionAvatarSrc(node: GraphCanvasNode): string {
  if (!node.avatar) return DEFAULT_AVATAR_DATA_URI;
  return graphAvatarProxyUrl(node.avatar);
}

function onSuggestionAvatarError(event: Event) {
  const img = event.target as HTMLImageElement | null;
  if (!img) return;
  if (img.src === DEFAULT_AVATAR_DATA_URI) return;
  img.src = DEFAULT_AVATAR_DATA_URI;
}

function handleSearchSelect(node: GraphCanvasNode) {
  selectedNode.value = node;
  searchOpen.value = false;
  searchQuery.value = "";
  if (node.id !== focusedId.value) {
    focusOn(node.id);
  } else {
    flyToNode(node.id);
  }
}

function onSearchDocumentClick(event: MouseEvent) {
  const root = searchWrapRef.value;
  if (!root) return;
  const target = event.target;
  if (target instanceof Node && root.contains(target)) return;
  searchOpen.value = false;
}

function resetToOverview() {
  selectedNode.value = null;
  hoveredNodeId.value = null;
  searchQuery.value = "";
  searchOpen.value = false;
  if (!graph) return;
  graph.cameraPosition(
    { x: 0, y: 0, z: NODE_BOUNDARY_RADIUS * 2.6 },
    { x: 0, y: 0, z: 0 },
    800
  );
}

onMounted(async () => {
  if (!canvasRef.value) return;
  initGraph(canvasRef.value);
  document.addEventListener("fullscreenchange", onFullscreenChange);
  document.addEventListener("mousedown", onSearchDocumentClick);
  if (credentialReady.value) {
    await reset(credentials.value.siteId);
  }
});

onBeforeUnmount(() => {
  document.removeEventListener("fullscreenchange", onFullscreenChange);
  document.removeEventListener("mousedown", onSearchDocumentClick);
  cancelDetailScreenPos();
  cancelLinkFlowRaf();
  for (const material of linkMaterialByEdgeId.values()) {
    material.dispose();
  }
  linkMaterialByEdgeId.clear();
  if (resizeObserver) {
    resizeObserver.disconnect();
    resizeObserver = null;
  }
  if (decorStars) {
    decorStars.geometry.dispose();
    if (decorStars.material instanceof THREE.Material) {
      decorStars.material.dispose();
    }
    decorStars = null;
  }
  for (const tex of avatarTextureCache.values()) {
    tex.dispose();
  }
  avatarTextureCache.clear();
  for (const tex of haloTextureCache.values()) {
    tex.dispose();
  }
  haloTextureCache.clear();
  for (const tex of coreTextureCache.values()) {
    tex.dispose();
  }
  coreTextureCache.clear();
  spriteByNodeIdGroup.clear();
  if (graph) {
    graph._destructor();
    graph = null;
  }
});

watch(
  () => credentials.value.siteId,
  async (next, prev) => {
    if (next && next !== prev) {
      await reset(next);
    }
  }
);

watch(
  () => props.refreshSignal,
  async (next, prev) => {
    if (typeof next !== "number" || next === prev) return;
    if (!credentialReady.value) return;
    await reset(credentials.value.siteId);
  }
);

watch(
  [nodes, edges],
  () => {
    if (!graph) return;
    pushDataToGraph();
  },
  { deep: false }
);

watch(focusedId, (next) => {
  if (!graph || !next) return;
  refreshLinkVisuals();
  flyToNode(next);
});

function initGraph(container: HTMLDivElement) {
  graph = new ForceGraph3D<ForceNode, ForceLink>(container, {
    controlType: "orbit",
    rendererConfig: {
      antialias: true,
      alpha: true,
    },
  });

  graph
    .backgroundColor("rgba(0,0,0,0)")
    .showNavInfo(false)
    .nodeId("id")
    .linkSource("source")
    .linkTarget("target")
    .nodeLabel((node) => buildNodeLabel((node as ForceNode).raw))
    .linkLabel(() => "")
    .nodeRelSize(2.5)
    .nodeOpacity(0)
    .nodeThreeObject((node) => buildStarPoint((node as ForceNode).raw))
    .nodeThreeObjectExtend(false)
    .linkThreeObject((link) => buildLineLink((link as ForceLink).raw))
    .linkThreeObjectExtend(false)
    .linkPositionUpdate((obj, coords, link) => {
      updateLineLinkPosition(obj as THREE.Line, coords, (link as ForceLink).raw);
      return true;
    })
    .showPointerCursor((obj) => {
      if (!obj) return false;
      return Boolean((obj as ForceNode).raw);
    })
    .onNodeClick((node) => {
      const data = (node as ForceNode).raw;
      selectedNode.value = data;
      if (data.id !== focusedId.value) {
        focusOn(data.id);
      } else if (graph) {
        flyToNode(data.id);
      }
    })
    .onBackgroundClick(() => {
      selectedNode.value = null;
    })
    .onNodeHover((node) => {
      hoveredNodeId.value = node ? (node as ForceNode).raw.id : null;
    });

  const charge = graph.d3Force("charge");
  if (
    charge &&
    typeof (charge as unknown as { strength: (v: number) => unknown }).strength === "function"
  ) {
    (charge as unknown as { strength: (v: number) => unknown }).strength(-120);
  }
  const link = graph.d3Force("link");
  if (
    link &&
    typeof (link as unknown as { distance: (v: number) => unknown }).distance === "function"
  ) {
    (link as unknown as { distance: (v: number) => unknown }).distance(36);
  }

  graph.d3Force("boundary", buildBoundaryForce(NODE_BOUNDARY_RADIUS));

  const controls = graph.controls() as unknown as {
    minDistance?: number;
    maxDistance?: number;
    autoRotate?: boolean;
    autoRotateSpeed?: number;
  } | null;
  if (controls) {
    controls.minDistance = NODE_BOUNDARY_RADIUS * 0.25;
    controls.maxDistance = NODE_BOUNDARY_RADIUS * 5;
    controls.autoRotate = autoRotate.value;
    controls.autoRotateSpeed = 0.6;
  }

  resizeObserver = new ResizeObserver(() => {
    if (!graph || !container) return;
    graph.width(container.clientWidth);
    graph.height(container.clientHeight);
  });
  resizeObserver.observe(container);
  graph.width(container.clientWidth);
  graph.height(container.clientHeight);

  decorStars = buildDecorStars();
  graph.scene().add(decorStars);

  graph.cameraPosition(
    { x: 0, y: 0, z: NODE_BOUNDARY_RADIUS * 2.6 },
    { x: 0, y: 0, z: 0 },
    0
  );
}

function pushDataToGraph() {
  if (!graph) return;
  const previousNodes = graph.graphData().nodes;
  const positionById = new Map<string, ForceNode>();
  for (const n of previousNodes) {
    positionById.set(n.id, n);
  }

  const nodeList: ForceNode[] = [];
  for (const [id, raw] of nodes.value) {
    const existing = positionById.get(id);
    if (existing) {
      nodeList.push(existing);
      existing.raw = raw;
      continue;
    }
    const seed = randomPointInBall(NODE_SPAWN_RADIUS);
    nodeList.push({ id, raw, x: seed.x, y: seed.y, z: seed.z });
  }
  const linkList: ForceLink[] = [];
  for (const edge of edges.value.values()) {
    linkList.push({ source: edge.source, target: edge.target, raw: edge });
  }
  // 计算每个节点连接度（无向：source/target 各 +1），供边界力分区。
  const degreeById = new Map<string, number>();
  for (const link of linkList) {
    degreeById.set(link.source, (degreeById.get(link.source) ?? 0) + 1);
    degreeById.set(link.target, (degreeById.get(link.target) ?? 0) + 1);
  }
  for (const node of nodeList) {
    node.__degree = degreeById.get(node.id) ?? 0;
  }
  graph.graphData({ nodes: nodeList, links: linkList });
}

function randomPointInBall(radius: number): { x: number; y: number; z: number } {
  const u = Math.random();
  const r = radius * Math.cbrt(u);
  const cosTheta = Math.random() * 2 - 1;
  const sinTheta = Math.sqrt(1 - cosTheta * cosTheta);
  const phi = Math.random() * Math.PI * 2;
  return {
    x: r * sinTheta * Math.cos(phi),
    y: r * sinTheta * Math.sin(phi),
    z: r * cosTheta,
  };
}

const POINT_SIZE = {
  self: 6,
  registered: 4,
  unregistered: 2.4,
} as const;

const PLANET_PALETTE = [
  "#ffffff",
  "#bfdbfe",
  "#bbf7d0",
  "#ddd6fe",
  "#fbcfe8",
  "#fde68a",
  "#fed7aa",
  "#a5f3fc",
  "#fef3c7",
] as const;

function hashStringToIndex(input: string, modulo: number): number {
  let h = 2166136261;
  for (let i = 0; i < input.length; i++) {
    h ^= input.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return Math.abs(h) % modulo;
}

function pickPlanetColor(node: GraphCanvasNode): string {
  if (node.kind === "self") return "#ffffff";
  if (node.kind === "unregistered") return "#cbd5e1";
  return PLANET_PALETTE[hashStringToIndex(node.id, PLANET_PALETTE.length)];
}

const BASE_TEXTURE_SIZE = 128;

const spriteByNodeIdGroup = new Map<string, THREE.Group>();
const avatarTextureCache = new Map<string, THREE.Texture>();
const haloTextureCache = new Map<string, THREE.Texture>();
const coreTextureCache = new Map<string, THREE.Texture>();

function getHaloTexture(color: string): THREE.Texture {
  const cached = haloTextureCache.get(color);
  if (cached) return cached;
  const dpr = 2;
  const canvas = document.createElement("canvas");
  canvas.width = BASE_TEXTURE_SIZE * dpr;
  canvas.height = BASE_TEXTURE_SIZE * dpr;
  const ctx = canvas.getContext("2d");
  if (ctx) {
    ctx.scale(dpr, dpr);
    drawHalo(ctx, color);
  }
  const tex = new THREE.CanvasTexture(canvas);
  tex.minFilter = THREE.LinearFilter;
  tex.magFilter = THREE.LinearFilter;
  tex.colorSpace = THREE.SRGBColorSpace;
  haloTextureCache.set(color, tex);
  return tex;
}

function drawHalo(ctx: CanvasRenderingContext2D, color: string) {
  const cx = BASE_TEXTURE_SIZE / 2;
  const cy = BASE_TEXTURE_SIZE / 2;
  ctx.clearRect(0, 0, BASE_TEXTURE_SIZE, BASE_TEXTURE_SIZE);
  const gradient = ctx.createRadialGradient(cx, cy, 0, cx, cy, BASE_TEXTURE_SIZE / 2);
  gradient.addColorStop(0, color);
  gradient.addColorStop(0.3, color);
  gradient.addColorStop(0.55, hexToRgba(color, 0.45));
  gradient.addColorStop(1, hexToRgba(color, 0));
  ctx.fillStyle = gradient;
  ctx.fillRect(0, 0, BASE_TEXTURE_SIZE, BASE_TEXTURE_SIZE);
}

function hexToRgba(hex: string, alpha: number): string {
  const value = hex.replace("#", "");
  const r = parseInt(value.slice(0, 2), 16);
  const g = parseInt(value.slice(2, 4), 16);
  const b = parseInt(value.slice(4, 6), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}

function buildStarPoint(node: GraphCanvasNode): THREE.Object3D {
  const group = new THREE.Group();
  const size = POINT_SIZE[node.kind];
  const color = pickPlanetColor(node);

  const coreSprite = new THREE.Sprite(
    new THREE.SpriteMaterial({
      map: getCoreTexture(color),
      transparent: false,
      alphaTest: 0.5,
      opacity: 1,
      depthWrite: true,
      depthTest: true,
    })
  );
  coreSprite.scale.set(size * 0.45, size * 0.45, 1);
  coreSprite.renderOrder = 2;
  group.add(coreSprite);

  const halo = new THREE.Sprite(
    new THREE.SpriteMaterial({
      map: getHaloTexture(color),
      transparent: true,
      opacity: 0.55,
      depthWrite: false,
      depthTest: false,
    })
  );
  halo.scale.set(size, size, 1);
  halo.renderOrder = 1;
  group.add(halo);

  const remoteAvatar = node.avatar;
  if (remoteAvatar) {
    void loadAvatarTexture(remoteAvatar, color).then((tex) => {
      if (!tex) return;
      coreSprite.material.map = tex;
      coreSprite.material.opacity = 1;
      coreSprite.material.needsUpdate = true;
      coreSprite.scale.set(size * 0.85, size * 0.85, 1);
    });
  }

  spriteByNodeIdGroup.set(node.id, group);
  return group;
}

function getCoreTexture(color: string): THREE.Texture {
  const cached = coreTextureCache.get(color);
  if (cached) return cached;
  const dpr = 2;
  const canvas = document.createElement("canvas");
  canvas.width = BASE_TEXTURE_SIZE * dpr;
  canvas.height = BASE_TEXTURE_SIZE * dpr;
  const ctx = canvas.getContext("2d");
  if (ctx) {
    ctx.scale(dpr, dpr);
    drawCore(ctx, color);
  }
  const tex = new THREE.CanvasTexture(canvas);
  tex.minFilter = THREE.LinearFilter;
  tex.magFilter = THREE.LinearFilter;
  tex.colorSpace = THREE.SRGBColorSpace;
  coreTextureCache.set(color, tex);
  return tex;
}

function drawCore(ctx: CanvasRenderingContext2D, color: string) {
  const cx = BASE_TEXTURE_SIZE / 2;
  const cy = BASE_TEXTURE_SIZE / 2;
  ctx.clearRect(0, 0, BASE_TEXTURE_SIZE, BASE_TEXTURE_SIZE);
  ctx.save();
  ctx.beginPath();
  ctx.arc(cx, cy, BASE_TEXTURE_SIZE * 0.42, 0, Math.PI * 2);
  ctx.closePath();
  const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, BASE_TEXTURE_SIZE * 0.42);
  grad.addColorStop(0, color);
  grad.addColorStop(0.85, color);
  grad.addColorStop(1, hexToRgba(color, 0));
  ctx.fillStyle = grad;
  ctx.fill();
  ctx.restore();
}

async function loadAvatarTexture(remoteUrl: string, haloColor: string): Promise<THREE.Texture | null> {
  const cached = avatarTextureCache.get(remoteUrl);
  if (cached) return cached;
  const proxyUrl = graphAvatarProxyUrl(remoteUrl);
  const img = await loadImage(proxyUrl).catch(() => loadImage(DEFAULT_AVATAR_DATA_URI));
  if (!img) return null;
  const dpr = 2;
  const canvas = document.createElement("canvas");
  canvas.width = BASE_TEXTURE_SIZE * dpr;
  canvas.height = BASE_TEXTURE_SIZE * dpr;
  const ctx = canvas.getContext("2d");
  if (!ctx) return null;
  ctx.scale(dpr, dpr);
  drawAvatarTile(ctx, img, haloColor);
  const tex = new THREE.CanvasTexture(canvas);
  tex.minFilter = THREE.LinearFilter;
  tex.magFilter = THREE.LinearFilter;
  tex.colorSpace = THREE.SRGBColorSpace;
  avatarTextureCache.set(remoteUrl, tex);
  return tex;
}

function loadImage(src: string): Promise<HTMLImageElement | null> {
  return new Promise((resolve) => {
    const img = new Image();
    img.crossOrigin = "anonymous";
    img.onload = () => resolve(img);
    img.onerror = () => resolve(null);
    img.src = src;
  });
}

function drawAvatarTile(
  ctx: CanvasRenderingContext2D,
  img: HTMLImageElement,
  haloColor: string
) {
  const cx = BASE_TEXTURE_SIZE / 2;
  const cy = BASE_TEXTURE_SIZE / 2;
  ctx.clearRect(0, 0, BASE_TEXTURE_SIZE, BASE_TEXTURE_SIZE);

  const halo = ctx.createRadialGradient(cx, cy, BASE_TEXTURE_SIZE * 0.32, cx, cy, BASE_TEXTURE_SIZE / 2);
  halo.addColorStop(0, "rgba(255,255,255,0)");
  halo.addColorStop(0.5, hexToRgba(haloColor, 0.35));
  halo.addColorStop(1, hexToRgba(haloColor, 0));
  ctx.fillStyle = halo;
  ctx.fillRect(0, 0, BASE_TEXTURE_SIZE, BASE_TEXTURE_SIZE);

  const avatarRadius = BASE_TEXTURE_SIZE * 0.36;
  ctx.save();
  ctx.beginPath();
  ctx.arc(cx, cy, avatarRadius, 0, Math.PI * 2);
  ctx.closePath();
  ctx.clip();
  ctx.drawImage(img, cx - avatarRadius, cy - avatarRadius, avatarRadius * 2, avatarRadius * 2);
  ctx.restore();

  ctx.beginPath();
  ctx.arc(cx, cy, avatarRadius, 0, Math.PI * 2);
  ctx.lineWidth = 1.2;
  ctx.strokeStyle = "rgba(255,255,255,0.65)";
  ctx.stroke();
}

function buildNodeLabel(node: GraphCanvasNode): string {
  const safe = (s: string | undefined) => (s ? escapeHtml(s) : "");
  const tagText =
    node.kind === "self"
      ? "我的站点"
      : node.kind === "registered"
      ? "已接入"
      : "未接入";
  const tagColor =
    node.kind === "self"
      ? "#f59e0b"
      : node.kind === "registered"
      ? "#60a5fa"
      : "#94a3b8";
  return `
    <div style="display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;background:rgba(15,23,42,0.78);border:1px solid rgba(148,163,184,0.35);color:#e2e8f0;font-size:12px;font-family:system-ui,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.4);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);">
      <span style="font-weight:700;">${safe(node.title)}</span>
      <span style="font-size:10px;color:${tagColor};">· ${tagText}</span>
    </div>
  `;
}

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

const LINK_BASE_COLOR = "#dbe2ec";
const LINK_ACTIVE_COLOR = "#fde68a";
const LINK_BASE_OPACITY = 0.07;
const LINK_SEGMENTS = 32;

const LINK_VERTEX_SHADER = `
  attribute float aProgress;
  varying float vProgress;
  void main() {
    vProgress = aProgress;
    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
  }
`;
const LINK_FRAGMENT_SHADER = `
  uniform float uTime;
  uniform float uActive;
  uniform vec3 uBaseColor;
  uniform vec3 uActiveColor;
  uniform float uBaseOpacity;
  varying float vProgress;
  void main() {
    if (uActive < 0.5) {
      gl_FragColor = vec4(uBaseColor, uBaseOpacity);
      return;
    }
    float phase = fract(uTime * 0.6);
    float dist = vProgress - phase;
    dist = dist - floor(dist + 0.5);
    float pulse = exp(- dist * dist * 60.0);
    vec3 col = uActiveColor;
    float a = mix(0.32, 0.55, pulse);
    gl_FragColor = vec4(col, a);
  }
`;

const linkMaterialByEdgeId = new Map<string, THREE.ShaderMaterial>();

function buildLineLink(edge: GraphCanvasEdge): THREE.Line {
  const previousMaterial = linkMaterialByEdgeId.get(edge.id);
  if (previousMaterial) {
    previousMaterial.dispose();
  }
  const geometry = new THREE.BufferGeometry();
  const positions = new Float32Array((LINK_SEGMENTS + 1) * 3);
  const progress = new Float32Array(LINK_SEGMENTS + 1);
  for (let i = 0; i <= LINK_SEGMENTS; i++) progress[i] = i / LINK_SEGMENTS;
  geometry.setAttribute("position", new THREE.BufferAttribute(positions, 3));
  geometry.setAttribute("aProgress", new THREE.BufferAttribute(progress, 1));
  const initialActive = isEdgeActiveNow(edge) ? 1 : 0;
  const material = new THREE.ShaderMaterial({
    uniforms: {
      uTime: { value: 0 },
      uActive: { value: initialActive },
      uBaseColor: { value: new THREE.Color(LINK_BASE_COLOR) },
      uActiveColor: { value: new THREE.Color(LINK_ACTIVE_COLOR) },
      uBaseOpacity: { value: LINK_BASE_OPACITY },
    },
    vertexShader: LINK_VERTEX_SHADER,
    fragmentShader: LINK_FRAGMENT_SHADER,
    transparent: true,
    depthWrite: false,
  });
  const line = new THREE.Line(geometry, material);
  line.renderOrder = -1;
  linkMaterialByEdgeId.set(edge.id, material);
  if (initialActive) {
    startLinkFlowRaf();
  }
  return line;
}

function isEdgeActiveNow(edge: GraphCanvasEdge): boolean {
  const focusId = selectedNode.value?.id || hoveredNodeId.value;
  if (!focusId) return false;
  return edge.source === focusId || edge.target === focusId;
}

function updateLineLinkPosition(
  line: THREE.Line,
  coords: { start: { x: number; y: number; z: number }; end: { x: number; y: number; z: number } },
  _edge: GraphCanvasEdge
) {
  const positionAttr = (line.geometry as THREE.BufferGeometry).getAttribute(
    "position"
  ) as THREE.BufferAttribute;
  if (!positionAttr) return;

  const { start, end } = coords;
  const sx = start.x, sy = start.y, sz = start.z;
  const ex = end.x, ey = end.y, ez = end.z;
  const mx = (sx + ex) / 2;
  const my = (sy + ey) / 2;
  const mz = (sz + ez) / 2;
  const midLen = Math.hypot(mx, my, mz) || 1;
  const chord = Math.hypot(ex - sx, ey - sy, ez - sz) || 1;
  const bend = chord * 0.18;
  const cx = mx + (mx / midLen) * bend;
  const cy = my + (my / midLen) * bend;
  const cz = mz + (mz / midLen) * bend;

  for (let i = 0; i <= LINK_SEGMENTS; i++) {
    const t = i / LINK_SEGMENTS;
    const omt = 1 - t;
    const x = omt * omt * sx + 2 * omt * t * cx + t * t * ex;
    const y = omt * omt * sy + 2 * omt * t * cy + t * t * ey;
    const z = omt * omt * sz + 2 * omt * t * cz + t * t * ez;
    positionAttr.setXYZ(i, x, y, z);
  }
  positionAttr.needsUpdate = true;
}

function refreshLinkVisuals() {
  if (!graph) return;
  graph.refresh();
}

const hoveredNodeId = ref<string | null>(null);

const activeEdgeIds = computed<Set<string>>(() => {
  const ids = new Set<string>();
  const focusId = selectedNode.value?.id || hoveredNodeId.value;
  if (!focusId) return ids;
  for (const edge of edges.value.values()) {
    if (edge.source === focusId || edge.target === focusId) {
      ids.add(edge.id);
    }
  }
  return ids;
});

let linkFlowRafHandle: number | null = null;

function tickLinkFlow() {
  if (activeEdgeIds.value.size === 0) {
    linkFlowRafHandle = null;
    return;
  }
  const t = performance.now() / 1000;
  for (const material of linkMaterialByEdgeId.values()) {
    if (material.uniforms.uActive.value > 0.5) {
      material.uniforms.uTime.value = t;
    }
  }
  linkFlowRafHandle = requestAnimationFrame(tickLinkFlow);
}

function startLinkFlowRaf() {
  if (linkFlowRafHandle != null) return;
  if (typeof requestAnimationFrame !== "function") return;
  linkFlowRafHandle = requestAnimationFrame(tickLinkFlow);
}

function cancelLinkFlowRaf() {
  if (linkFlowRafHandle != null && typeof cancelAnimationFrame === "function") {
    cancelAnimationFrame(linkFlowRafHandle);
  }
  linkFlowRafHandle = null;
}

watch(activeEdgeIds, (next) => {
  for (const [edgeId, material] of linkMaterialByEdgeId.entries()) {
    material.uniforms.uActive.value = next.has(edgeId) ? 1 : 0;
  }
  if (next.size > 0) {
    startLinkFlowRaf();
  }
});

function flyToNode(canvasNodeId: string) {
  if (!graph) return;
  setTimeout(() => {
    if (!graph) return;
    const node = findForceNodeById(canvasNodeId);
    if (!node || typeof node.x !== "number") return;

    const target = { x: node.x, y: node.y ?? 0, z: node.z ?? 0 };
    const flightDistance = 60;
    const cameraPos = graph.cameraPosition() as { x: number; y: number; z: number };
    let dx = cameraPos.x - target.x;
    let dy = cameraPos.y - target.y;
    let dz = cameraPos.z - target.z;
    let dirLen = Math.hypot(dx, dy, dz);
    if (dirLen < 1e-3) {
      dx = 0;
      dy = 0;
      dz = 1;
      dirLen = 1;
    }
    const newPos = {
      x: target.x + (dx / dirLen) * flightDistance,
      y: target.y + (dy / dirLen) * flightDistance,
      z: target.z + (dz / dirLen) * flightDistance,
    };
    graph.cameraPosition(newPos, target, 800);
  }, 80);
}

function findForceNodeById(id: string): ForceNode | null {
  if (!graph) return null;
  const data = graph.graphData();
  for (const n of data.nodes) {
    if (n.id === id) return n;
  }
  return null;
}

function buildBoundaryForce(radius: number) {
  const innerRadius = radius;
  const innerRadiusSq = innerRadius * innerRadius;
  // 孤立节点（degree===0）的外壳：明显在球外（1.7×），环绕主球展示。
  const isolatedRadius = radius * 1.7;
  // 软回推按 alpha 衰减，冷却后推不动；这里再叠一道无视 alpha 的硬钳，确保 degree>=1 收在球内。
  const hardInnerRadius = innerRadius * 1.02;
  let nodes: ForceNode[] = [];
  const force = (alpha: number) => {
    const k = 0.18 * alpha;
    for (const node of nodes) {
      const x = node.x ?? 0;
      const y = node.y ?? 0;
      const z = node.z ?? 0;
      const degree = node.__degree ?? 0;
      if (degree === 0) {
        // 孤立节点：每帧无视 alpha 硬钉到外壳球面，只保留切向速度形成环绕。
        const n0 = node as unknown as Record<string, number>;
        const dist = Math.sqrt(x * x + y * y + z * z);
        if (dist < 1e-6) {
          node.x = isolatedRadius;
          node.y = 0;
          node.z = 0;
          n0.vx = 0;
          n0.vy = 0;
          n0.vz = 0;
          continue;
        }
        const scale = isolatedRadius / dist;
        node.x = x * scale;
        node.y = y * scale;
        node.z = z * scale;
        const vx = n0.vx ?? 0;
        const vy = n0.vy ?? 0;
        const vz = n0.vz ?? 0;
        const radialV = (vx * x + vy * y + vz * z) / (dist * dist);
        n0.vx = vx - radialV * x;
        n0.vy = vy - radialV * y;
        n0.vz = vz - radialV * z;
        continue;
      }
      // 有关联节点：软回推 + 硬钳，收在内球。
      const distSq = x * x + y * y + z * z;
      if (distSq <= innerRadiusSq) continue;
      const dist = Math.sqrt(distSq);
      const overshoot = (dist - innerRadius) / innerRadius;
      const factor = k * (1 + overshoot);
      const n = node as unknown as Record<string, number>;
      n.vx = (n.vx ?? 0) - x * factor;
      n.vy = (n.vy ?? 0) - y * factor;
      n.vz = (n.vz ?? 0) - z * factor;
      if (dist > hardInnerRadius) {
        const scale = hardInnerRadius / dist;
        node.x = x * scale;
        node.y = y * scale;
        node.z = z * scale;
        const vx = n.vx ?? 0;
        const vy = n.vy ?? 0;
        const vz = n.vz ?? 0;
        const radialV = (vx * x + vy * y + vz * z) / distSq;
        if (radialV > 0) {
          n.vx = vx - radialV * x;
          n.vy = vy - radialV * y;
          n.vz = vz - radialV * z;
        }
      }
    }
  };
  (force as unknown as { initialize: (input: ForceNode[]) => void }).initialize = (input) => {
    nodes = input;
  };
  return force;
}

const DECOR_STAR_COUNT = 12000;
const DECOR_SHELL_RADIUS = NODE_BOUNDARY_RADIUS * 1.05;

function buildDecorStars(): THREE.Points {
  const positions = new Float32Array(DECOR_STAR_COUNT * 3);
  const colors = new Float32Array(DECOR_STAR_COUNT * 3);
  for (let i = 0; i < DECOR_STAR_COUNT; i++) {
    const u = Math.random() * 2 - 1;
    const theta = Math.random() * Math.PI * 2;
    const r = Math.sqrt(1 - u * u);
    let radial: number;
    if (Math.random() < 0.7) {
      radial = 0.55 + Math.random() * 0.45;
    } else {
      radial = Math.random() * 0.55;
    }
    positions[i * 3] = r * Math.cos(theta) * radial;
    positions[i * 3 + 1] = u * radial;
    positions[i * 3 + 2] = r * Math.sin(theta) * radial;
    const palette = Math.random();
    if (palette < 0.55) {
      colors[i * 3] = 1;
      colors[i * 3 + 1] = 1;
      colors[i * 3 + 2] = 1;
    } else if (palette < 0.85) {
      colors[i * 3] = 0.55;
      colors[i * 3 + 1] = 0.78;
      colors[i * 3 + 2] = 1;
    } else {
      colors[i * 3] = 1;
      colors[i * 3 + 1] = 0.92;
      colors[i * 3 + 2] = 0.78;
    }
  }
  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute("position", new THREE.BufferAttribute(positions, 3));
  geometry.setAttribute("color", new THREE.BufferAttribute(colors, 3));

  const material = new THREE.PointsMaterial({
    size: 0.8,
    sizeAttenuation: false,
    vertexColors: true,
    transparent: true,
    opacity: 1,
    depthWrite: false,
    blending: THREE.AdditiveBlending,
    map: getDecorStarTexture(),
  });

  const points = new THREE.Points(geometry, material);
  points.scale.setScalar(DECOR_SHELL_RADIUS);
  points.position.set(0, 0, 0);
  points.renderOrder = -2;
  return points;
}

function getDecorStarTexture(): THREE.Texture {
  const size = 32;
  const canvas = document.createElement("canvas");
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext("2d");
  if (ctx) {
    const cx = size / 2;
    const cy = size / 2;
    ctx.clearRect(0, 0, size, size);
    const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, size / 2);
    grad.addColorStop(0, "rgba(255,255,255,1)");
    grad.addColorStop(0.4, "rgba(255,255,255,0.55)");
    grad.addColorStop(1, "rgba(255,255,255,0)");
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, size, size);
  }
  const tex = new THREE.CanvasTexture(canvas);
  tex.minFilter = THREE.LinearFilter;
  tex.magFilter = THREE.LinearFilter;
  tex.colorSpace = THREE.SRGBColorSpace;
  return tex;
}

function toggleFullscreen() {
  const target = wrapRef.value;
  if (!target) return;
  if (document.fullscreenElement) {
    void document.exitFullscreen();
  } else {
    void target.requestFullscreen?.();
  }
}

function onFullscreenChange() {
  isFullscreen.value = Boolean(document.fullscreenElement);
  if (graph && canvasRef.value) {
    graph.width(canvasRef.value.clientWidth);
    graph.height(canvasRef.value.clientHeight);
  }
}

function toggleAutoRotate() {
  autoRotate.value = !autoRotate.value;
  if (!graph) return;
  const controls = graph.controls() as unknown as { autoRotate?: boolean } | null;
  if (controls) {
    controls.autoRotate = autoRotate.value;
  }
}

const selectedAvatarSrc = computed(() => {
  const node = selectedNode.value;
  if (!node || !node.avatar) return DEFAULT_AVATAR_DATA_URI;
  return graphAvatarProxyUrl(node.avatar);
});

function onDetailAvatarError(event: Event) {
  const img = event.target as HTMLImageElement | null;
  if (!img) return;
  if (img.src === DEFAULT_AVATAR_DATA_URI) return;
  img.src = DEFAULT_AVATAR_DATA_URI;
}

const selectedGalaxies = computed<string[]>(() => {
  const node = selectedNode.value;
  if (!node) return [];
  const result: string[] = [];
  const seen = new Set<string>();
  for (const edge of edges.value.values()) {
    let neighborId: string | null = null;
    if (edge.source === node.id) neighborId = edge.target;
    else if (edge.target === node.id) neighborId = edge.source;
    if (!neighborId) continue;
    const neighbor = nodes.value.get(neighborId);
    if (!neighbor) continue;
    if (neighbor.kind !== "self" && neighbor.kind !== "registered") continue;
    if (neighbor.id === node.id) continue;
    const galaxyName = (neighbor.galaxyName || "").trim();
    if (!galaxyName || seen.has(galaxyName)) continue;
    seen.add(galaxyName);
    result.push(galaxyName);
  }
  return result;
});

const selectedGalaxiesText = computed<string>(() => {
  const list = selectedGalaxies.value;
  if (list.length === 0) return "";
  if (list.length <= 3) return list.join("/");
  return list.slice(0, 3).join("/") + "/...";
});

function updateDetailScreenPos() {
  detailRafHandle = null;
  const node = selectedNode.value;
  if (!graph || !node) {
    detailScreenPos.value = null;
    return;
  }
  const force = findForceNodeById(node.id);
  if (
    !force ||
    typeof force.x !== "number" ||
    typeof force.y !== "number" ||
    typeof force.z !== "number"
  ) {
    detailScreenPos.value = null;
    scheduleDetailScreenPos();
    return;
  }
  const graphTyped = graph as unknown as {
    graph2ScreenCoords?: (
      x: number,
      y: number,
      z: number
    ) => { x: number; y: number; z?: number };
  };
  if (typeof graphTyped.graph2ScreenCoords !== "function") {
    detailScreenPos.value = null;
    return;
  }
  const screen = graphTyped.graph2ScreenCoords(force.x, force.y, force.z);
  detailScreenPos.value = {
    x: screen.x,
    y: screen.y,
    visible: true,
  };
  scheduleDetailScreenPos();
}

function scheduleDetailScreenPos() {
  if (detailRafHandle != null) return;
  if (typeof requestAnimationFrame !== "function") return;
  detailRafHandle = requestAnimationFrame(updateDetailScreenPos);
}

function cancelDetailScreenPos() {
  if (detailRafHandle != null && typeof cancelAnimationFrame === "function") {
    cancelAnimationFrame(detailRafHandle);
  }
  detailRafHandle = null;
}

watch(selectedNode, (next) => {
  cancelDetailScreenPos();
  if (next) {
    scheduleDetailScreenPos();
  } else {
    detailScreenPos.value = null;
  }
});

const detailCardStyle = computed<Record<string, string>>(() => {
  const pos = detailScreenPos.value;
  if (!pos || !pos.visible) {
    return { display: "none" };
  }
  const left = Math.max(12, pos.x + 24);
  const top = Math.max(12, pos.y - 60);
  return {
    left: `${left}px`,
    top: `${top}px`,
  };
});
</script>

<template>
  <div class="rg-panel">
    <div v-if="!credentialReady" class="rg-empty-static-wrap">
      <div class="sp-empty-state">
        <svg class="sp-empty-state-icon" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" width="120" height="120">
          <path d="M217.0088 482.0912l315.36-64.8v133.2z" fill="#416191"/>
          <path d="M851.3288 482.0912l-318.96-64.8v133.2zM211.2488 881.6912l321.12 76.32v-419.76l-321.12-52.56z" fill="#5074AE"/>
          <path d="M853.4888 881.6912l-321.12 76.32v-419.76l321.12-52.56z" fill="#40608F"/>
          <path d="M532.3688 538.2512l88.56 169.92 318.96-92.88-88.56-133.2z" fill="#4B6F9B"/>
          <path d="M535.9688 538.2512l-88.56 169.92-318.96-92.88 88.56-133.2z" fill="#6A90C0"/>
          <path d="M459.44 62c-25.92 11.52-43.2 20.88-52.56 28.08-13.68 11.52-28.08 44.64-40.32 48.24-12.24 4.32 48.24-4.32 68.4-15.84 13.68-7.92 21.6-28.08 24.48-60.48zM869.84 314c-32.4-11.52-56.16-17.28-72-16.56-24.48 1.44-61.2 3.6-72.72-1.44-11.52-5.04 42.48 43.92 75.6 47.52 20.88 2.16 43.92-7.92 69.12-29.52zM354.32 324.08c-18.72 7.92-30.96 15.12-36 20.16-7.92 7.92-20.16 20.16-32.4 24.48-12.24 4.32 32.4 7.92 48.24 0 10.8-5.04 18-20.16 20.16-44.64z" fill="#6A90C0"/>
        </svg>
        <div class="sp-empty-state-text">当前站点尚未接入星链，无法加载关系图。</div>
        <div class="sp-empty-state-hint">请先在「接入配置」完成星链接入。</div>
      </div>
    </div>

    <div v-else ref="wrapRef" class="rg-canvas-wrap" :class="{ 'rg-fullscreen': isFullscreen }">
      <div ref="canvasRef" class="rg-canvas"></div>

      <div class="rg-toolbar">
        <div ref="searchWrapRef" class="rg-search">
          <input
            type="text"
            class="rg-search-input"
            placeholder="搜索站点 / 星系 / 链接"
            v-model="searchQuery"
            @focus="searchOpen = true"
            @input="searchOpen = true"
          />
          <button
            v-if="searchQuery"
            class="rg-search-clear"
            type="button"
            title="清空"
            @click="searchQuery = ''; searchOpen = false"
          >
            <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true">
              <path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
            </svg>
          </button>
          <div v-if="searchOpen && searchQuery.trim()" class="rg-search-suggestions">
            <div v-if="searchSuggestions.length === 0" class="rg-search-empty">没有匹配的站点</div>
            <button
              v-for="node in searchSuggestions"
              :key="node.id"
              type="button"
              class="rg-search-item"
              :class="{ active: selectedNode && selectedNode.id === node.id }"
              @click="handleSearchSelect(node)"
            >
              <img
                :src="buildSuggestionAvatarSrc(node)"
                alt=""
                class="rg-search-item-avatar"
                referrerpolicy="no-referrer"
                crossorigin="anonymous"
                @error="onSuggestionAvatarError"
              />
              <div class="rg-search-item-meta">
                <div class="rg-search-item-title">{{ node.title || node.url || "未命名站点" }}</div>
                <div v-if="node.url" class="rg-search-item-sub">{{ node.url }}</div>
              </div>
              <span
                class="rg-search-item-tag"
                :class="node.kind === 'unregistered' ? 'unregistered' : 'registered'"
              >{{ node.kind === "unregistered" ? "未接入" : "已接入" }}</span>
            </button>
          </div>
        </div>
        <button
          class="rg-tool-btn"
          type="button"
          title="重置：取消所有状态，回到整体球视角"
          @click="resetToOverview"
        >
          <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
            <path d="M3 12a9 9 0 1 0 3-6.7L3 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M3 3v5h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
        <button
          class="rg-tool-btn"
          type="button"
          :class="{ active: autoRotate }"
          :title="autoRotate ? '关闭自动旋转' : '开启自动旋转'"
          @click="toggleAutoRotate"
        >
          <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
            <path d="M21 12a9 9 0 1 1-3-6.7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M21 4v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
        <button
          class="rg-tool-btn"
          type="button"
          :title="isFullscreen ? '退出全屏' : '全屏'"
          @click="toggleFullscreen"
        >
          <svg v-if="!isFullscreen" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
            <path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <svg v-else viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
            <path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
      </div>

      <div
        v-if="selectedNode"
        class="rg-detail-card"
        :style="detailCardStyle"
      >
        <div class="rg-detail-head">
          <img
            :key="selectedNode.id"
            :src="selectedAvatarSrc"
            alt=""
            class="rg-detail-avatar"
            referrerpolicy="no-referrer"
            crossorigin="anonymous"
            @error="onDetailAvatarError"
          />
          <div class="rg-detail-meta">
            <div class="rg-detail-title">{{ selectedNode.title }}</div>
            <a
              v-if="selectedNode.url"
              :href="selectedNode.url"
              target="_blank"
              rel="noopener noreferrer"
              class="rg-detail-link"
            >
              {{ selectedNode.url }}
            </a>
          </div>
        </div>
        <div v-if="selectedNode.description" class="rg-detail-desc">
          {{ selectedNode.description }}
        </div>
        <div class="rg-detail-row">
          <span class="rg-detail-row-label">所属星系</span>
          <span
            v-if="selectedGalaxies.length === 0"
            class="rg-detail-row-empty"
          >暂未连入任何星系</span>
          <span v-else class="rg-detail-row-galaxies" :title="selectedGalaxies.join('/')">
            {{ selectedGalaxiesText }}
          </span>
        </div>
      </div>

      <div v-if="showProgress && !error" class="rg-loading">
        <div class="rg-spinner" aria-hidden="true"></div>
        <div class="rg-loading-text">loading</div>
        <div class="rg-loading-progress">
          已汇聚 {{ progress.expanded }}/{{ progress.total }}
          <span v-if="progress.pending > 0">· 待展开 {{ progress.pending }}</span>
          <span v-if="progress.capped" class="rg-progress-cap">· 已达节点上限</span>
        </div>
      </div>
      <div v-else-if="error" class="rg-error">
        <p>{{ error }}</p>
      </div>
      <div v-else-if="!loading && nodes.size === 0" class="rg-empty rg-empty-overlay">
        <p>主星上还没有为你建立任何友链关系。</p>
        <p>请先把站点的友链同步到主星，然后回来查看。</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.rg-panel {
  display: flex;
  flex: 1;
  min-height: 0;
  padding: 12px;
}

.rg-canvas-wrap {
  position: relative;
  flex: 1;
  min-height: 0;
  background: radial-gradient(circle at 50% 50%, #0b1220 0%, #050913 70%, #02040a 100%);
  border: 1px solid rgba(15, 23, 42, 0.18);
  border-radius: 18px;
  overflow: hidden;
}
.rg-canvas-wrap.rg-fullscreen {
  border-radius: 0;
  border: none;
}
.rg-canvas-wrap:fullscreen {
  border-radius: 0;
  border: none;
}
.rg-canvas { position: absolute; inset: 0; }
:deep(.rg-canvas canvas) { outline: none; background: transparent; }

.rg-toolbar {
  position: absolute;
  top: 12px;
  right: 12px;
  z-index: 6;
  display: inline-flex;
  gap: 8px;
}
.rg-tool-btn {
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  border: 1px solid rgba(148, 163, 184, .25);
  background: rgba(2, 6, 23, .55);
  color: #cbd5e1;
  cursor: pointer;
  transition: background .15s ease, color .15s ease, border-color .15s ease;
}
.rg-tool-btn:hover {
  background: rgba(2, 6, 23, .85);
  color: #fff;
}
.rg-tool-btn.active {
  background: rgba(99, 102, 241, .35);
  border-color: rgba(165, 180, 252, .55);
  color: #fff;
}

.rg-search {
  position: relative;
  display: inline-flex;
  align-items: center;
}
.rg-search-input {
  width: 220px;
  height: 32px;
  padding: 0 28px 0 12px;
  border-radius: 8px;
  border: 1px solid rgba(148, 163, 184, .25);
  background: rgba(2, 6, 23, .55);
  color: #e2e8f0;
  font-size: 12px;
  outline: none;
  transition: background .15s ease, border-color .15s ease;
}
.rg-search-input::placeholder {
  color: rgba(148, 163, 184, .8);
}
.rg-search-input:focus {
  background: rgba(2, 6, 23, .85);
  border-color: rgba(165, 180, 252, .55);
}
.rg-search-clear {
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  width: 18px;
  height: 18px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: none;
  background: rgba(148, 163, 184, .18);
  color: #cbd5e1;
  cursor: pointer;
}
.rg-search-clear:hover {
  background: rgba(148, 163, 184, .35);
  color: #fff;
}
.rg-search-suggestions {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  width: 280px;
  max-height: 320px;
  overflow-y: auto;
  background: rgba(2, 6, 23, .95);
  border: 1px solid rgba(148, 163, 184, .25);
  border-radius: 10px;
  padding: 4px;
  box-shadow: 0 12px 32px rgba(0, 0, 0, .55);
  z-index: 8;
}
.rg-search-empty {
  padding: 12px;
  color: rgba(148, 163, 184, .8);
  font-size: 12px;
  text-align: center;
}
.rg-search-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 8px;
  border: none;
  background: transparent;
  color: #e2e8f0;
  cursor: pointer;
  text-align: left;
}
.rg-search-item:hover,
.rg-search-item.active {
  background: rgba(99, 102, 241, .22);
}
.rg-search-item-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
  background: rgba(148, 163, 184, .2);
}
.rg-search-item-meta {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.rg-search-item-title {
  font-size: 12px;
  font-weight: 600;
  color: #f1f5f9;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rg-search-item-sub {
  font-size: 10px;
  color: rgba(148, 163, 184, .85);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rg-search-item-tag {
  flex-shrink: 0;
  padding: 2px 6px;
  border-radius: 999px;
  font-size: 10px;
  letter-spacing: .02em;
}
.rg-search-item-tag.registered {
  background: rgba(96, 165, 250, .2);
  color: #93c5fd;
}
.rg-search-item-tag.unregistered {
  background: rgba(148, 163, 184, .18);
  color: #cbd5e1;
}

.rg-detail-card {
  position: absolute;
  z-index: 7;
  width: min(280px, calc(100% - 32px));
  padding: 14px 16px 12px;
  border-radius: 20px;
  background:
    radial-gradient(120% 120% at 100% 0%, rgba(191, 219, 254, .42), transparent 58%),
    radial-gradient(120% 120% at 0% 100%, rgba(224, 242, 254, .48), transparent 58%),
    rgba(255, 255, 255, .96);
  border: 1px solid rgba(148, 163, 184, .35);
  box-shadow: 0 18px 38px rgba(15, 23, 42, .35), inset 0 1px 0 rgba(255, 255, 255, .78);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  font-family: system-ui, -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif;
  color: #0f172a;
  pointer-events: auto;
}
.rg-detail-head {
  display: flex;
  align-items: center;
  gap: 12px;
}
.rg-detail-avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  object-fit: cover;
  border: 1px solid rgba(59, 130, 246, .32);
  background: linear-gradient(145deg, rgba(191, 219, 254, .65), rgba(224, 242, 254, .58));
  flex-shrink: 0;
}
.rg-detail-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}
.rg-detail-title {
  font-size: 14px;
  font-weight: 800;
  letter-spacing: 0;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 200px;
}
.rg-detail-link {
  display: block;
  color: #475569;
  text-decoration: none;
  font-size: 11px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
  max-width: 200px;
}
.rg-detail-link:hover {
  color: #1d4ed8;
  text-decoration: underline;
}
.rg-detail-desc {
  margin-top: 10px;
  padding: 8px 10px;
  border-radius: 12px;
  background: rgba(241, 245, 249, .7);
  color: #334155;
  font-size: 11px;
  line-height: 1.55;
  word-break: break-word;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  overflow: hidden;
}
.rg-detail-row {
  margin-top: 10px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  min-width: 0;
}
.rg-detail-row-label {
  color: #64748b;
  font-weight: 600;
  flex-shrink: 0;
}
.rg-detail-row-empty {
  color: #94a3b8;
  font-style: italic;
}
.rg-detail-row-galaxies {
  color: #4338ca;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
  flex: 1;
}

.rg-loading-progress {
  font-size: 11px;
  color: #cbd5e1;
  letter-spacing: .04em;
  text-shadow: 0 0 10px rgba(0, 0, 0, .8);
}
.rg-progress-cap {
  margin-left: 4px;
  color: #fca5a5;
}

.rg-loading,
.rg-error,
.rg-empty-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: #02040a;
  color: #cbd5e1;
  font-size: 13px;
  gap: 10px;
  text-align: center;
  padding: 16px;
}
.rg-empty-static-wrap {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 360px;
}
.rg-empty-static-wrap .sp-empty-state {
  flex: 1;
  justify-content: center;
}
.rg-empty-overlay p { margin: 0; }
.rg-error p { margin: 0 0 8px; color: #fca5a5; }

.rg-spinner {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid rgba(154, 121, 255, .25);
  border-top-color: #c8b6ff;
  animation: rg-spin 1s linear infinite;
}
.rg-loading-text {
  color: #c8b6ff;
  font-size: 13px;
  letter-spacing: 1px;
}
@keyframes rg-spin {
  to { transform: rotate(360deg); }
}

/* 内联空态（对齐 Halo common/EmptyState.vue） */
.sp-empty-state{padding:64px 16px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:12px}
.sp-empty-state-icon{opacity:.4}
.sp-empty-state-text{font-size:14px;font-weight:600;color:#64748b}
.sp-empty-state-hint{font-size:12px;color:#94a3b8}
</style>

<style>
.float-tooltip-kap {
  background: transparent !important;
  padding: 0 !important;
  border: none !important;
  box-shadow: none !important;
  border-radius: 0 !important;
  color: inherit !important;
  font: inherit !important;
}
</style>
