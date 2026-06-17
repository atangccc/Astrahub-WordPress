(function () {
  var ROOT_ID = 'astrahub-galaxy-widget-root';
  var CRITICAL_STYLE_ID = 'astrahub-galaxy-widget-critical-style';
  var RETRY_MAX = 30;
  var retryCount = 0;
  var shipIconSeed = 0;
  // WP 端：资源基址由 PHP 通过 window.WP_ASTRAHUB_WIDGET.assetsBase 注入（插件 assets URL）。
  // 回退到 Halo 原路径仅为兼容，正常 WP 环境一定走注入值。
  var AH_ASSETS_BASE = (window.WP_ASTRAHUB_WIDGET && window.WP_ASTRAHUB_WIDGET.assetsBase) || '/plugins/PluginAstraHub/assets/static';
  var LIVE2D_SCRIPT_SRC_LOCAL = AH_ASSETS_BASE + '/live2d-widget/L2Dwidget.min.js';
  var LIVE2D_MODEL_JSON = AH_ASSETS_BASE + '/live2d/33/model.2016.xmas.1.json';
  var LIVE2D_WIDGET_ID = 'astrahub-live2d-widget';
  var LIVE2D_CANVAS_ID = 'astrahub-live2dcanvas';
  var LIVE2D_SCRIPT_ID = 'astrahub-live2d-script';
  var LIVE2D_WIDTH = 200;
  var LIVE2D_HEIGHT = 240;
  var LIVE2D_H_OFFSET = 50;
  var LIVE2D_V_OFFSET = -10;
  var PANEL_RIGHT_SHIFT_PX = 28;
  var DOUBLE_CLICK_THRESHOLD_MS = 320;
  var MASCOT_CLICK_DELAY_MS = 230;
  var MASCOT_BUBBLE_HIDE_MS = 6500;
  var MASCOT_BUBBLE_HIDE_MS_MIN = 4200;
  var MASCOT_BUBBLE_HIDE_MS_MAX = 11000;
  var STATUS_BUBBLE_EXIT_CLEANUP_MS = 340;
  var MASCOT_BUBBLE_QUEUE_MAX = 12;
  var MASCOT_BUBBLE_ID_TTL_MS = 10 * 60 * 1000;
  // WP 端：默认无 public-status 桥接 WS（PHP 不能常驻 WebSocket），置空即优雅跳过实时播报连接；
  // 若将来 WP 侧提供同源 WS 端点，可由 PHP 注入 window.WP_ASTRAHUB_WIDGET.publicStatusWsPath 启用。
  var PUBLIC_STATUS_WS_PATH = (window.WP_ASTRAHUB_WIDGET && typeof window.WP_ASTRAHUB_WIDGET.publicStatusWsPath === 'string')
    ? window.WP_ASTRAHUB_WIDGET.publicStatusWsPath
    : '';
  var PUBLIC_STATUS_RECONNECT_MIN_MS = 2000;
  var PUBLIC_STATUS_RECONNECT_MAX_MS = 30000;
  var LOG_TAG = '[AstraHubWidget]';
  var DEBUG_LOG = false;

  function log(level, message, extra) {
    if (!DEBUG_LOG || !window.console) {
      return;
    }
    var logger = console[level] || console.log;
    if (typeof logger !== 'function') {
      logger = console.log;
    }
    if (typeof extra === 'undefined') {
      logger.call(console, LOG_TAG + ' ' + message);
      return;
    }
    logger.call(console, LOG_TAG + ' ' + message, extra);
  }

  function boot() {
    log('info', 'boot start');
    if (document.getElementById(ROOT_ID)) {
      log('debug', 'root exists, skip');
      return;
    }

    var dataNode = document.getElementById('astrahub-galaxy-widget-data');
    if (!dataNode) {
      log('warn', 'widget data node missing');
      return;
    }

    var widgetData = null;
    try {
      widgetData = JSON.parse(dataNode.textContent || '{}');
    } catch (_error) {
      log('error', 'widget data JSON parse failed');
      return;
    }

    if (!widgetData || widgetData.linked !== true) {
      log('info', 'site not linked, skip widget');
      return;
    }

    if (!document.body || !document.documentElement) {
      log('warn', 'document body not ready, retry');
      retryBoot();
      return;
    }

    ensureCriticalStyle();

    var creators = Array.isArray(widgetData.creators) ? widgetData.creators.slice(0, 4) : [];
    var featuredCreator = asText(widgetData.featuredCreator) || asText(creators[0] && creators[0].name) || '更多创作者';
    var nodeName = asText(widgetData.nodeName) || '未命名节点';
    var siteName = asText(widgetData.siteName) || '本站';
    var protocol = asText(widgetData.protocol) || 'GALAXY-X9';
    var joinUrl = normalizeUrl(widgetData.joinUrl) || normalizeUrl(widgetData.hubBaseUrl) || normalizeUrl(widgetData.siteUrl) || '';
    var nodeAvatar = normalizeUrl(widgetData.nodeAvatar);
    var moreCreatorCount = Math.max(0, Number(widgetData.moreCreatorCount) || 0);
    var healthy = Boolean(widgetData.healthy);
    var statusLabel = asText(widgetData.statusLabel);
    var realtimeBroadcast = normalizeRealtimeBroadcast(widgetData.realtimeBroadcast);

    var root = document.createElement('div');
    root.id = ROOT_ID;
    root.className = 'ah-gxw-container';
    root.innerHTML = '';
    document.body.appendChild(root);

    var panel = document.createElement('div');
    panel.className = 'ah-gxw-panel';
    panel.setAttribute('aria-hidden', 'true');
    panel.innerHTML =
      '  <div class="ah-gxw-header">' +
      '    <div class="ah-gxw-header-left">' +
      '      <div>' +
      '        <h3 class="ah-gxw-title">已链接至主星</h3>' +
      '        <p class="ah-gxw-subtitle">protocol: ' + escapeHtml(protocol) + '</p>' +
      '      </div>' +
      '    </div>' +
      '    <span class="ah-gxw-verified">主星已链接</span>' +
      '  </div>' +
      '  <div class="ah-gxw-node">' +
      '    <div class="ah-gxw-node-avatar">' + avatarMarkup(nodeAvatar, nodeName) + '</div>' +
      '    <div>' +
      '      <p class="ah-gxw-node-name">' + escapeHtml(nodeName) + '</p>' +
      '      <p class="ah-gxw-node-meta">' + escapeHtml(siteName) + '</p>' +
      '    </div>' +
      '  </div>' +
      '  <div class="ah-gxw-creator-heads">' + creatorHeadMarkup(creators) + (moreCreatorCount > 0 ? '<div class="ah-gxw-creator-more">+' + moreCreatorCount + '</div>' : '') + '</div>' +
      '  <p class="ah-gxw-desc"><strong>' + escapeHtml(featuredCreator) + '</strong> 也在主星，快来加入银河协议，一起扩展你的创作者星图。</p>' +
      '  <button type="button" class="ah-gxw-action">进入主星舱库</button>';
    document.body.appendChild(panel);

    var statusBubble = document.createElement('div');
    statusBubble.className = 'ah-gxw-status-bubble';
    statusBubble.setAttribute('aria-hidden', 'true');
    statusBubble.setAttribute('role', 'status');
    statusBubble.setAttribute('aria-live', 'polite');
    statusBubble.setAttribute('data-tail', 'bottom');
    statusBubble.innerHTML = '<div class="ah-gxw-status-bubble-text">' + buildStatusBubbleHtml(statusLabel, healthy) + '</div>';
    document.body.appendChild(statusBubble);
    log('info', 'status bubble mounted');

    var action = panel.querySelector('.ah-gxw-action');
    var expanded = false;
    var statusBubbleTimer = null;
    var statusBubbleCleanupTimer = null;
    var statusBubbleMode = 'none';
    var statusBubbleRenderToken = 0;
    var statusBubbleHideAt = 0;
    var statusBubbleRemainingMs = 0;
    var statusBubblePausedByHover = false;
    var mascotBubbleQueue = [];

    statusBubble.addEventListener('mouseenter', function () {
      if (statusBubbleMode === 'auto' && statusBubbleTimer) {
        statusBubblePausedByHover = true;
        pauseStatusBubbleAutoHide();
      }
    });
    statusBubble.addEventListener('mouseleave', function () {
      if (statusBubblePausedByHover) {
        statusBubblePausedByHover = false;
        resumeStatusBubbleAutoHide();
      }
    });
    statusBubble.addEventListener('focusin', function () {
      if (statusBubbleMode === 'auto' && statusBubbleTimer) {
        statusBubblePausedByHover = true;
        pauseStatusBubbleAutoHide();
      }
    });
    statusBubble.addEventListener('focusout', function () {
      if (statusBubblePausedByHover) {
        statusBubblePausedByHover = false;
        resumeStatusBubbleAutoHide();
      }
    });
    var publicStatusSocket = null;
    var publicStatusReconnectTimer = null;
    var publicStatusReconnectAttempts = 0;
    var mascotReady = false;
    var recentMascotBubbleIds = {};
    var widgetActive = true;
    enforceDock(root);

    window.addEventListener('resize', function () {
      enforceDock(root);
      applyMaintenanceLive2dSize();
      syncMascotPanelPosition(panel);
      syncMascotStatusBubblePosition(statusBubble);
    });

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        processMascotBubbleQueue();
      }
    });

    function setExpanded(value) {
      expanded = Boolean(value);
      log('debug', 'setExpanded=' + expanded);
      root.classList.toggle('ah-gxw-expanded', expanded);
      panel.classList.toggle('ah-gxw-panel-open', expanded);
      if (panel) {
        panel.setAttribute('aria-hidden', expanded ? 'false' : 'true');
      }
      if (!expanded) {
        window.setTimeout(processMascotBubbleQueue, 160);
      }
    }

    function setStatusBubbleVisible(value) {
      if (!statusBubble) {
        return;
      }
      log('debug', value ? 'status bubble show' : 'status bubble hide');
      statusBubble.classList.toggle('ah-gxw-status-bubble-visible', Boolean(value));
      statusBubble.setAttribute('aria-hidden', value ? 'false' : 'true');
    }

    function setStatusBubbleContent(html) {
      var textNode = statusBubble && statusBubble.querySelector('.ah-gxw-status-bubble-text');
      if (!textNode) {
        return;
      }
      textNode.innerHTML = html;
    }

    function clearStatusBubbleModeClasses() {
      if (!statusBubble) {
        return;
      }
      statusBubble.classList.remove('ah-gxw-status-bubble-auto', 'ah-gxw-status-bubble-article');
      statusBubble.style.width = '';
      statusBubble.style.top = '';
      statusBubble.style.bottom = '';
      statusBubble.setAttribute('data-tail', 'bottom');
    }

    function prepareStatusBubbleRender() {
      statusBubbleRenderToken += 1;
      if (statusBubbleCleanupTimer) {
        window.clearTimeout(statusBubbleCleanupTimer);
        statusBubbleCleanupTimer = null;
      }
      if (statusBubble) {
        statusBubble.style.width = '';
        statusBubble.style.top = '';
        statusBubble.style.bottom = '';
      }
    }

    function lockStatusBubbleExitWidth() {
      if (!statusBubble) {
        return;
      }
      var rect = statusBubble.getBoundingClientRect();
      if (rect && rect.width > 0) {
        statusBubble.style.width = rect.width + 'px';
      }
    }

    function hideStatusBubble(options) {
      var renderToken = statusBubbleRenderToken;
      lockStatusBubbleExitWidth();
      setStatusBubbleVisible(false);
      if (statusBubbleTimer) {
        window.clearTimeout(statusBubbleTimer);
        statusBubbleTimer = null;
      }
      statusBubbleHideAt = 0;
      statusBubbleRemainingMs = 0;
      statusBubblePausedByHover = false;
      statusBubbleMode = 'none';
      if (statusBubble) {
        if (statusBubbleCleanupTimer) {
          window.clearTimeout(statusBubbleCleanupTimer);
        }
        statusBubbleCleanupTimer = window.setTimeout(function () {
          if (renderToken !== statusBubbleRenderToken || statusBubbleMode !== 'none') {
            return;
          }
          clearStatusBubbleModeClasses();
          statusBubbleCleanupTimer = null;
        }, STATUS_BUBBLE_EXIT_CLEANUP_MS);
      }
      if (!options || options.resumeQueue !== false) {
        window.setTimeout(processMascotBubbleQueue, 180);
      }
    }

    function setWidgetActive(value) {
      widgetActive = Boolean(value);
      if (widgetActive) {
        setMascotVisible(true);
        root.classList.add('ah-gxw-visible');
        return;
      }
      mascotBubbleQueue = [];
      setExpanded(false);
      hideStatusBubble({ resumeQueue: false });
      root.classList.remove('ah-gxw-visible');
      setMascotVisible(false);
    }

    function enqueueMascotBubble(event) {
      if (!shouldAcceptMascotBubble(event, realtimeBroadcast)) {
        return;
      }
      var bubble = normalizeMascotRealtimeEvent(event);
      if (!bubble) {
        return;
      }
      if (isDuplicateMascotBubble(bubble, recentMascotBubbleIds)) {
        return;
      }
      rememberMascotBubbleId(bubble, recentMascotBubbleIds);
      if (expanded) {
        setExpanded(false);
      }
      if (statusBubbleMode === 'status') {
        hideStatusBubble({ resumeQueue: false });
      }
      mascotBubbleQueue.push(bubble);
      while (mascotBubbleQueue.length > MASCOT_BUBBLE_QUEUE_MAX) {
        mascotBubbleQueue.shift();
      }
      processMascotBubbleQueue();
    }

    function processMascotBubbleQueue() {
      if (!widgetActive || document.hidden || !mascotReady || !root.classList.contains('ah-gxw-visible') || expanded) {
        return;
      }
      if (statusBubbleMode !== 'none' || !mascotBubbleQueue.length) {
        return;
      }
      showMascotBubble(mascotBubbleQueue.shift());
    }

    function computeBubbleHideMs(bubble) {
      var base = MASCOT_BUBBLE_HIDE_MS;
      if (!bubble) {
        return base;
      }
      if (bubble.type === 'mascot_article_card') {
        return Math.min(MASCOT_BUBBLE_HIDE_MS_MAX, Math.max(base, 9000));
      }
      var text = '';
      if (typeof bubble.title === 'string') text += bubble.title;
      if (typeof bubble.message === 'string') text += ' ' + bubble.message;
      if (typeof bubble.nodeName === 'string') text += ' ' + bubble.nodeName;
      var len = text.trim().length;
      var dynamic = MASCOT_BUBBLE_HIDE_MS_MIN + Math.floor(len / 30) * 600;
      return Math.min(MASCOT_BUBBLE_HIDE_MS_MAX, Math.max(MASCOT_BUBBLE_HIDE_MS_MIN, dynamic));
    }

    function scheduleStatusBubbleHide(durationMs) {
      if (statusBubbleTimer) {
        window.clearTimeout(statusBubbleTimer);
        statusBubbleTimer = null;
      }
      var safe = Math.max(800, Number(durationMs) || MASCOT_BUBBLE_HIDE_MS);
      statusBubbleHideAt = Date.now() + safe;
      statusBubbleRemainingMs = safe;
      statusBubbleTimer = window.setTimeout(function () {
        hideStatusBubble();
      }, safe);
    }

    function pauseStatusBubbleAutoHide() {
      if (!statusBubbleTimer || !statusBubbleHideAt) {
        return;
      }
      statusBubbleRemainingMs = Math.max(0, statusBubbleHideAt - Date.now());
      window.clearTimeout(statusBubbleTimer);
      statusBubbleTimer = null;
    }

    function resumeStatusBubbleAutoHide() {
      if (!statusBubbleRemainingMs || statusBubbleMode === 'none') {
        return;
      }
      var remaining = Math.max(800, statusBubbleRemainingMs);
      statusBubbleHideAt = Date.now() + remaining;
      statusBubbleTimer = window.setTimeout(function () {
        hideStatusBubble();
      }, remaining);
    }

    function showMascotBubble(bubble) {
      if (!bubble || statusBubbleMode !== 'none') {
        return false;
      }
      statusBubbleMode = 'auto';
      prepareStatusBubbleRender();
      if (statusBubble) {
        statusBubble.classList.add('ah-gxw-status-bubble-auto');
        statusBubble.classList.toggle('ah-gxw-status-bubble-article', bubble.type === 'mascot_article_card');
      }
      setStatusBubbleContent(buildMascotRealtimeHtml(bubble));
      var positioned = false;
      try {
        positioned = syncMascotStatusBubblePosition(statusBubble) === true;
      } catch (error) {
        log('error', 'sync mascot bubble position failed', error);
      }
      if (!positioned) {
        statusBubbleMode = 'none';
        if (statusBubble) {
          clearStatusBubbleModeClasses();
        }
        mascotBubbleQueue.unshift(bubble);
        window.setTimeout(processMascotBubbleQueue, 500);
        return false;
      }
      setStatusBubbleVisible(true);
      scheduleStatusBubbleHide(computeBubbleHideMs(bubble));
      return true;
    }

    function updatePublicStatus(snapshot) {
      if (!snapshot || typeof snapshot !== 'object' || snapshot.type === 'mascot_bubble' || snapshot.type === 'mascot_article_card') {
        return;
      }
      if (typeof snapshot.healthy !== 'undefined') {
        healthy = Boolean(snapshot.healthy);
      }
      if (asText(snapshot.statusLabel)) {
        statusLabel = asText(snapshot.statusLabel);
      }
      if (typeof snapshot.widgetEnabled !== 'undefined' || typeof snapshot.linked !== 'undefined') {
        setWidgetActive(Boolean(snapshot.widgetEnabled !== false && snapshot.linked !== false));
        if (!widgetActive) {
          return;
        }
      }
      if (snapshot.realtimeBroadcast && typeof snapshot.realtimeBroadcast === 'object') {
        realtimeBroadcast = normalizeRealtimeBroadcast(snapshot.realtimeBroadcast);
        if (!realtimeBroadcast.enabled) {
          mascotBubbleQueue = [];
          if (statusBubbleMode === 'auto') {
            hideStatusBubble({ resumeQueue: false });
          }
        }
      }
      if (statusBubbleMode !== 'auto') {
        setStatusBubbleContent(buildStatusBubbleHtml(statusLabel, healthy));
      }
    }

    function handlePublicStatusPayload(payload) {
      if (!payload || typeof payload !== 'object') {
        return;
      }
      if (payload.type === 'mascot_bubble') {
        enqueueMascotBubble(payload);
        return;
      }
      if (payload.type === 'mascot_article_card') {
        enqueueMascotBubble(payload);
        return;
      }
      updatePublicStatus(payload);
    }

    function handlePublicStatusRaw(raw) {
      var payload = null;
      try {
        payload = JSON.parse(raw || '{}');
      } catch (_error) {
        return;
      }
      handlePublicStatusPayload(payload);
    }

    function schedulePublicStatusReconnect() {
      if (publicStatusReconnectTimer) {
        return;
      }
      publicStatusReconnectAttempts += 1;
      var delay = Math.min(
        PUBLIC_STATUS_RECONNECT_MAX_MS,
        PUBLIC_STATUS_RECONNECT_MIN_MS * Math.pow(2, Math.min(publicStatusReconnectAttempts - 1, 4))
      );
      publicStatusReconnectTimer = window.setTimeout(function () {
        publicStatusReconnectTimer = null;
        connectPublicStatusStream();
      }, delay);
    }

    function connectPublicStatusStream() {
      if (publicStatusSocket) {
        return;
      }
      if (!PUBLIC_STATUS_WS_PATH) {
        return;
      }
      if (!('WebSocket' in window)) {
        return;
      }
      try {
        var wsUrl = (window.location.protocol === 'https:' ? 'wss://' : 'ws://') +
          window.location.host + PUBLIC_STATUS_WS_PATH;
        publicStatusSocket = new WebSocket(wsUrl);
        publicStatusSocket.addEventListener('open', function () {
          publicStatusReconnectAttempts = 0;
        });
        publicStatusSocket.addEventListener('message', function (event) {
          handlePublicStatusRaw(event.data);
        });
        publicStatusSocket.addEventListener('close', function () {
          publicStatusSocket = null;
          schedulePublicStatusReconnect();
        });
        publicStatusSocket.addEventListener('error', function () {
          try {
            publicStatusSocket.close();
          } catch (_error) {
          }
        });
      } catch (_error) {
        publicStatusSocket = null;
      }
    }

    initLive2dMascot(function () {
      log('info', 'single click callback');
      hideStatusBubble();
      setExpanded(true);
      syncMascotPanelPosition(panel);
    }, function () {
      log('info', 'double click callback');
      hideStatusBubble();
      // Double-click should always open panel; do not toggle.
      setExpanded(true);
      syncMascotPanelPosition(panel);
    }, function () {
      log('info', 'mascot ready');
      mascotReady = true;
      syncMascotPanelPosition(panel);
      syncMascotStatusBubblePosition(statusBubble);
      processMascotBubbleQueue();
    });

    connectPublicStatusStream();

    if (action && joinUrl) {
      action.addEventListener('click', function () {
        window.open(joinUrl, '_blank', 'noopener,noreferrer');
      });
    }

    document.addEventListener('click', function (event) {
      var bubbleOpen = Boolean(
        statusBubble && statusBubble.classList.contains('ah-gxw-status-bubble-visible')
      );
      if (!expanded && !bubbleOpen) {
        return;
      }
      var target = event.target;
      if (target instanceof Node && root.contains(target)) {
        return;
      }
      if (target instanceof Node && panel.contains(target)) {
        return;
      }
      if (target instanceof Node && statusBubble.contains(target)) {
        return;
      }
      if (isMascotEventTarget(target)) {
        return;
      }
      setExpanded(false);
      hideStatusBubble();
    }, true);

    document.addEventListener('keydown', function (event) {
      var bubbleOpen = Boolean(
        statusBubble && statusBubble.classList.contains('ah-gxw-status-bubble-visible')
      );
      if (event.key === 'Escape' && (expanded || bubbleOpen)) {
        setExpanded(false);
        hideStatusBubble();
      }
    });

    window.setTimeout(function () {
      if (!widgetActive) {
        return;
      }
      root.classList.add('ah-gxw-visible');
      log('info', 'widget visible');
      processMascotBubbleQueue();
    }, 120);

    window.addEventListener('beforeunload', function () {
      if (publicStatusReconnectTimer) {
        window.clearTimeout(publicStatusReconnectTimer);
        publicStatusReconnectTimer = null;
      }
      if (publicStatusSocket) {
        try {
          publicStatusSocket.close();
        } catch (_error) {
        }
        publicStatusSocket = null;
      }
    });

  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }

  function retryBoot() {
    retryCount += 1;
    log('debug', 'retry boot #' + retryCount);
    if (retryCount > RETRY_MAX) {
      log('warn', 'retry max reached');
      return;
    }
    window.setTimeout(boot, 120);
  }

  function enforceDock(root) {
    if (!root || !root.style) {
      return;
    }
    root.style.setProperty('position', 'fixed', 'important');
    root.style.setProperty('right', '1.4rem', 'important');
    root.style.setProperty('bottom', '1.4rem', 'important');
    root.style.setProperty('left', 'auto', 'important');
    root.style.setProperty('top', 'auto', 'important');
    root.style.setProperty('z-index', '2147483000', 'important');
    root.style.setProperty('margin', '0', 'important');
  }
  function ensureCriticalStyle() {
    if (document.getElementById(CRITICAL_STYLE_ID)) {
      return;
    }
    var style = document.createElement('style');
    style.id = CRITICAL_STYLE_ID;
    style.textContent =
      '#' + ROOT_ID + '.ah-gxw-container{' +
      'position:fixed!important;' +
      'right:1.4rem!important;' +
      'bottom:1.4rem!important;' +
      'left:auto!important;' +
      'top:auto!important;' +
      'z-index:2147483000!important;' +
      'margin:0!important;' +
      'pointer-events:none;' +
      '}' +
      '#' + ROOT_ID + '.ah-gxw-container.ah-gxw-visible{pointer-events:auto;}' +
      '@media (max-width:768px){' +
      '#' + ROOT_ID + '.ah-gxw-container{' +
      'right:.82rem!important;' +
      'bottom:.82rem!important;' +
      '}' +
      '}';
    document.head.appendChild(style);
  }

  function initLive2dMascot(onSingleClick, onDoubleClick, onReady) {
    var existingDefaultWidget = document.getElementById('live2d-widget');
    var existingDefaultCanvas = document.getElementById('live2dcanvas');
    var bindTryCount = 0;

    function resolveMascotElements() {
      var customWidget = document.getElementById(LIVE2D_WIDGET_ID);
      var customCanvas = document.getElementById(LIVE2D_CANVAS_ID);
      if (customWidget || customCanvas) {
        return {
          widget: customWidget,
          canvas: customCanvas,
          source: 'custom'
        };
      }
      var defaultWidget = document.getElementById('live2d-widget');
      var defaultCanvas = document.getElementById('live2dcanvas');
      var hasDefault = Boolean(defaultWidget || defaultCanvas);
      if (!hasDefault) {
        return { widget: null, canvas: null, source: 'none' };
      }

      var widgetChanged = defaultWidget && defaultWidget !== existingDefaultWidget;
      var canvasChanged = defaultCanvas && defaultCanvas !== existingDefaultCanvas;
      var canUseDefault = (!existingDefaultWidget && !existingDefaultCanvas) || widgetChanged || canvasChanged;
      if (!canUseDefault) {
        return { widget: null, canvas: null, source: 'blocked-default-existing' };
      }
      return {
        widget: defaultWidget,
        canvas: defaultCanvas,
        source: 'default-fallback'
      };
    }

    ensureLive2dScript(function () {
      log('info', 'live2d script ready');
      if (typeof window.L2Dwidget === 'undefined') {
        log('error', 'L2Dwidget undefined after script load');
        return;
      }
      if (!document.getElementById(LIVE2D_CANVAS_ID) && !document.getElementById(LIVE2D_WIDGET_ID)) {
        try {
          log('info', 'L2Dwidget.init', {
            model: LIVE2D_MODEL_JSON,
            display: {
              width: LIVE2D_WIDTH,
              height: LIVE2D_HEIGHT,
              hOffset: LIVE2D_H_OFFSET,
              vOffset: LIVE2D_V_OFFSET
            },
            name: {
              canvas: LIVE2D_CANVAS_ID,
              div: LIVE2D_WIDGET_ID
            }
          });
          window.L2Dwidget.init({
            model: { jsonPath: LIVE2D_MODEL_JSON },
            display: { position: 'right', width: LIVE2D_WIDTH, height: LIVE2D_HEIGHT, hOffset: LIVE2D_H_OFFSET, vOffset: LIVE2D_V_OFFSET },
            mobile: { show: false },
            name: { canvas: LIVE2D_CANVAS_ID, div: LIVE2D_WIDGET_ID },
            react: { opacityDefault: 0.85, opacityOnHover: 1 }
          });
        } catch (_error) {
          log('error', 'L2Dwidget.init failed', _error);
          return;
        }
      }
      var clickTimer = null;
      var lastClickAt = 0;
      var suppressNativeDblUntil = 0;
      var suppressSingleUntil = 0;
      var readyNotified = false;

      function notifyReadyOnce() {
        if (readyNotified) {
          return;
        }
        readyNotified = true;
        if (typeof onReady === 'function') {
          onReady();
        }
      }

      function bindTarget(target) {
        if (!target || target.getAttribute('data-ah-bound') === '1') {
          return;
        }
        target.setAttribute('data-ah-bound', '1');
        log('info', 'bind events on target #' + (target.id || '(no-id)'));
        target.style.cursor = 'pointer';
        target.addEventListener('pointerdown', function (event) {
          log('debug', 'pointerdown', { targetId: target.id, type: event.type });
        }, true);
        target.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopImmediatePropagation();
          event.stopPropagation();
          log('debug', 'click captured', { targetId: target.id, type: event.type });
          var now = Date.now();
          if (now < suppressSingleUntil) {
            log('debug', 'click ignored by suppressSingleUntil');
            return;
          }
          if (lastClickAt > 0 && (now - lastClickAt) <= DOUBLE_CLICK_THRESHOLD_MS) {
            lastClickAt = 0;
            if (clickTimer) {
              window.clearTimeout(clickTimer);
              clickTimer = null;
            }
            suppressNativeDblUntil = now + 420;
            suppressSingleUntil = now + 420;
            log('info', 'double click detected by timing');
            if (typeof onDoubleClick === 'function') {
              onDoubleClick();
            }
            return;
          }
          lastClickAt = now;
          if (clickTimer) {
            window.clearTimeout(clickTimer);
          }
          clickTimer = window.setTimeout(function () {
            clickTimer = null;
            lastClickAt = 0;
            if (Date.now() < suppressSingleUntil) {
              log('debug', 'single click canceled by suppressSingleUntil');
              return;
            }
            log('debug', 'single click dispatch');
            if (typeof onSingleClick === 'function') {
              onSingleClick();
            }
          }, MASCOT_CLICK_DELAY_MS);
        }, true);
        target.addEventListener('dblclick', function (event) {
          event.preventDefault();
          event.stopImmediatePropagation();
          event.stopPropagation();
          if (Date.now() < suppressNativeDblUntil) {
            log('debug', 'native dblclick ignored (already handled by timing)');
            return;
          }
          log('debug', 'dblclick captured', { targetId: target.id, type: event.type });
          lastClickAt = 0;
          suppressSingleUntil = Date.now() + 300;
          if (clickTimer) {
            window.clearTimeout(clickTimer);
            clickTimer = null;
          }
          if (typeof onDoubleClick === 'function') {
            onDoubleClick();
          }
        }, true);
        notifyReadyOnce();
      }

      function tryBindMascot() {
        bindTryCount += 1;
        var resolved = resolveMascotElements();
        var cv = resolved.canvas;
        var w = resolved.widget;
        if (bindTryCount <= 3 || bindTryCount % 10 === 0) {
          log('debug', 'try bind #' + bindTryCount, {
            source: resolved.source,
            hasCanvas: Boolean(cv),
            hasWidget: Boolean(w)
          });
        }
        if (!cv && !w) {
          return false;
        }
        if (resolved.source === 'default-fallback') {
          log('warn', 'fallback to default live2d ids');
        }
        applyMaintenanceLive2dSize();
        // Avoid duplicate click chain: bind only one primary target.
        bindTarget(cv || w);
        return true;
      }

      // Maintenance plugin style: wait for live2d canvas appears then bind.
      var wait = window.setInterval(function () {
        if (tryBindMascot()) {
          window.clearInterval(wait);
        }
      }, 500);

      // Maintenance plugin style fallback timeout.
      window.setTimeout(function () {
        window.clearInterval(wait);
        var ok = tryBindMascot();
        log('info', 'bind timeout fallback result=' + ok);
      }, 15000);
    });
  }

  // Keep mascot size/position identical to maintenance plugin baseline.
  function applyMaintenanceLive2dSize() {
    var widget = document.getElementById(LIVE2D_WIDGET_ID);
    if (widget) {
      widget.style.width = LIVE2D_WIDTH + 'px';
      widget.style.height = LIVE2D_HEIGHT + 'px';
      widget.style.position = 'fixed';
      widget.style.right = LIVE2D_H_OFFSET + 'px';
      widget.style.bottom = LIVE2D_V_OFFSET + 'px';
      widget.style.left = 'auto';
      widget.style.top = 'auto';
      widget.style.pointerEvents = 'auto';
      widget.style.zIndex = '2147482999';
    }
    var canvas = document.getElementById(LIVE2D_CANVAS_ID);
    if (canvas) {
      canvas.style.width = LIVE2D_WIDTH + 'px';
      canvas.style.height = LIVE2D_HEIGHT + 'px';
      canvas.style.pointerEvents = 'auto';
      canvas.style.cursor = 'pointer';
      canvas.style.zIndex = '2147482999';
    }
  }

  function setMascotVisible(value) {
    var display = value ? '' : 'none';
    var widget = document.getElementById(LIVE2D_WIDGET_ID) || document.getElementById('live2d-widget');
    var canvas = document.getElementById(LIVE2D_CANVAS_ID) || document.getElementById('live2dcanvas');
    if (widget) {
      widget.style.display = display;
    }
    if (canvas) {
      canvas.style.display = display;
    }
  }

  function ensureLive2dScript(done) {
    if (typeof window.L2Dwidget !== 'undefined') {
      log('debug', 'L2Dwidget already exists');
      done();
      return;
    }
    var script = document.getElementById(LIVE2D_SCRIPT_ID);
    if (script) {
      log('debug', 'reuse existing live2d script tag');
      script.addEventListener('load', function () {
        log('debug', 'existing script loaded');
        done();
      }, { once: true });
      return;
    }
    log('info', 'append live2d script ' + LIVE2D_SCRIPT_SRC_LOCAL);
    script = document.createElement('script');
    script.id = LIVE2D_SCRIPT_ID;
    script.async = true;
    script.src = LIVE2D_SCRIPT_SRC_LOCAL;
    script.addEventListener('load', function () {
      log('info', 'live2d script load ok');
      done();
    }, { once: true });
    script.addEventListener('error', function (event) {
      log('error', 'live2d script load failed', event);
    }, { once: true });
    document.head.appendChild(script);
  }

  function waitForLive2dCanvas(onReady) {
    var attempts = 0;
    var timer = window.setInterval(function () {
      attempts += 1;
      var canvas = document.getElementById(LIVE2D_CANVAS_ID);
      if (canvas) {
        window.clearInterval(timer);
        onReady(canvas);
        return;
      }
      if (attempts >= 80) {
        window.clearInterval(timer);
      }
    }, 150);
  }

  function syncMascotPanelPosition(panel) {
    if (!panel || !panel.style) {
      return;
    }
    var viewportPadding = 8;
    var rawWidth = panel.offsetWidth || parseFloat(window.getComputedStyle(panel).width) || 324;
    var maxWidth = Math.max(220, viewportInnerWidth() - viewportPadding * 2);
    if (rawWidth > maxWidth) {
      panel.style.width = maxWidth + 'px';
    } else {
      panel.style.width = '';
    }
    var panelWidth = panel.offsetWidth || rawWidth;
    var panelHeight = panel.offsetHeight || parseFloat(window.getComputedStyle(panel).height) || 320;
    var headAnchor = getMascotHeadAnchor();
    if (!headAnchor) {
      var fallbackLeft = clampNumber(viewportInnerWidth() - panelWidth - 24 + PANEL_RIGHT_SHIFT_PX, viewportPadding, Math.max(viewportPadding, viewportInnerWidth() - panelWidth - viewportPadding));
      var fallbackBottom = clampNumber(246, 12, Math.max(12, viewportInnerHeight() - panelHeight - 10));
      panel.style.left = fallbackLeft + 'px';
      panel.style.right = 'auto';
      panel.style.bottom = fallbackBottom + 'px';
      panel.style.top = 'auto';
      panel.style.setProperty('--ah-gxw-tail-left', Math.max(24, panelWidth - 30) + 'px');
      return;
    }
    var left = headAnchor.x - panelWidth * 0.78 + PANEL_RIGHT_SHIFT_PX;
    left = clampNumber(left, viewportPadding, Math.max(viewportPadding, viewportInnerWidth() - panelWidth - viewportPadding));
    var baseBottom = Math.max(12, viewportInnerHeight() - headAnchor.y + 12);
    var maxBottom = Math.max(12, viewportInnerHeight() - panelHeight - 10);
    var bottom = clampNumber(baseBottom, 12, maxBottom);
    panel.style.left = left + 'px';
    panel.style.right = 'auto';
    panel.style.bottom = bottom + 'px';
    panel.style.top = 'auto';
    var arrowLeft = clampNumber(headAnchor.x - left, 24, Math.max(24, panelWidth - 24));
    panel.style.setProperty('--ah-gxw-tail-left', arrowLeft + 'px');
  }

  function syncMascotStatusBubblePosition(bubble) {
    if (!bubble || !bubble.style) {
      return false;
    }
    var viewportPadding = 16;
    var headAnchor = getMascotHeadAnchor();
    if (!headAnchor) {
      return false;
    }
    var maxBubbleWidth = Math.max(160, viewportInnerWidth() - viewportPadding * 2);
    // Measure layout box, NOT getBoundingClientRect: the bubble carries transform: scale(.93)
    // while hidden, which would shrink the rect by ~7% and let us position so the post-show
    // size overflows the viewport on the right edge.
    var probeWidth = bubble.offsetWidth || 0;
    if (probeWidth > maxBubbleWidth) {
      bubble.style.width = maxBubbleWidth + 'px';
    } else if (bubble.style.width && parseFloat(bubble.style.width) >= maxBubbleWidth + 1) {
      // previous run may have written a stale max-width; allow CSS to retake control if window grew
      bubble.style.width = '';
    }
    var bubbleWidth = bubble.offsetWidth || 220;
    var bubbleHeight = bubble.offsetHeight || 44;
    // Tail visual: CSS triangles are 20x10 px (10px border-left + 10px border-right + 10px border-top)
    var tailLength = 10;
    var anchorGap = tailLength + 4;
    var tailHalfBase = 12; // half of 20 + a small safe margin so tail stays inside bubble corner radius

    var maxLeft = Math.max(viewportPadding, viewportInnerWidth() - bubbleWidth - viewportPadding);

    var roomAbove = headAnchor.y - anchorGap;
    var roomBelow = viewportInnerHeight() - headAnchor.y - anchorGap;
    var direction = 'bottom';
    if (roomAbove >= bubbleHeight + 12) {
      direction = 'bottom';
    } else if (roomBelow >= bubbleHeight + 12) {
      direction = 'top';
    } else if (headAnchor.x >= bubbleWidth + anchorGap + 12) {
      direction = 'right';
    } else if (viewportInnerWidth() - headAnchor.x >= bubbleWidth + anchorGap + 12) {
      direction = 'left';
    }

    var left;
    var top;
    var tailLeftPct = '50%';
    var tailTopPct = '50%';
    var translateX = '0px';
    var translateY = '12px';

    if (direction === 'bottom' || direction === 'top') {
      left = headAnchor.x - (bubbleWidth / 2);
      left = clampNumber(left, viewportPadding, maxLeft);
      var tailX = clampNumber(headAnchor.x - left, tailHalfBase, Math.max(tailHalfBase, bubbleWidth - tailHalfBase));
      tailLeftPct = tailX + 'px';
      if (direction === 'bottom') {
        top = headAnchor.y - anchorGap - bubbleHeight;
        translateY = '12px';
      } else {
        top = headAnchor.y + anchorGap;
        translateY = '-12px';
      }
      top = clampNumber(top, viewportPadding, Math.max(viewportPadding, viewportInnerHeight() - bubbleHeight - viewportPadding));
    } else {
      top = headAnchor.y - (bubbleHeight / 2);
      top = clampNumber(top, viewportPadding, Math.max(viewportPadding, viewportInnerHeight() - bubbleHeight - viewportPadding));
      var tailY = clampNumber(headAnchor.y - top, tailHalfBase, Math.max(tailHalfBase, bubbleHeight - tailHalfBase));
      tailTopPct = tailY + 'px';
      if (direction === 'right') {
        left = headAnchor.x - anchorGap - bubbleWidth;
        translateX = '12px';
      } else {
        left = headAnchor.x + anchorGap;
        translateX = '-12px';
      }
      left = clampNumber(left, viewportPadding, maxLeft);
    }

    bubble.style.left = left + 'px';
    bubble.style.right = 'auto';
    bubble.style.top = top + 'px';
    bubble.style.bottom = 'auto';
    bubble.setAttribute('data-tail', direction);
    bubble.style.setProperty('--ah-gxw-bubble-tail-left', tailLeftPct);
    bubble.style.setProperty('--ah-gxw-bubble-tail-top', tailTopPct);
    bubble.style.setProperty('--ah-gxw-bubble-translate-x', translateX);
    bubble.style.setProperty('--ah-gxw-bubble-translate-y', translateY);
    bubble.style.setProperty('--ah-gxw-status-tail-left', tailLeftPct);
    return true;
  }

  function isMascotEventTarget(target) {
    if (!(target instanceof Element)) {
      return false;
    }
    if (
      target.id === LIVE2D_CANVAS_ID ||
      target.id === LIVE2D_WIDGET_ID ||
      target.id === 'live2dcanvas' ||
      target.id === 'live2d-widget'
    ) {
      return true;
    }
    return Boolean(target.closest(
      '#' + LIVE2D_CANVAS_ID + ',#' + LIVE2D_WIDGET_ID + ',#live2dcanvas,#live2d-widget'
    ));
  }

  function getMascotAnchor() {
    var canvas = document.getElementById(LIVE2D_CANVAS_ID);
    if (canvas) {
      return canvas;
    }
    var widget = document.getElementById(LIVE2D_WIDGET_ID);
    if (widget) {
      var innerCanvas = widget.querySelector('canvas');
      return innerCanvas || widget;
    }
    return null;
  }

  function getVisibleMascotRect() {
    var anchor = getMascotAnchor();
    if (!anchor) {
      return null;
    }
    var rect = anchor.getBoundingClientRect();
    if (!rect || rect.width < 48 || rect.height < 72) {
      return null;
    }
    if (rect.right < 0 || rect.left > viewportInnerWidth() || rect.bottom < 0 || rect.top > viewportInnerHeight()) {
      return null;
    }
    return rect;
  }

  function getMascotHeadAnchor() {
    var rect = getVisibleMascotRect();
    if (!rect) {
      return null;
    }
    return {
      x: rect.left + (rect.width * 0.5),
      y: rect.top + Math.max(24, Math.min(rect.height * 0.24, 58))
    };
  }

  function enableFabDragging(root, fab, storageKey, onDragDone) {
    if (!root || !fab) {
      return;
    }
    var dragState = null;

    fab.addEventListener('pointerdown', function (event) {
      if (event.button !== 0) {
        return;
      }
      var rootRect = root.getBoundingClientRect();
      var fabRect = fab.getBoundingClientRect();
      dragState = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        startRight: viewportInnerWidth() - rootRect.right,
        startBottom: viewportInnerHeight() - rootRect.bottom,
        fabWidth: fabRect.width || 48,
        fabHeight: fabRect.height || 48,
        moved: false
      };
      try {
        fab.setPointerCapture(event.pointerId);
      } catch (_error) {
      }
      event.preventDefault();
    });

    fab.addEventListener('pointermove', function (event) {
      if (!dragState || event.pointerId !== dragState.pointerId) {
        return;
      }
      var dx = event.clientX - dragState.startX;
      var dy = event.clientY - dragState.startY;
      if (!dragState.moved && (Math.abs(dx) > 3 || Math.abs(dy) > 3)) {
        dragState.moved = true;
      }
      if (!dragState.moved) {
        return;
      }
      var maxRight = Math.max(0, viewportInnerWidth() - dragState.fabWidth - 4);
      var maxBottom = Math.max(0, viewportInnerHeight() - dragState.fabHeight - 4);
      var nextRight = clampNumber(dragState.startRight - dx, 0, maxRight);
      var nextBottom = clampNumber(dragState.startBottom - dy, 0, maxBottom);
      applyDock(root, nextRight, nextBottom);
    });

    function endDrag(event) {
      if (!dragState || event.pointerId !== dragState.pointerId) {
        return;
      }
      var moved = dragState.moved;
      try {
        fab.releasePointerCapture(event.pointerId);
      } catch (_error) {
      }
      dragState = null;
      if (!moved) {
        return;
      }
      persistDockPosition(root, storageKey);
      if (typeof onDragDone === 'function') {
        onDragDone();
      }
    }

    fab.addEventListener('pointerup', endDrag);
    fab.addEventListener('pointercancel', endDrag);
  }

  function applyDock(root, right, bottom) {
    if (!root || !root.style) {
      return;
    }
    root.style.setProperty('position', 'fixed', 'important');
    root.style.setProperty('right', right + 'px', 'important');
    root.style.setProperty('bottom', bottom + 'px', 'important');
    root.style.setProperty('left', 'auto', 'important');
    root.style.setProperty('top', 'auto', 'important');
    root.style.setProperty('z-index', '2147483000', 'important');
    root.style.setProperty('margin', '0', 'important');
  }

  function readDockFromRoot(root) {
    if (!root) {
      return { right: 22, bottom: 22 };
    }
    var right = parseFloat(root.style.right);
    var bottom = parseFloat(root.style.bottom);
    if (Number.isFinite(right) && Number.isFinite(bottom)) {
      return { right: right, bottom: bottom };
    }
    var rect = root.getBoundingClientRect();
    return {
      right: Math.max(0, viewportInnerWidth() - rect.right),
      bottom: Math.max(0, viewportInnerHeight() - rect.bottom)
    };
  }

  function clampDockToViewport(root, fab) {
    if (!root) {
      return;
    }
    var dock = readDockFromRoot(root);
    var fabRect = fab ? fab.getBoundingClientRect() : null;
    var fabWidth = fabRect ? fabRect.width : 48;
    var fabHeight = fabRect ? fabRect.height : 48;
    var maxRight = Math.max(0, viewportInnerWidth() - fabWidth - 4);
    var maxBottom = Math.max(0, viewportInnerHeight() - fabHeight - 4);
    applyDock(root, clampNumber(dock.right, 0, maxRight), clampNumber(dock.bottom, 0, maxBottom));
  }

  function persistDockPosition(root, storageKey) {
    try {
      var dock = readDockFromRoot(root);
      localStorage.setItem(storageKey, JSON.stringify({ right: dock.right, bottom: dock.bottom }));
    } catch (_error) {
    }
  }

  function restoreDockPosition(root, fab, storageKey) {
    try {
      var raw = localStorage.getItem(storageKey);
      if (!raw) {
        return false;
      }
      var parsed = JSON.parse(raw);
      if (!parsed || !Number.isFinite(Number(parsed.right)) || !Number.isFinite(Number(parsed.bottom))) {
        return false;
      }
      var fabRect = fab ? fab.getBoundingClientRect() : null;
      var fabWidth = fabRect ? fabRect.width : 48;
      var fabHeight = fabRect ? fabRect.height : 48;
      var maxRight = Math.max(0, viewportInnerWidth() - fabWidth - 4);
      var maxBottom = Math.max(0, viewportInnerHeight() - fabHeight - 4);
      var right = clampNumber(Number(parsed.right), 0, maxRight);
      var bottom = clampNumber(Number(parsed.bottom), 0, maxBottom);
      applyDock(root, right, bottom);
      return true;
    } catch (_error) {
      return false;
    }
  }

  function clampNumber(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function viewportInnerWidth() {
    var doc = document.documentElement;
    var width = doc && doc.clientWidth;
    if (typeof width === 'number' && width > 0) {
      return width;
    }
    var bodyWidth = document.body && document.body.clientWidth;
    if (typeof bodyWidth === 'number' && bodyWidth > 0) {
      return bodyWidth;
    }
    return 0;
  }

  function viewportInnerHeight() {
    var doc = document.documentElement;
    var height = doc && doc.clientHeight;
    if (typeof height === 'number' && height > 0) {
      return height;
    }
    var bodyHeight = document.body && document.body.clientHeight;
    if (typeof bodyHeight === 'number' && bodyHeight > 0) {
      return bodyHeight;
    }
    return 0;
  }

  function creatorHeadMarkup(list) {
    if (!list.length) {
      return '<div class="ah-gxw-creator-head"><div class="ah-gxw-creator-fallback">A</div></div>';
    }
    return list.map(function (item) {
      var name = asText(item && item.name) || 'A';
      var avatar = normalizeUrl(item && item.avatar);
      return (
        '<div class="ah-gxw-creator-head" title="' + escapeHtml(name) + '">' +
        avatarMarkup(avatar, name) +
        '</div>'
      );
    }).join('');
  }

  function avatarMarkup(url, label) {
    if (url) {
      return '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(label || '') + '" referrerpolicy="no-referrer" loading="lazy" />';
    }
    var text = asText(label).slice(0, 1).toUpperCase() || 'A';
    return '<div class="ah-gxw-node-avatar-fallback">' + escapeHtml(text) + '</div>';
  }

  function shipIcon(extraClass) {
    var uid = 'ahgxwship' + (++shipIconSeed);
    var shellGradientId = 'ship-shell-' + uid;
    var shellStrokeGradientId = 'ship-shell-stroke-' + uid;
    var canopyGradientId = 'ship-canopy-' + uid;
    var coreGradientId = 'ship-core-' + uid;
    var flameGradientId = 'ship-flame-' + uid;
    var ringGradientId = 'ship-ring-' + uid;
    var klass = ['ah-gxw-ship', extraClass || ''].join(' ').trim();
    var particles = [
      { tx: 20, ty: -36, d: 3.0, dl: 0.00, c: 'var(--ah-gxw-neon-blue)' },
      { tx: 35, ty: -12, d: 3.4, dl: 0.20, c: 'var(--ah-gxw-neon-purple)' },
      { tx: 34, ty: 16, d: 3.8, dl: 0.45, c: 'var(--ah-gxw-neon-pink)' },
      { tx: 14, ty: 34, d: 3.2, dl: 0.70, c: 'var(--ah-gxw-neon-blue)' },
      { tx: -14, ty: 34, d: 3.6, dl: 0.95, c: 'var(--ah-gxw-neon-purple)' },
      { tx: -34, ty: 16, d: 4.0, dl: 1.20, c: 'var(--ah-gxw-neon-pink)' },
      { tx: -35, ty: -12, d: 3.3, dl: 1.45, c: 'var(--ah-gxw-neon-blue)' },
      { tx: -20, ty: -36, d: 3.7, dl: 1.70, c: 'var(--ah-gxw-neon-purple)' }
    ];
    var particleMarkup = particles.map(function (p) {
      return '<i class="ah-gxw-particle" style="--tx:' + p.tx + ';--ty:' + p.ty + ';--dur:' + p.d + 's;--delay:' + p.dl + 's;--pc:' + p.c + ';"></i>';
    }).join('');
    return (
      '<span class="' + klass + '" aria-hidden="true">' +
      '  <span class="ah-gxw-ship-glow"></span>' +
      '  <svg class="ah-gxw-rings" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">' +
      '    <defs>' +
      '      <linearGradient id="' + ringGradientId + '" x1="0%" y1="0%" x2="100%" y2="0%">' +
      '        <stop offset="0%" stop-color="var(--ah-gxw-neon-blue)"/>' +
      '        <stop offset="55%" stop-color="#d6eeff"/>' +
      '        <stop offset="100%" stop-color="var(--ah-gxw-neon-purple)"/>' +
      '      </linearGradient>' +
      '    </defs>' +
      '    <g class="ah-gxw-ring-a">' +
      '      <ellipse cx="50" cy="50" rx="43" ry="13" fill="none" stroke="url(#' + ringGradientId + ')" stroke-width="1.55" stroke-opacity=".66">' +
      '        <animate attributeName="stroke-opacity" values=".44;.82;.44" dur="4.8s" repeatCount="indefinite"/>' +
      '      </ellipse>' +
      '      <circle r="1.35" fill="#ffffff">' +
      '        <animateMotion dur="5.6s" repeatCount="indefinite" path="M93 50 C80 58 64 63 50 63 C36 63 20 58 7 50 C20 42 36 37 50 37 C64 37 80 42 93 50 Z"/>' +
      '      </circle>' +
      '    </g>' +
      '    <g class="ah-gxw-ring-b">' +
      '      <ellipse cx="50" cy="50" rx="31" ry="9" fill="none" stroke="url(#' + ringGradientId + ')" stroke-width="1.15" stroke-opacity=".48">' +
      '        <animate attributeName="stroke-opacity" values=".34;.66;.34" dur="4.2s" repeatCount="indefinite"/>' +
      '      </ellipse>' +
      '      <circle r="1.1" fill="var(--ah-gxw-neon-blue)">' +
      '        <animateMotion dur="7.8s" repeatCount="indefinite" path="M81 50 C71 56 61 59 50 59 C39 59 29 56 19 50 C29 44 39 41 50 41 C61 41 71 44 81 50 Z"/>' +
      '      </circle>' +
      '    </g>' +
      '  </svg>' +
      '  <span class="ah-gxw-core">' +
      '    <svg class="ah-gxw-core-svg" viewBox="0 0 100 120" xmlns="http://www.w3.org/2000/svg">' +
      '      <defs>' +
      '        <linearGradient id="' + shellGradientId + '" x1="0%" y1="0%" x2="100%" y2="100%">' +
      '          <stop offset="0%" stop-color="#f4f9ff"/>' +
      '          <stop offset="42%" stop-color="#bfd2f8"/>' +
      '          <stop offset="100%" stop-color="#6f89c4"/>' +
      '        </linearGradient>' +
      '        <linearGradient id="' + shellStrokeGradientId + '" x1="0%" y1="0%" x2="100%" y2="100%">' +
      '          <stop offset="0%" stop-color="#e8f4ff"/>' +
      '          <stop offset="55%" stop-color="var(--ah-gxw-neon-blue)"/>' +
      '          <stop offset="100%" stop-color="var(--ah-gxw-neon-purple)"/>' +
      '        </linearGradient>' +
      '        <linearGradient id="' + canopyGradientId + '" x1="0%" y1="0%" x2="100%" y2="100%">' +
      '          <stop offset="0%" stop-color="#ffffff" stop-opacity=".92"/>' +
      '          <stop offset="35%" stop-color="#dff3ff" stop-opacity=".84"/>' +
      '          <stop offset="100%" stop-color="#a9bff5" stop-opacity=".72"/>' +
      '        </linearGradient>' +
      '        <linearGradient id="' + coreGradientId + '" x1="0%" y1="0%" x2="100%" y2="100%">' +
      '          <stop offset="0%" stop-color="var(--ah-gxw-neon-blue)"/>' +
      '          <stop offset="56%" stop-color="var(--ah-gxw-neon-purple)"/>' +
      '          <stop offset="100%" stop-color="var(--ah-gxw-neon-pink)"/>' +
      '        </linearGradient>' +
      '        <linearGradient id="' + flameGradientId + '" x1="50%" y1="0%" x2="50%" y2="100%">' +
      '          <stop offset="0%" stop-color="#ffffff"/>' +
      '          <stop offset="45%" stop-color="var(--ah-gxw-neon-blue)"/>' +
      '          <stop offset="100%" stop-color="var(--ah-gxw-neon-purple)"/>' +
      '        </linearGradient>' +
      '      </defs>' +
      '      <path d="M50 6 L71 39 L68 78 L50 97 L32 78 L29 39 Z" fill="none" stroke="rgba(8,24,48,0.5)" stroke-width="2.4" stroke-linejoin="round"/>' +
      '      <path d="M50 6 L71 39 L68 78 L50 97 L32 78 L29 39 Z" fill="url(#' + shellGradientId + ')" fill-opacity=".95" stroke="url(#' + shellStrokeGradientId + ')" stroke-width="2.2" stroke-linejoin="round"/>' +
      '      <path d="M50 8 L66 40 L64 74 L50 88 L36 74 L34 40 Z" fill="none" stroke="rgba(255,255,255,0.28)" stroke-width=".9" stroke-linejoin="round"/>' +
      '      <path d="M50 14 L62 39 L61 69 L50 83 L39 69 L38 39 Z" fill="rgba(8,22,42,0.44)" stroke="rgba(255,255,255,0.24)" stroke-width=".95" stroke-linejoin="round"/>' +
      '      <path d="M41 30 L59 30 L56 45 L44 45 Z" fill="url(#' + canopyGradientId + ')" stroke="rgba(255,255,255,0.42)" stroke-width=".8"/>' +
      '      <path d="M44 32 L56 32" stroke="rgba(255,255,255,0.55)" stroke-width=".8" stroke-linecap="round"/>' +
      '      <path class="ah-gxw-core-pulse" d="M50 25 L59 45 L58 69 L50 84 L42 69 L41 45 Z" fill="url(#' + coreGradientId + ')"/>' +
      '      <path d="M50 27 L56 46 L55 66 L50 76 L45 66 L44 46 Z" fill="rgba(255,255,255,0.15)"/>' +
      '      <path d="M30 58 C24 66 18 73 12 81 L29 74 Z" fill="rgba(18,36,62,0.58)" stroke="url(#' + shellStrokeGradientId + ')" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>' +
      '      <path d="M70 58 C76 66 82 73 88 81 L71 74 Z" fill="rgba(18,36,62,0.58)" stroke="url(#' + shellStrokeGradientId + ')" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>' +
      '      <path class="ah-gxw-flame" d="M41 96 L50 113 L59 96" fill="url(#' + flameGradientId + ')"/>' +
      '      <path class="ah-gxw-flame-core" d="M46 95 L50 106 L54 95" fill="#ffffff"/>' +
      '      <line x1="35" y1="45" x2="65" y2="45" stroke="var(--ah-gxw-neon-blue)" stroke-width="1" stroke-opacity=".34"/>' +
      '      <line x1="35" y1="60" x2="65" y2="60" stroke="var(--ah-gxw-neon-purple)" stroke-width="1" stroke-opacity=".36"/>' +
      '      <line x1="50" y1="5" x2="50" y2="-2" stroke="var(--ah-gxw-neon-blue)" stroke-width="1" stroke-opacity=".8"/>' +
      '      <circle cx="50" cy="-2" r="1.3" fill="var(--ah-gxw-neon-blue)"/>' +
      '    </svg>' +
      '  </span>' +
      '  <span class="ah-gxw-particles">' + particleMarkup + '</span>' +
      '  <span class="ah-gxw-scan"></span>' +
      '</span>'
    );
  }

  function rocketIcon() {
    return (
      '<svg class="ah-gxw-fab-svg icon-rocket" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '  <defs>' +
      '    <linearGradient id="ah-gxw-rocket-gradient" x1="0%" y1="0%" x2="100%" y2="100%">' +
      '      <stop offset="0%" stop-color="rgba(255,255,255,0.98)"/>' +
      '      <stop offset="56%" stop-color="rgba(255,255,255,0.78)"/>' +
      '      <stop offset="100%" stop-color="rgba(255,255,255,0.44)"/>' +
      '    </linearGradient>' +
      '  </defs>' +
      '  <path stroke="url(#ah-gxw-rocket-gradient)" d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.71-2.13.09-2.91a2.18 2.18 0 0 0-3.09-.09z"/>' +
      '  <path stroke="url(#ah-gxw-rocket-gradient)" d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/>' +
      '  <path stroke="url(#ah-gxw-rocket-gradient)" d="M9 12H4s.55-3.03 2-5c1.62-2.2 5-4 5-4"/>' +
      '  <path stroke="url(#ah-gxw-rocket-gradient)" d="M12 15v5s3.03-.55 5-2c2.2-1.62 4-5 4-5"/>' +
      '</svg>'
    );
  }


  function normalizeUrl(input) {
    var value = asText(input);
    if (!value) {
      return '';
    }
    if (/^https?:\/\//i.test(value)) {
      return value;
    }
    if (value.startsWith('/')) {
      return value;
    }
    return '';
  }

  function escapeHtml(input) {
    return asText(input)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function asText(input) {
    return input == null ? '' : String(input).trim();
  }

  function buildStatusBubbleHtml(statusLabel, healthy) {
    var label = asText(statusLabel);
    if (!label) {
      if (!healthy) {
        label = '链路异常';
      } else {
        label = '恒星已链接';
      }
    }
    return (
      '<span class="ah-gxw-status-line">' +
      '  <span class="ah-gxw-status-heartbeat" aria-hidden="true">' +
      '    <svg viewBox="0 0 26 12" xmlns="http://www.w3.org/2000/svg">' +
      '      <path d="M1 6h4l2.2-3 2.7 7 2.8-8 2.3 4h8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>' +
      '    </svg>' +
      '  </span>' +
      '  <span class="ah-gxw-status-value">' + escapeHtml(label) + '</span>' +
      '</span>'
    );
  }

  function normalizeRealtimeBroadcast(input) {
    var raw = input && typeof input === 'object' ? input : {};
    return {
      enabled: typeof raw.enabled === 'undefined' ? true : Boolean(raw.enabled)
    };
  }

  function shouldAcceptMascotBubble(input, config) {
    var settings = normalizeRealtimeBroadcast(config);
    if (!settings.enabled) {
      return false;
    }
    return true;
  }

  function isDuplicateMascotBubble(bubble, recentIds) {
    if (!bubble || !bubble.id) {
      return false;
    }
    if (!recentIds || typeof recentIds !== 'object') {
      return false;
    }
    var now = Date.now();
    Object.keys(recentIds).forEach(function (id) {
      if (now - recentIds[id] > MASCOT_BUBBLE_ID_TTL_MS) {
        delete recentIds[id];
      }
    });
    return Boolean(recentIds[bubble.id]);
  }

  function rememberMascotBubbleId(bubble, recentIds) {
    if (!bubble || !bubble.id) {
      return;
    }
    if (!recentIds || typeof recentIds !== 'object') {
      return;
    }
    recentIds[bubble.id] = Date.now();
  }

  function normalizeMascotRealtimeEvent(input) {
    if (!input || typeof input !== 'object') {
      return null;
    }
    if (input.type === 'mascot_article_card') {
      return normalizeMascotArticleCard(input);
    }
    return normalizeMascotBubble(input);
  }

  function normalizeMascotBubble(input) {
    if (!input || typeof input !== 'object') {
      return null;
    }
    var title = asText(input.title);
    var message = asText(input.message);
    if (!title && !message) {
      return null;
    }
    return {
      id: asText(input.id),
      event: asText(input.event),
      level: asText(input.level) || 'info',
      title: title || message,
      message: message,
      siteName: asText(input.siteName),
      nodeName: asText(input.nodeName),
      nodeAvatar: normalizeUrl(input.nodeAvatar),
      time: asText(input.time),
      visibility: asText(input.visibility)
    };
  }

  function normalizeMascotArticleCard(input) {
    if (!input || typeof input !== 'object') {
      return null;
    }
    var article = input.article && typeof input.article === 'object' ? input.article : {};
    var url = normalizeUrl(article.url);
    var title = asText(article.title);
    if (!url || !title) {
      return null;
    }
    var nodeName = asText(article.nodeName) || asText(input.nodeName) || asText(input.siteName) || '星链节点';
    return {
      type: 'mascot_article_card',
      id: asText(input.id) || asText(article.id) || url,
      event: asText(input.event),
      level: asText(input.level) || 'info',
      title: asText(input.title) || title,
      message: asText(input.message),
      siteName: asText(input.siteName),
      nodeName: nodeName,
      nodeAvatar: normalizeUrl(input.nodeAvatar) || normalizeUrl(article.nodeAvatar),
      time: asText(input.time),
      visibility: asText(input.visibility),
      reason: asText(input.reason),
      article: {
        id: asText(article.id),
        title: title,
        url: url,
        summary: asText(article.summary),
        publishedAt: asText(article.publishedAt),
        nodeName: nodeName,
        nodeAvatar: normalizeUrl(article.nodeAvatar) || normalizeUrl(input.nodeAvatar),
        nodeUrl: normalizeUrl(article.nodeUrl)
      }
    };
  }

  function buildMascotRealtimeHtml(event) {
    if (event && event.type === 'mascot_article_card') {
      return buildMascotArticleCardHtml(event);
    }
    return buildMascotBubbleHtml(event);
  }

  function buildMascotBubbleHtml(event) {
    var title = asText(event && event.title) || '主星实时播报';
    var message = asText(event && event.message);
    var nodeName = asText(event && event.nodeName);
    var siteName = asText(event && event.siteName);
    var meta = nodeName || siteName;
    return (
      '<span class="ah-gxw-status-line ah-gxw-mascot-bubble-title">' +
      '  <span class="ah-gxw-status-heartbeat" aria-hidden="true">' +
      '    <svg viewBox="0 0 26 12" xmlns="http://www.w3.org/2000/svg">' +
      '      <path d="M1 6h4l2.2-3 2.7 7 2.8-8 2.3 4h8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>' +
      '    </svg>' +
      '  </span>' +
      '  <span class="ah-gxw-status-value">' + escapeHtml(title) + '</span>' +
      '</span>' +
      (message ? '<span class="ah-gxw-mascot-bubble-message">' + escapeHtml(message) + '</span>' : '') +
      (meta ? '<span class="ah-gxw-mascot-bubble-meta">' + escapeHtml(meta) + '</span>' : '')
    );
  }

  function truncateBubbleSummary(text, max) {
    var raw = asText(text);
    if (!raw) {
      return '';
    }
    var limit = Number(max) > 0 ? Number(max) : 16;
    var chars = Array.from(raw);
    if (chars.length <= limit) {
      return raw;
    }
    return chars.slice(0, limit).join('') + '…';
  }

  function buildMascotArticleCardHtml(event) {
    var article = event && event.article ? event.article : {};
    var title = asText(article.title);
    var url = normalizeUrl(article.url);
    if (!title || !url) {
      return buildMascotBubbleHtml(event);
    }
    var nodeName = asText(article.nodeName) || asText(event.nodeName) || '星链节点';
    var avatar = normalizeUrl(article.nodeAvatar) || normalizeUrl(event.nodeAvatar);
    // Summary only takes article.summary; do NOT fall back to event.message (which is a hub decorative line).
    var summary = truncateBubbleSummary(article.summary, 16);
    var time = formatArticleTime(article.publishedAt || event.time);
    var avatarMarkup = avatar
      ? '<img src="' + escapeHtml(avatar) + '" alt="" loading="lazy" referrerpolicy="no-referrer">'
      : '<span class="ah-gxw-article-avatar-fallback">' + escapeHtml(nodeName.slice(0, 1).toUpperCase()) + '</span>';
    var sourceLabel = '来源 ' + nodeName + ' 星球';
    return (
      '<a class="ah-gxw-article-card" href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer">' +
      '  <span class="ah-gxw-article-source">' +
      '    <span class="ah-gxw-article-avatar">' + avatarMarkup + '</span>' +
      '    <span class="ah-gxw-article-node" title="' + escapeHtml(nodeName) + '">' + escapeHtml(nodeName) + '</span>' +
      (time ? '<span class="ah-gxw-article-time" title="' + escapeHtml(time) + '">' + escapeHtml(time) + '</span>' : '') +
      '  </span>' +
      '  <span class="ah-gxw-article-title">' + escapeHtml(title) + '</span>' +
      (summary ? '<span class="ah-gxw-article-summary">' + escapeHtml(summary) + '</span>' : '') +
      '  <span class="ah-gxw-article-footer">' +
      '    <span class="ah-gxw-article-source-tag" title="' + escapeHtml(sourceLabel) + '">' + escapeHtml(sourceLabel) + '</span>' +
      '    <span class="ah-gxw-article-link" aria-hidden="true">' +
      '      <svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M6.5 2h-3A1.5 1.5 0 0 0 2 3.5v9A1.5 1.5 0 0 0 3.5 14h9a1.5 1.5 0 0 0 1.5-1.5v-3M9 2h5v5M14 2 7.5 8.5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
      '      <span class="ah-gxw-article-link-text">点击阅读</span>' +
      '    </span>' +
      '  </span>' +
      '</a>'
    );
  }

  function formatArticleTime(input) {
    var raw = asText(input);
    if (!raw) {
      return '';
    }
    var date = new Date(raw);
    if (!Number.isFinite(date.getTime())) {
      return raw.length > 16 ? raw.slice(0, 16) : raw;
    }
    // Reject Go zero time ("0001-01-01T00:00:00Z") and any pre-1970 sentinel value.
    if (date.getFullYear() < 1970) {
      return '';
    }
    var month = String(date.getMonth() + 1).padStart(2, '0');
    var day = String(date.getDate()).padStart(2, '0');
    var hour = String(date.getHours()).padStart(2, '0');
    var minute = String(date.getMinutes()).padStart(2, '0');
    return month + '/' + day + ' ' + hour + ':' + minute;
  }
})();

