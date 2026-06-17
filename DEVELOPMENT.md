# WP-AstraHub 插件开发文档

WordPress 版「博客星球 / AstraHub」接入插件。把 WordPress 站点接入主星（Hub，`https://astra.aobp.cn`），实现站点注册/登舱、图谱数据上报、友链星球浏览、友链邀请与本地建链、RSS 深空资讯、关系图谱可视化。

本文档按**实现阶段**逐段记录精确细节（文件、符号、常量、option key、路由、上游契约），用于后续排查问题。所有路径相对于插件根 `plugin-wp-astrahub/`。

---

## 0. 总体架构

```
浏览器（WP 后台 SPA, Vue3）
   │  fetch  + X-WP-Nonce
   ▼
WordPress REST（命名空间 wp-astrahub/v1，权限 manage_options）
   │  HMAC 签名（X-BP-* 头）
   ▼
Hub / 主星（Go 后端，Blog Planet，https://astra.aobp.cn）
```

- **前端**：`console/`，Vite + Vue3 单页，产物固定名打到 `assets/dist/`，由 PHP enqueue。
- **后端**：`includes/` 一组 PHP 类，单例 `WP_AstraHub_Plugin` 装配。
- **鉴权两段**：浏览器→插件用 WordPress REST nonce（`X-WP-Nonce`）+ `manage_options`；插件→Hub 用站点 HMAC 签名（`X-BP-*`）。
- **Hub 基址固定写死**：`WP_ASTRAHUB_HUB_BASE_URL = 'https://astra.aobp.cn'`（`wp-astrahub.php:40`），不暴露给用户配置。

### 关键常量与存储 key 速查

| 项 | 值 | 位置 |
|---|---|---|
| REST 命名空间 | `wp-astrahub/v1` | `WP_AstraHub_Rest_Register::NAMESPACE` |
| Hub 基址 | `https://astra.aobp.cn` | `WP_ASTRAHUB_HUB_BASE_URL`（wp-astrahub.php） |
| 插件版本 | `WP_ASTRAHUB_VERSION`（0.1.0） | wp-astrahub.php |
| 凭据 option | `wp_astrahub_credentials` | `Credential_Store::OPTION_CREDENTIALS` |
| 连接 option | `wp_astrahub_connection` | `Credential_Store::OPTION_CONNECTION` |
| 同步状态 option | `wp_astrahub_report_status` | `Push_Service::OPTION_REPORT` |
| 图谱协议版本 | `bp.graph.v1` | `Graph_Collector::GRAPH_VERSION` |
| 图谱推送路径 | `/v1/graph/push` | `Push_Service::GRAPH_PUSH_PATH` |
| cron hook | `wp_astrahub_push_graph_cron` | `Cron::HOOK`（hourly，首次 +300s） |
| 本地建链标记前缀 | `astrahub:peer-site-id=` | `Link_Reconcile::NOTE_PEER_PREFIX` |
| 前端收藏 localStorage | `wp_astrahub_favorites` | PlanetLinksPanel |
| 前端稍后阅读 localStorage | `wp_astrahub_read_later` | NewsHubPanel |
| 前端产物 | `assets/dist/wp-astrahub-admin.{js,css}` | vite.config.ts |
| 前端挂载点 | `#wp-astrahub-app` | index.html / main.ts |
| bootstrap 全局 | `window.WP_ASTRAHUB_BOOTSTRAP` | PHP 注入 |

---

## 阶段 0：脚手架、构建与装配

### 0.1 前端构建（console/）

- `vite.config.ts`：普通 build（非库模式）。
  - `build.outDir = ../assets/dist`，`emptyOutDir: true`，`cssCodeSplit: false`。
  - 单入口 `src/main.ts`；`entryFileNames = wp-astrahub-admin.js`；CSS 资源统一命名 `wp-astrahub-admin.css`。
  - dev server 端口 `5273`，`open: /index.html`。
- `src/main.ts`：`createApp(AstraHubApp)`，import 全局 `./styles/console.css`，仅当 `#wp-astrahub-app` 存在才 mount。
- `index.html`：dev 预览用，inline 注入 mock `window.WP_ASTRAHUB_BOOTSTRAP`（`__dev:true`）。
- `tsconfig.json`：`moduleResolution: Bundler`，`strict: true`，`noUnusedLocals: false`。
- 构建命令：`npm run build`（在 `console/` 下）。类型检查：`npx vue-tsc --noEmit -p tsconfig.json`。

### 0.2 PHP 装配（includes/）

- 入口 `wp-astrahub.php`：定义常量 → require `class-wp-astrahub-autoloader.php` → `WP_AstraHub_Autoloader::register()` → `wp_astrahub()` 调 `WP_AstraHub_Plugin::instance()`。
- 自动加载规则：`WP_AstraHub_Hub_Client` → `includes/class-wp-astrahub-hub-client.php`（类名小写、`_`换`-`、加前缀 `class-`）。
- 单例 `WP_AstraHub_Plugin::__construct()`（`class-wp-astrahub-plugin.php:118`）按序装配：

```
credentials       = new Credential_Store()
hub_client        = new Hub_Client(credentials)
register_service  = new Register_Service(hub_client, credentials)
rest_register     = new Rest_Register(register_service, credentials)
rest_proxy        = new Rest_Proxy(hub_client, credentials)
collector         = new Graph_Collector(credentials)
push_service      = new Push_Service(hub_client, credentials, collector)
rest_proxy->set_push_service(push_service)   ← 二段注入，打破循环依赖
cron              = new Cron(push_service)
reconcile         = new Link_Reconcile()
rest_friend       = new Rest_Friend(hub_client, credentials, reconcile)
```

- 钩子（`class-wp-astrahub-plugin.php:138-147`）：
  - `admin_init` → `maybe_run_selftest`
  - `rest_api_init` ×3 → 三个 REST 类的 `register_routes`
  - `admin_menu` → `register_admin_menu`（slug `wp-astrahub`，cap `manage_options`）
  - `init` → `cron->register`
  - `register_deactivation_hook` → `Cron::clear`（静态）
- 后台页：`enqueue_admin_assets()` 仅在 `toplevel_page_wp-astrahub` 加载产物；`render_admin_page()` 输出 `#wp-astrahub-app`；`bootstrap_admin_data()` 注入 `window.WP_ASTRAHUB_BOOTSTRAP`（restBase / restNonce(`wp_rest`) / hubBaseUrl / registered）。

### 0.3 排查要点
- 后台页空白：确认 `assets/dist/wp-astrahub-admin.js` 已构建、enqueue 命中页面 hook、`#wp-astrahub-app` 已输出。
- 前端 404 到 `/wp-json/wp-astrahub/v1/*`：确认三个 `register_routes` 都在 `rest_api_init` 注册、固定链接已刷新。

---

## 阶段 1：接入配置（注册 / 登舱 / 凭据 / 签名）

对应前端 `maintenance` 导航 → `ConnectionPanel.vue`；后端 `Rest_Register` + `Register_Service` + `Credential_Store` + `Hub_Client` + `Hub_Signer`。

### 1.1 签名协议（与 Hub `internal/security/signature.go` 逐字节对齐）

`WP_AstraHub_Hub_Signer::sign_request()`（`class-wp-astrahub-hub-signer.php`）：

```
canonical = UPPER(METHOD) + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + SHA256_hex(BODY)
signature = lowerhex( HMAC-SHA256(apiKey, canonical) )
```

- **PATH 必须是解码后的路径、不含 query**（与 Go 的 `r.URL.Path` 对齐，handlers.go:2347）。query 仅在 `Hub_Client::dispatch()` 里用 `add_query_arg(array_map('rawurlencode', $query))` 拼到实际 URL，**不参与签名**。
- 空 body 的 SHA256 固定为 `e3b0c442...b855`。
- `timestamp = (string) time()`（Unix 秒）；`nonce = wp_generate_uuid4()` 去横线。
- 请求头：`X-BP-Site-Id` / `X-BP-Timestamp` / `X-BP-Nonce` / `X-BP-Signature`。
- **Hub 侧校验**：时钟偏差默认 5 分钟（超出 `AUTH_EXPIRED`），nonce TTL 默认 900s 防重放（重复 `AUTH_REPLAY`）—— `sitecredentialruntime/manager.go:66-216`。

> ⚠️ 排查签名 401/403：先核对 PATH 是否解码值、是否误把 query 计入签名；再核对服务器时钟（5 分钟内）。`?wp_astrahub_selftest=1`（需 manage_options）用固定向量自检签名实现。

### 1.2 凭据/连接存储 `Credential_Store`

- `wp_astrahub_credentials`（autoload=false）：`siteId, apiKey, createdAt, nodeName, category, nodeAvatar`。apiKey **敏感，绝不下发前端**。
- `wp_astrahub_connection`（autoload=false）：`siteName, siteUrl, siteDescription, siteRssUrl, siteAvatarUrl, contactEmail, siteNodeName, siteNodeAvatar`，缺省用 WP 站点信息填充（bloginfo / home_url / get_feed_link / admin_email / custom_logo→site_icon）。
- `is_registered()` = siteId 与 apiKey 均非空。

### 1.3 REST 路由（`Rest_Register`，全部 `manage_options`）

| 方法 | 路径 | handler | 说明 |
|---|---|---|---|
| POST | `/register` | handle_register | 已接入时更新信息（Hub `/v1/sites/register`，用 request_public，可带令牌头） |
| POST | `/invitation/request` | handle_invitation_request | 申请签发码 → Hub `/v1/sites/invitations/apply` |
| POST | `/invitation/register` | handle_invitation_register | 带签发码注册 → `/v1/sites/register`（头 `X-BP-Invitation-Code`） |
| POST | `/boarding/send-code` | handle_boarding_send_code | → `/v1/sites/boarding/send-code` |
| POST | `/boarding/restore` | handle_boarding_restore | 邮箱 OTP 恢复凭据 → `/v1/sites/boarding/restore` |
| GET | `/status` | handle_status | **平铺返回** registered/credentials/connection/hubBaseUrl，apiKey 只回 mask |
| POST | `/connection` | handle_save_connection | 仅本地保存，不触发 Hub |
| POST | `/logout` | handle_logout | 清空本地凭据 |

> 注册/登舱类对 Hub 的请求**无需站点签名**（此时还没有 apiKey），用 `Hub_Client::request_public()`。

### 1.4 前端 `ConnectionPanel.vue`
- 本地 `form`（reactive）与 `connection` 同构；`hasCredentials` 决定按钮是「接入星链」还是「更新信息」。
- 接入流程：勾选同意书 + 邮箱 → `onRequestInvitationCode`（POST `/invitation/request`）→ 邮箱收码 → `onConfirmJoinPlanet`（POST `/invitation/register`）。
- 重新登舱：6 位 OTP（`OTP_LENGTH=6`），`onSendBoardingCode` → `onRestoreByBoardingCode`。
- `/status` 是平铺结构（非 `data` 包裹），`useStatus.fetchStatus()` 做了兼容读取。

### 1.5 排查要点
- 注册成功但前端仍显示未接入：检查 `/status` 是否平铺返回、`useStatus` 是否正确读取 `raw.registered`。
- apiKey 复制：前端 `onCopyApiKey` 仅提示「密钥仅存服务端」，这是有意为之。

---

## 阶段 2：图谱采集与推送（含 cron）

对应 `Graph_Collector` + `Push_Service` + `Cron`；前端 `/push-graph`、`/report-status`（经 `Rest_Proxy`）。

### 2.1 采集 `Graph_Collector::build_payload($reason)`
- 协议版本 `GRAPH_VERSION = bp.graph.v1`，与 Hub `graphschema/schema.go`（version=bp.graph.v1）对齐。
- payload 顶层：`{version, source{...}, snapshotAt, consent{granted:true,version:v1,grantedAt}, groups[], contents[]}`。
- `source`：`platform=wordpress, plugin=astrahub, pluginVersion, siteId, siteName, siteUrl, nodeName, category, nodeAvatar, siteRssUrl, syncReason, owner, language`。
- `contents[]` 四类，各有 `externalId` 前缀：
  - 友链分组：`friend-group:<term_id>`（`get_terms('link_category')`）
  - 友链条目：`friend-link:<link_id>`（`get_bookmarks()` / Links Manager）
  - 文章：`post:<ID>`（`WP_Query`，post/publish，按 modified DESC，limit 200；正则提取 outboundLinks 上限 50、mentionedSites 去重 confidence 0.7）
  - 自身节点：`self-link:<siteId或站点根>`
- URL 规范化 `normalize_url`：去尾斜杠、去 utm_/追踪参数；邮箱脱敏 `sanitize_email`。

### 2.2 推送 `Push_Service`
- `push_graph($reason='manual')`：未登舱返回 400；否则 `request_signed('POST', '/v1/graph/push', payload)`。
- 结果写 option `wp_astrahub_report_status`：`{success, status, message, trigger, pushedAt, updatedAt}`。
- `get_report_status()` 读取，前端经 `/report-status` 展示「最近同步」。

### 2.3 定时任务 `Cron`
- hook `wp_astrahub_push_graph_cron`，`register()` 在 `init` 触发：未排程则 `wp_schedule_event(time()+300, 'hourly', HOOK)`。
- `run()` → `push_service->push_graph('cron')`。停用钩子调静态 `clear()` 注销排程。

### 2.4 排查要点
- 推送一直失败：看 `wp_astrahub_report_status` 的 status/message；401/403 多为签名或时钟问题（见 1.1）。
- cron 不跑：WP 的 cron 依赖站点流量触发，确认未禁用 `DISABLE_WP_CRON` 或配置了系统级 cron。

---

## 阶段 3：友链星球（PlanetLinks）

对应前端 `planetLinks` → `PlanetLinksPanel.vue` + `usePlanetLinks.ts`；后端 `Rest_Proxy::handle_planet_links`。

### 3.1 数据通路
- 前端 GET `/planet-links`（注：proxy 内部转发 Hub `/v1/planet/links`，签名 GET）。query：`size(=50) / cursor / keyword / relation`。
- `usePlanetLinks`：游标分页 + 按 `url` 去重 append（`PAGE_SIZE=50`）；`setQuery({keyword,relation})`。

### 3.2 关系枚举（来自 Hub `planetlinks/common.go`）
- `relationStatus`：`self / mutual / following / follower / invite_sent / invitable / none`
- `relationKind`：`mutual / one_way_out / one_way_in / none`
- `targetRegistered` 与上面正交；`targetSupportsInvitation`、`targetInvitationState`、`outboxInvitationActive` 决定邀请按钮状态。
- 前端 `relationSummary / canInvite / inviteButtonText / inviteButtonTone` 把这些字段映射成中文文案与色调。

### 3.3 前端实现要点
- 虚拟滚动：`ROW_HEIGHT=76 / ROW_GAP=8 / OVERSCAN=6`；`visibleRange` 扣除 hero 高度；触底 `distanceToBottom < 80` 调 `loadMore`。
- 收藏：localStorage `wp_astrahub_favorites`（仅前端，收藏项排前）。
- 搜索防抖 300ms；切 filter 立即 reload 并平滑滚回 hero 底。
- 邀请/解除弹窗调用阶段 4 的 friend API。

### 3.4 排查要点
- 列表空：确认已接入（未接入 Hub 不返回数据）；检查 proxy 是否把 Hub body 放进 `data`（`passthrough`）。
- 邀请按钮置灰：核对 `targetRegistered / targetSupportsInvitation / targetInvitationState`。

---

## 阶段 4：友链管理（邀请 / 审核 / 本地建链）

对应前端 `friendManagement` → `FriendInvitationPanel.vue` + `api/friend.ts`；后端 `Rest_Friend` + `Link_Reconcile`。

### 4.1 REST 路由（`Rest_Friend`，全部 `manage_options`）

| 方法 | 路径 | handler | 转发 Hub |
|---|---|---|---|
| GET | `/friend-invitations?box=&status=` | handle_list | `/v1/friend-invitations/inbox` 或 `/outbox` |
| POST | `/friend-invitations` | handle_create | `/v1/friend-invitations` |
| POST | `/friend-invitations/{id}/review` | handle_review | `.../review`，**通过后本地建链** |
| POST | `/friend-invitations/{id}/cancel` | handle_cancel | `.../cancel` |
| POST | `/friend-invitations/{id}/delete` | handle_delete | `.../delete` |
| POST | `/friend-invitations/{id}/reconcile` | handle_reconcile | 纯本地建链（不调 Hub） |
| POST | `/friend-invitations/{id}/ack` | handle_ack | `.../ack`，回执 `{lastError}`（写 ackedAt） |
| POST | `/friend-relations/{peerSiteId}/remove` | handle_remove_relation | Hub 删边 + 本地删链 |
| GET | `/friend-invitations/link-groups` | handle_link_groups | 本地 `link_category` terms |

### 4.2 状态枚举（Hub `friendinvitation/types.go`、service.go:1041）
- `status`：`pending / accepted / rejected / cancelled / expired`
- `deliveryStatus`：`delivered / acknowledged`
- 错误码见 `friendinvitation/protocol.go`。

### 4.3 本地建链 `Link_Reconcile`（WP Links Manager / wp_links）
- `NOTE_PEER_PREFIX = astrahub:peer-site-id=` 写入 `link_notes` 标记对端 siteId。
- `ensure_links_manager()`：未开启则 `update_option('link_manager_enabled',1)` 并 require `bookmark.php`。
- `reconcile_peer($peer,$group)`：按 URL `strcasecmp` 去重，命中则 duplicate，否则 `wp_insert_link()`；分组按名查 `link_category` term。
- `delete_by_peer_url($url)`：按 URL 大小写不敏感匹配 `wp_delete_link()`，返回删除计数。
- `handle_review` 通过时解析对端（审核方=toSite，对端=fromSite）后建链；`handle_remove_relation` 用 Hub 返回的 `peerSiteUrl` 删本地链。

### 4.4 前端要点
- `FriendInvitationPanel`：`loadAll()` 用 `Promise.all` 同拉 inbox/outbox/linkGroups；本地按 tab 过滤（无虚拟滚动/分页）。
- 审核通过/拒绝带分组选择（`reviewGroup` by inviteId）；成功 emit `pending-inbox-remove` 更新红点。
- 红点：`AstraHubApp.refreshPendingCount()` 拉 inbox+pending 的 length。

### 4.5 排查要点
- 通过后本地无友链：确认 Links Manager 已启用、`reconcile_peer` 未判 duplicate、`wp_insert_link` 无 WP_Error。
- 删除关系后本地仍在：确认 Hub 返回了 `peerSiteUrl`，且与本地 `link_url` 大小写无关匹配。

---

## 阶段 5：通用签名代理（/hub/get、/hub/post）

`Rest_Proxy` 提供通用签名转发，供阶段 6（资讯/图谱）复用，避免为每个 Hub 只读端点写专用 PHP 路由。

### 5.1 端点
- POST `/hub/get`，body `{path, query}` → 签名 GET 转发。
- POST `/hub/post`，body `{path, payload}` → 签名 POST 转发。
- 二者都先 `is_registered()` 检查，再 `passthrough()` 原样透传 Hub `{success,status,message,data=body}`。

### 5.2 路径白名单 `is_allowed_path()`（`class-wp-astrahub-rest-proxy.php:62,264`）
```
/v1/planet/
/v1/graph/
/v1/friend-invitations
/v1/friend-relations/
/v1/relations/
/v1/sites/lookup
```
校验规则：① 必须以 `/` 开头；② 拒绝含 `?` 或 `#`（防注入）；③ 必须命中上述前缀之一。否则 403 `path not allowed`。

> 新增一个 Hub 只读端点接入前端时，**先确认其前缀在白名单内**，否则 403。

---

## 阶段 6：资讯（NewsHub）+ 关系图（RelationGraph）

本阶段无新增 PHP 路由，全部经阶段 5 的 `/hub/get` 代理。

### 6.1 资讯 NewsHub

**前端**：`news` → `NewsHubPanel.vue` + `api/news.ts`。

- API（均经 `/hub/get`）：
  - `fetchNewsBrowse({pageSize,cursor,onlyMyGalaxy})` → `/v1/planet/rss-deep-space/browse`（**cursor + size**）
  - `fetchNewsSearch({q,page,pageSize,onlyMyGalaxy})` → `/v1/planet/rss-deep-space/search`（**q + page + size**）
  - `fetchNewsDiscover({size,cursor})` → `/v1/planet/rss-deep-space/discover`（**cursor + size**）
- **Hub 契约（rss_deep_space.go:2799-3006）**：默认 page=1 / size=20 / max size=80；browse/discover 用 cursor，search 用 page。响应字段：`nextCursor / hasMore / refreshedAt / indexedItems / items`。
  - ⚠️ browse 与 search 的翻页机制不同：**browse 是 cursor，search 是 page**。前端 `NewsHubPanel` 用 `isBrowseMode()` 区分，`loadMoreItems` 分别走 cursor / page+1。
- 前端常量：`PAGE_SIZE=40`、源列表 `SOURCE_LIMIT=80 / SOURCE_ROW_HEIGHT=64 / SOURCE_OVERSCAN=6`。
- 右侧文章流用 `IntersectionObserver`（sentinel，rootMargin `0px 0px 200px 0px`）触发加载；`deduplicateAppend` 按 `item.id` 去重。
- 左侧源列表虚拟滚动 + 游标加载更多。
- **稍后阅读**：localStorage `wp_astrahub_read_later`（`ReadLaterItem[]`），替代 Halo 端的 settings.readLater。
- 三种模式：全站浏览 / 单源（selectedSourceId）/ 我的星系（`onlyMyGalaxy`）。搜索防抖 300ms。

### 6.2 关系图 RelationGraph

**前端**：`relationGraph` → `RelationGraphPanel.vue` + `composables/useRelationGraph.ts` + `api/graph.ts`。siteId 取自 `useStatus().credentials.siteId`。

- API（均经 `/hub/get`）：
  - `fetchMySite(siteId,size)` → `/v1/graph/sites/{siteId}`（取自身 nodeId 作 BFS 种子）
  - `fetchGraphNodes(page,size,sort=recommendation)` → `/v1/graph/nodes`（全量节点，铺孤岛）
  - `fetchGraphNode(nodeId,size)` → `/v1/graph/nodes/{nodeId}`（含一度 friendLinks，供展开）
- **数据流 `useRelationGraph`（多种子 BFS）**：常量 `FRIEND_LINK_PAGE_SIZE=100 / NODES_FETCH_PAGE_SIZE=100 / BFS_MAX_NODES=1000 / BFS_MAX_CONCURRENCY=4`。
  - `bootstrap(siteId)`：取自身 nodeId → `fetchAllNodes()` 把全部节点作种子 → `crawlAll(seedIds)` 并发展开。
  - `expandFriendLinks`：registered 用 `targetNodeId` 作 id，unregistered 用 `url:{normalizeUrl}`；边 key `friend|<min>|<max>`；`nodeCache` 防重复展开；达 `BFS_MAX_NODES` 置 `capped`。
- **渲染**：纯 Canvas 力导向图（WP 控制台**无 three.js / 3d-force-graph 依赖**，与 Halo 端 3D 实现不同）。力学常量：`REPULSION=1400 / SPRING=0.015 / SPRING_LEN=70 / CENTER_PULL=0.002 / DAMPING=0.86`，限速 30，斥力 O(n²)（配合上限 1000）。交互：拖拽、平移、滚轮以光标为中心缩放(0.15~4)、hover 高亮、点击选中详情卡、节点搜索、全局自适应 `fitView`。
- 节点色：self `#8081FF`、registered `#38bdf8`、unregistered `#cbd5e1`。
- `refreshSignal` 变化（顶部「刷新」按钮）→ `reset(siteId)` 重爬。

### 6.3 ⚠️ 关键陷阱：nodeId 的签名与转义

- Hub 的 nodeId **可能是非 ASCII（中文站点名）**：`NodeKeyForSite` 回退 `ClusterIDSeed`，用 `unicode.IsLetter` 保留中文（graphquery/common.go:310, apptext/common.go:140）。
- 签名 PATH 必须与 Hub 的**已解码** `r.URL.Path` 逐字节一致（见 1.1）。因此 `api/graph.ts` 的 `safeSegment()` **不做 `encodeURIComponent`**，而是按 Halo 端 `sanitizePathSegment` 口径校验（拒绝 `/ ? # \u0000`、长度 ≤256）后**原样**拼接路径段。
  - 若误用 `encodeURIComponent`，中文 nodeId 会让 PHP 端签名 percent-encoded 路径，与 Hub 解码后路径不一致 → HMAC 校验失败。

### 6.4 排查要点
- 资讯翻页重复/卡住：确认 browse 用 cursor、search 用 page（两者别混）；`hasMore` 同时看 `nextCursor` 与本批 items 是否为空。
- 关系图空白且报签名错误：优先怀疑中文 nodeId 的转义（见 6.3）。
- 关系图未接入提示：`credentialReady` 依赖 `useStatus` 的 siteId，确认 `/status` 正常返回。
- 节点头像不显示：Canvas 跨域贴图可能 taint，已 try/catch 跳过，回退纯色圆点（非错误）。

---

## 上游契约索引（Hub / Blog Planet）

| 主题 | 文件 | 行号 |
|---|---|---|
| 签名校验 | `internal/security/signature.go` | 11-26 |
| 时钟/nonce/防重放 | `internal/app/sitecredentialruntime/manager.go` | 46-217 |
| 鉴权头 / PATH 解码 | `internal/api/handlers.go` | 34-39, 2341-2350 |
| 路由总表 | `internal/api/router.go` | 44-90 |
| 注册类型 | `internal/app/siteregistration/types.go` | 11-30 |
| 登舱码 | `internal/app/boardingcode/common.go` | 23-39 |
| 友链邀请类型/状态 | `internal/app/friendinvitation/types.go`、`protocol.go` | 10-67 / 9-34 |
| 友链邀请 handler | `internal/api/friend_invitation_handlers_runtime.go` | 14-405 |
| planet/links | `internal/api/handlers.go`、`internal/app/planetlinks/common.go` | 851-924 / 15-87 |
| RSS 深空 | `internal/api/rss_deep_space.go` | 24-108, 2799-3006 |
| 图谱查询/options | `internal/api/handlers.go`、`internal/app/graphquery/public_types.go` | 2833-2857 / 10-205 |
| 图谱推送 schema | `internal/app/graphschema/schema.go` | 5-81 |
| nodeId 中文保留 | `internal/app/graphquery/common.go`、`apptext/common.go` | 310-327 / 109-167 |

---

## 阶段 7：友链实时（链路 A）+ 反向对账（链路 B）

友链管理面板要做到「对端发邀请/审核/撤回/删除 → 卡片秒级增删、红点实时变化、不刷新整页」，
以及「对端解除关系/改资料 → 本地 `wp_links` 友链最终一致」。分两条链路实现。

### 7.1 链路 A：实时 UI（浏览器直连 Hub WS）

**关键事实**：WebSocket 不受同源/CORS 限制，且 PHP-FPM 无法维持服务端长连接。因此 UI 事件流
由**浏览器直连** `wss://astra.aobp.cn/v1/ws`，**不经过 PHP**。PHP 只在握手前签发一次性票据。

- **PHP**：`POST /realtime/token`（`Rest_Proxy::handle_realtime_token`）→ 签名转发 Hub
  `POST /v1/ws-token` → 返回 `data.{token, expiresAt}`。token TTL 仅 2 分钟、一次性消费
  （Hub `ws_runtime.go`）。
- **前端 `api/realtime.ts`**：`issueRealtimeToken()` 拿票；`buildHubWsUrl()` 把 hubBaseUrl 转
  `wss://.../v1/ws?access_token=...&replayLimit=200`。
- **前端 `composables/useFriendInvitationRealtime.ts`**：浏览器持 WS，3s 断线重连，
  `isRelevantHubEvent` 按 siteId 过滤 7 类事件（`friend_invitation_created/reviewed/acked/
  cancelled/deleted`、`friend_relation_removed`、`site_relation_updated`）。
- **壳层 `AstraHubApp.vue`**：持有 WS（切面板不断连）。收事件 → 实时增减红点
  `pendingInboxCount` + 透传 `friendRealtimeEvent` 给面板。
- **面板 `FriendInvitationPanel.vue`**：`watch(realtimeEvent)` → `applyRealtimeEvent` 按
  `inviteId` 在 inbox/outbox 做 `upsert`/`removeById`（方向由 `directionOf` 用 siteId 判定）。

排查：WS 连不上先看 `/realtime/token` 是否 200 且 `data.token` 非空；再看浏览器 Network 的
ws 帧；token 2 分钟过期是握手前一次性用，连上后不受影响，断线由重连覆盖。

#### 7.1.1 邀请方侧自动建链 + 回执（对齐 Halo autoReconcileAcceptedOutboxInvitation）
- 邀请方（发件箱）WS 收到 `friend_invitation_reviewed` 且 `status=accepted` 时，
  `AstraHubApp.onRealtimeEvent` 立即调 `autoReconcileAcceptedOutboxInvitation`：
  `reconcileFriendInvitation()`（本地建链）→ `ackFriendInvitation(inviteId,'')`（POST
  `/friend-invitations/{id}/ack` 写 Hub `ackedAt`）。
- `reconciledOutboxIds` 去重集合防 WS 重放重复建链；失败时 `ackFriendInvitation(inviteId, message)`
  把原因写回 Hub（用户在「发出的」tab 可见 `lastError`），并从去重集合移除以便后续重试。
- 不依赖用户切到友链管理页；cron 全量对账（链路 B）作为兜底。

### 7.2 链路 B：本地友链最终一致（实时触发 + cron 兜底）

对端解除关系 / 改资料后，本地 `wp_links` 友链需要删除/更新。**两条腿**：

**(a) 在线实时（WS 事件触发，秒级）——100% 对齐 Halo HubRealtimeBridge 的服务端处理**
- 浏览器 WS 收到 `friend_relation_removed` / `site_profile_updated`。
- `AstraHubApp.onRealtimeEvent` → `api/friend.dispatchSelfCleanupEvent(type, data)` 把**事件原样**
  POST `/friend-sync/peer { type, data }`（不在前端解析对端，与 Halo 服务端解析对齐）。
- PHP 按事件类型分发，**直接处理本地友链，不回 Hub 二次确认**（与 Halo 一致）：
  - `friend_relation_removed` → `Friend_Sync_Service::handle_relation_removed()`：用本站凭据 siteId
    经 `resolve_self_cleanup_peer_url`（本站是 actor→删 peerSiteUrl，是 peer→删 actorSiteUrl，
    都不是→跳过，对齐 `resolveLocalLinkPeerUrlForSelfCleanup`）→ `delete_by_peer_url`（URL 大小写
    不敏感，对齐 `deleteLocalLinkByPeerUrl`）。
  - `site_profile_updated` → `handle_profile_updated()`：按 siteId 经 `update_by_peer_site_id`
    （匹配 `astrahub:peer-site-id=` 标记，对齐 `updateLocalLinkByPeerSiteId`）覆写名称/URL/简介/
    头像/RSS。

**(b) 离线兜底（cron 全量对账，WP 专属安全网，最长 1 小时）**
- `Friend_Sync_Service::reconcile($reason)`：列出本地托管友链 → 分批（`BATCH_LIMIT=80`）签名
  POST Hub `/v1/relations/sites/batch` → 按 relationKind 决策（见下）。Halo 无此 cron（它纯靠常驻
  WS），WP 因无常驻进程额外增加，仅纠正不误建。
- `Cron::run()` 在 push_graph 后调 `reconcile('cron')`。

**cron 决策（`apply_relation_result`）**：`none`/`one_way_in` 且对端已注册→删；`mutual`/`one_way_out`
→保留+刷新资料；`unknown`/未注册→保留绝不误删。

**`Link_Reconcile`** 扩展：`list_managed_links`（认 `astrahub:peer-site-id=` 标记）、
`delete_by_link_id`、`update_local_link`（仅变化字段写库，name/url 不允许被清空）、
`extract_peer_site_id`。

**REST（`Rest_Proxy`）**：`POST /friend-sync`（手动全量）、`GET /friend-sync/status`、
`POST /friend-sync/peer { peerUrl }`（实时定向）。结果写 option `wp_astrahub_friend_sync_status`。

Hub 关系契约（`siterelation/common.go`）：`relationKind ∈ self/mutual/one_way_out/one_way_in/
none/unknown`。`request_signed('POST','/v1/relations/sites/batch', {targetUrls})` 直接调，
**不走 `/hub/post` 白名单**。

排查：
- 误删友链：只有 `none`/`one_way_in` + 对端已注册才删；`unknown`（Hub 任一方快照缺失）必保留。
- 实时删除没生效：看浏览器是否收到 WS 事件、`/friend-sync/peer` 是否 200；失败由 cron 兜底。
- 友链没被清理（离线场景）：看 `wp_astrahub_friend_sync_status`；确认 cron 在跑；确认本地链接
  `link_notes` 带 `astrahub:peer-site-id=` 标记（手动建的链接不带标记，不参与对账）。

---

## 通用排查清单

1. **401/403 from Hub**：签名 PATH 是否解码值、是否含 query；服务器时钟是否在 5 分钟内；nonce 是否重放。
2. **403 path not allowed**：`/hub/get|post` 的 path 不在白名单前缀（阶段 5.2）。
3. **400 not registered**：未完成接入；`is_registered()`=siteId+apiKey 均非空。
4. **前端 401（WP 层）**：`X-WP-Nonce` 失效（页面停留过久），刷新后台页重取 nonce。
5. **中文 nodeId 签名失败**：检查是否误对 path 段做了 `encodeURIComponent`（阶段 6.3）。
6. **资讯翻页异常**：browse=cursor / search=page 不能混用。
7. **构建失败**：`console/` 下 `npx vue-tsc --noEmit` 看类型错误；产物落 `assets/dist/`。

---

## 阶段 8：卸载清理 + 安全加固

### 8.1 `uninstall.php`（插件删除时自动加载，无需注册 hook）
- 守卫 `if ( ! defined('WP_UNINSTALL_PLUGIN') ) exit;`。
- `delete_option`（多站点同时 `delete_site_option`）：
  `wp_astrahub_credentials`、`wp_astrahub_connection`、`wp_astrahub_report_status`、
  `wp_astrahub_friend_sync_status`（含 apiKey 等敏感数据一并清除）。
- 把本插件为使用 Links Manager 而自动开启的 `link_manager_enabled` 恢复为 0。
- `wp_clear_scheduled_hook('wp_astrahub_push_graph_cron')` 清理定时任务。
- **不删** `wp_insert_link` 建立的本地友链（属用户内容）。停用（deactivate）只清 cron，删除
  （uninstall）才清 option，两者分工见 `class-wp-astrahub-plugin.php` 的
  `register_deactivation_hook(..., Cron::clear)`。

### 8.2 输入净化（写库前防御）
- `Link_Reconcile::reconcile_peer`/`update_local_link` 写 `wp_insert_link`/`wp_update_link` 前：
  URL 类字段（url/image/rss）走 `esc_url_raw`，文本类（name/description/peerSiteId 标记）走
  `sanitize_text_field`。数据来源是 Hub 响应或前端透传 payload，统一净化作为纵深防御。

### 8.3 代理白名单边界（`Rest_Proxy::is_allowed_path`）
- 以 `/` 结尾的前缀（如 `/v1/planet/`）含天然边界，直接放行。
- 非 `/` 结尾的前缀（如 `/v1/friend-invitations`、`/v1/sites/lookup`）视为端点：要求路径
  **完全相等或下一字符是 `/`**，杜绝 `/v1/friend-invitations-evil` 这类越界匹配。

---

## 阶段 9：数据模型 / 时效性专项修复（务必牢记的约定）

### 9.1 ⚠️ 响应信封统一约定（最易踩坑）
`console/src/api/client.ts` 的 `request()` **只暴露 `{success,status,message,data}` 四个键**，
所有业务字段必须放在 `data` 下，前端统一读 `resp.data`。

- **历史 bug**：friend 系列 PHP 处理器曾把 `items/generatedAt/total` 等返回在**顶层**，
  而 `friend.ts` 又按顶层读 → 经 client 后全被丢弃 → 收发件箱列表恒空、分组下拉恒空、
  reconcile/remove 返回值丢失。**已修**：`handle_list`/`handle_reconcile`/
  `handle_remove_relation`/`handle_link_groups` 全部改为 `'data' => array(...)`，
  `friend.ts` 对应读 `resp.data`。
- 新增 friend 路由时务必：PHP 把业务字段包进 `data`，前端读 `(resp.data || {})`。
- 例外：`/status` 由 `useStatus` 特殊处理（兼容平铺）；review/ack 前端只判 `success` 不读字段。

### 9.2 ⚠️ 代理路由路径必须与 PHP 注册完全一致
- **历史 bug**：`usePlanetLinks.ts` 请求 `/planet-links`，PHP 注册的是 `/planet/links` →
  404 → 友链星球面板恒报「读取星球友链失败」。**已修**为 `/planet/links`。
- 改/加代理路由时，前端 `api.get('/x')` 的 `/x` 必须与 `register_rest_route(NS,'/x',...)` 字面一致。

### 9.3 友链星球面板实时刷新（A.2 修复）
- `AstraHubApp.vue` 现在把 `friendRealtimeEvent` 同时传给 `PlanetLinksPanel`（含
  `friend_relation_removed`/`site_profile_updated` 两类，注意 self-cleanup 分支先 set 再 return）。
- `PlanetLinksPanel.vue` 新增 `realtimeEvent` prop + `watch` → 500ms 防抖 `reload({silent:true})`，
  对齐 Halo `scheduleSilentReload`。停留在星球面板时，对端解除关系/改资料、本端审核邀请都会
  静默刷新 relationStatus 与按钮态，无需切面板。

### 9.4 红点权威纠偏（A.4 修复）
- `pendingInboxCount` 乐观增减仅为即时反馈，存在双扣（本地 emit + WS 事件）与断线重放漂移。
- `AstraHubApp.schedulePendingReconcile()`：任何收件箱相关事件 / 本地 `onPendingRemove` 后
  800ms 防抖 `refreshPendingCount()` 向服务端重取真实待审数，消除累计误差。

### 9.5 已知低优先项（不影响业务，记录备查）
- localStorage 收藏 `wp_astrahub_favorites` / 稍后读 `wp_astrahub_read_later` 为本地快照，
  对端 URL/资料变更后不会回填、不跨设备、永不失效（移植时有意改为本地存储）。
- `news.ts` `NewsItem.nodeName` 是 Hub 不输出的字段（恒 undefined，模板未渲染）；
  discover 的 `feedUrl` 已改名为 `rssUrl` 与 Hub 对齐。
- 死代码（无引用，未清理）：`client.ts` `isDev`/`hubBaseUrl`、`usePlanetLinks` `visibleItems`、
  `useRelationGraph` 返回的 `focusedNode`/`mySite`。
- `GET /friend-sync/status`（PHP 已实现）前端暂未消费，cron 历史对账结果未在 UI 展示。
