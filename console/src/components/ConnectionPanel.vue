<script lang="ts" setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from "vue";
import { api } from "../api/client";
import type { ConnectionView, CredentialsView } from "../composables/useStatus";

interface ReportStatusView {
  success: boolean;
  status: number;
  message: string;
  trigger: string;
  pushedAt: string;
  updatedAt: string;
}

const props = defineProps<{
  connection: ConnectionView;
  credentials: CredentialsView;
  registered: boolean;
  hubBaseUrl?: string;
  saveSignal?: number;
}>();

const emit = defineEmits<{ (e: "refresh"): void }>();

// 本地可编辑表单（与 props.connection 同构）。
const form = reactive<ConnectionView>({
  siteName: props.connection.siteName,
  siteUrl: props.connection.siteUrl,
  siteDescription: props.connection.siteDescription,
  siteRssUrl: props.connection.siteRssUrl,
  siteAvatarUrl: props.connection.siteAvatarUrl,
  contactEmail: props.connection.contactEmail,
  siteNodeName: props.connection.siteNodeName,
  siteNodeAvatar: props.connection.siteNodeAvatar
});

const hubBaseUrl = computed(() => props.hubBaseUrl || window.WP_ASTRAHUB_BOOTSTRAP?.hubBaseUrl || "https://astra.aobp.cn");
const hasCredentials = computed(() => props.registered && Boolean(props.credentials.siteId));
const registerActionLabel = computed(() => (hasCredentials.value ? "更新信息" : "接入星链"));

const registering = ref(false);
const restoring = ref(false);
const sendingCode = ref(false);
const requestingInvitation = ref(false);
const revealApiKey = ref(false);
const busy = computed(() => registering.value || restoring.value || sendingCode.value || requestingInvitation.value);

const toast = reactive({ kind: "", text: "" });
function showToast(kind: "ok" | "err", text: string) {
  toast.kind = kind;
  toast.text = text;
  window.setTimeout(() => { if (toast.text === text) toast.text = ""; }, 4000);
}

const maskedApiKey = computed(() => {
  const raw = revealedApiKey.value;
  if (revealApiKey.value && raw) return raw;
  return props.credentials.apiKeyMask || (props.credentials.hasApiKey ? "********" : "-");
});

// 接入密钥明文（点「显示」时按需从服务端拉取，对齐 Halo 可显示/复制真实密钥）。
const revealedApiKey = ref("");
async function ensureRevealedApiKey(): Promise<string> {
  if (revealedApiKey.value) return revealedApiKey.value;
  if (!props.credentials.hasApiKey) return "";
  const resp = await api.get<{ apiKey?: string }>("/credentials/reveal");
  revealedApiKey.value = String((resp.data as { apiKey?: string })?.apiKey || "");
  return revealedApiKey.value;
}

async function onToggleRevealApiKey() {
  if (!props.credentials.hasApiKey) return;
  if (!revealApiKey.value) {
    try {
      await ensureRevealedApiKey();
      revealApiKey.value = true;
    } catch (e) {
      showToast("err", e instanceof Error ? e.message : "读取密钥失败");
    }
  } else {
    revealApiKey.value = false;
  }
}

// props.connection 异步到达或刷新后，回填本地表单（修复"注册信息没回填输入框"）。
watch(
  () => props.connection,
  (next) => {
    if (!next) return;
    form.siteName = next.siteName;
    form.siteUrl = next.siteUrl;
    form.siteDescription = next.siteDescription;
    form.siteRssUrl = next.siteRssUrl;
    form.siteAvatarUrl = next.siteAvatarUrl;
    form.contactEmail = next.contactEmail;
    form.siteNodeName = next.siteNodeName;
    form.siteNodeAvatar = next.siteNodeAvatar;
  },
  { deep: true }
);

// —— 最近同步状态（对齐 Halo：结构化展示返回状态/提示/完成时间 + 重新同步）——
const syncing = ref(false);
const lastSyncResult = ref<ReportStatusView | null>(null);

function showStatusValue(value?: string | number | null) {
  if (value === null || value === undefined) return "-";
  const text = String(value).trim();
  return text || "-";
}

async function refreshReportStatus(silent = false) {
  if (!hasCredentials.value) {
    lastSyncResult.value = null;
    return;
  }
  try {
    const resp = await api.get<{ status?: ReportStatusView }>("/report-status");
    const status = (resp.data as { status?: ReportStatusView })?.status;
    lastSyncResult.value = status || null;
  } catch (e) {
    if (!silent) showToast("err", e instanceof Error ? e.message : "读取同步状态失败");
  }
}

async function onSyncNow() {
  if (busy.value || syncing.value || !hasCredentials.value) return;
  syncing.value = true;
  try {
    const resp = await api.post("/push-graph", { reason: "manual" });
    await refreshReportStatus(true);
    showToast(resp.success ? "ok" : "err", resp.success ? "同步完成" : (resp.message || "同步失败"));
  } catch (e) {
    await refreshReportStatus(true);
    showToast("err", e instanceof Error ? e.message : "同步失败");
  } finally {
    syncing.value = false;
  }
}

function connectionPayload() {
  return {
    siteName: form.siteName,
    siteUrl: form.siteUrl,
    siteDescription: form.siteDescription,
    siteRssUrl: form.siteRssUrl,
    siteAvatarUrl: form.siteAvatarUrl,
    contactEmail: form.contactEmail,
    siteNodeName: form.siteNodeName,
    siteNodeAvatar: form.siteNodeAvatar
  } as Record<string, unknown>;
}

async function saveConnection() {
  try {
    const resp = await api.post("/connection", connectionPayload());
    showToast(resp.success ? "ok" : "err", resp.message || (resp.success ? "已保存" : "保存失败"));
  } catch (e) {
    showToast("err", e instanceof Error ? e.message : "保存失败");
  }
}

// —— 接入星链对话框（申请签发码 + 带码注册）——
const joinDialogVisible = ref(false);
const joinCode = ref("");
const joinExpiresAt = ref("");
const joinConsentChecked = ref(false);

function openJoinDialog() {
  joinDialogVisible.value = true;
  joinCode.value = "";
  joinExpiresAt.value = "";
  joinConsentChecked.value = false;
}
function closeJoinDialog() {
  if (requestingInvitation.value || registering.value) return;
  joinDialogVisible.value = false;
}

async function onRegisterSite() {
  if (busy.value) return;
  await saveConnection();
  if (hasCredentials.value) {
    // 已接入 → 直接更新（带令牌的直接注册）
    registering.value = true;
    try {
      const resp = await api.post("/register", { ...connectionPayload() });
      showToast(resp.success ? "ok" : "err", resp.message || (resp.success ? "接入信息已更新" : "更新失败"));
      if (resp.success) emit("refresh");
    } catch (e) {
      showToast("err", e instanceof Error ? e.message : "更新失败");
    } finally {
      registering.value = false;
    }
    return;
  }
  openJoinDialog();
}

async function onRequestInvitationCode() {
  if (requestingInvitation.value) return;
  if (!joinConsentChecked.value) { showToast("err", "请先阅读并勾选《星链接入与数据同步说明》"); return; }
  if (!form.contactEmail.trim()) { showToast("err", "请先填写联系邮箱"); return; }
  requestingInvitation.value = true;
  try {
    const resp = await api.post("/invitation/request", { contactEmail: form.contactEmail, siteUrl: form.siteUrl });
    if (resp.success) {
      joinExpiresAt.value = String((resp.data as Record<string, unknown>)?.expiresAt || "");
      showToast("ok", "签发码已发送，请查收邮箱");
    } else {
      showToast("err", resp.message || "申请签发码失败");
    }
  } catch (e) {
    showToast("err", e instanceof Error ? e.message : "申请签发码失败");
  } finally {
    requestingInvitation.value = false;
  }
}

async function onConfirmJoinPlanet() {
  if (registering.value) return;
  if (!joinConsentChecked.value) { showToast("err", "请先勾选同意说明"); return; }
  if (!joinCode.value.trim()) { showToast("err", "请输入邮箱收到的签发码"); return; }
  registering.value = true;
  try {
    const resp = await api.post("/invitation/register", { ...connectionPayload(), invitationCode: joinCode.value.trim() });
    if (resp.success) {
      joinDialogVisible.value = false;
      showToast("ok", "接入星链成功");
      emit("refresh");
    } else {
      showToast("err", resp.message || "接入星链失败");
    }
  } catch (e) {
    showToast("err", e instanceof Error ? e.message : "接入星链失败");
  } finally {
    registering.value = false;
  }
}

// —— 重新登舱对话框 + OTP ——
const boardingDialogVisible = ref(false);
const boardingEmail = ref("");
const boardingExpiresAt = ref("");
const OTP_LENGTH = 6;
const otpDigits = ref<string[]>(Array(OTP_LENGTH).fill(""));
const otpRefs = ref<(HTMLInputElement | null)[]>([]);
const boardingCode = computed(() => otpDigits.value.join(""));

function setOtpRef(el: unknown, idx: number) {
  otpRefs.value[idx] = el as HTMLInputElement | null;
}
function onOtpInput(idx: number) {
  const digit = (otpDigits.value[idx] || "").replace(/\D/g, "").slice(-1);
  otpDigits.value[idx] = digit;
  if (digit && idx < OTP_LENGTH - 1) nextTick(() => otpRefs.value[idx + 1]?.focus());
}
function onOtpKeydown(e: KeyboardEvent, idx: number) {
  if (e.key === "Backspace" && !otpDigits.value[idx] && idx > 0) nextTick(() => otpRefs.value[idx - 1]?.focus());
}
function onOtpPaste(e: ClipboardEvent) {
  e.preventDefault();
  const text = (e.clipboardData?.getData("text") || "").replace(/\D/g, "").slice(0, OTP_LENGTH);
  if (!text) return;
  for (let i = 0; i < OTP_LENGTH; i++) otpDigits.value[i] = text[i] || "";
  nextTick(() => otpRefs.value[Math.min(text.length, OTP_LENGTH - 1)]?.focus());
}

function openBoardingDialog() {
  boardingDialogVisible.value = true;
  otpDigits.value = Array(OTP_LENGTH).fill("");
  boardingExpiresAt.value = "";
  boardingEmail.value = form.contactEmail || "";
}
function closeBoardingDialog() {
  if (sendingCode.value || restoring.value) return;
  boardingDialogVisible.value = false;
}
async function onSendBoardingCode() {
  if (sendingCode.value) return;
  if (!boardingEmail.value.trim()) { showToast("err", "请输入邮箱"); return; }
  sendingCode.value = true;
  try {
    const resp = await api.post("/boarding/send-code", { contactEmail: boardingEmail.value.trim() });
    if (resp.success) {
      boardingExpiresAt.value = String((resp.data as Record<string, unknown>)?.expiresAt || "");
      showToast("ok", "验证码已发送，请检查邮箱");
    } else {
      showToast("err", resp.message || "发送验证码失败");
    }
  } catch (e) {
    showToast("err", e instanceof Error ? e.message : "发送验证码失败");
  } finally {
    sendingCode.value = false;
  }
}
async function onRestoreByBoardingCode() {
  if (restoring.value) return;
  if (!boardingEmail.value.trim()) { showToast("err", "请输入邮箱"); return; }
  if (!boardingCode.value.trim()) { showToast("err", "请输入验证码"); return; }
  restoring.value = true;
  try {
    const resp = await api.post("/boarding/restore", { contactEmail: boardingEmail.value.trim(), code: boardingCode.value.trim() });
    if (resp.success) {
      boardingDialogVisible.value = false;
      showToast("ok", "重新登舱成功");
      emit("refresh");
    } else {
      showToast("err", resp.message || "登舱恢复失败");
    }
  } catch (e) {
    showToast("err", e instanceof Error ? e.message : "登舱恢复失败");
  } finally {
    restoring.value = false;
  }
}

async function onCopyApiKey() {
  if (!props.credentials.hasApiKey) return;
  try {
    const key = await ensureRevealedApiKey();
    if (!key) {
      showToast("err", "暂无可复制的密钥");
      return;
    }
    await navigator.clipboard.writeText(key);
    showToast("ok", "接入密钥已复制");
  } catch {
    showToast("err", "复制失败，请手动复制");
  }
}

onMounted(() => {
  void refreshReportStatus(true);
  void loadWidgetSettings();
});

// —— 前台显示 / 主星实时播报 开关（对齐 Halo settings.widget.enabled / realtimeBroadcast.enabled）——
const widgetEnabled = ref(true);
const realtimeEnabled = ref(true);
let widgetSettingsLoaded = false;

async function loadWidgetSettings() {
  try {
    const resp = await api.get<{ enabled?: boolean; realtimeEnabled?: boolean }>("/widget-settings");
    const d = (resp.data || {}) as { enabled?: boolean; realtimeEnabled?: boolean };
    widgetEnabled.value = d.enabled !== false;
    realtimeEnabled.value = d.realtimeEnabled !== false;
  } catch {
    /* 读取失败用默认值 */
  } finally {
    widgetSettingsLoaded = true;
  }
}

async function saveWidgetSettings() {
  if (!widgetSettingsLoaded) return;
  try {
    await api.post("/widget-settings", {
      enabled: widgetEnabled.value,
      realtimeEnabled: realtimeEnabled.value
    });
  } catch (e) {
    showToast("err", e instanceof Error ? e.message : "保存挂件设置失败");
  }
}

watch([widgetEnabled, realtimeEnabled], () => {
  void saveWidgetSettings();
});

// 顶栏「保存设置」触发：保存当前连接表单。
watch(
  () => props.saveSignal,
  (next, prev) => {
    if (typeof next !== "number" || next === prev) return;
    void saveConnection();
  }
);

watch(
  () => [props.credentials.siteId, props.credentials.hasApiKey].join("|"),
  () => {
    revealApiKey.value = false;
    revealedApiKey.value = "";
    void refreshReportStatus(true);
  }
);
</script>

<template>
  <div class="stats-wrapper">
    <div v-if="toast.text" class="sp-toast" :class="toast.kind === 'ok' ? 'sp-toast-ok' : 'sp-toast-err'">
      {{ toast.text }}
    </div>

    <!-- 接入信息 -->
    <div class="sp-card sp-card--blue">
      <div class="sp-card-title">
        <div class="sp-card-title-main">
          <span class="sp-dot" style="background:#3b82f6"></span>
          <span>接入信息</span>
          <span class="sp-title-separator">·</span>
          <span class="sp-status-pill" :class="hasCredentials ? 'ok' : 'pending'">{{ hasCredentials ? "已接入" : "未接入" }}</span>
        </div>
      </div>
      <div class="sp-card-body">
        <div class="sp-form-item">
          <label class="sp-form-label">Hub 基础地址</label>
          <input :value="hubBaseUrl" class="sp-input" readonly />
        </div>
        <div class="sp-form-grid-2">
          <div class="sp-form-item"><label class="sp-form-label">站点名称</label><input v-model="form.siteName" class="sp-input" placeholder="站点显示名称" /></div>
          <div class="sp-form-item"><label class="sp-form-label">站点 URL</label><input v-model="form.siteUrl" class="sp-input" placeholder="https://your-site.com" /></div>
        </div>
        <div class="sp-form-grid-2">
          <div class="sp-form-item"><label class="sp-form-label">联系邮箱</label><input v-model="form.contactEmail" class="sp-input" placeholder="admin@example.com" :readonly="hasCredentials" /></div>
          <div class="sp-form-item"><label class="sp-form-label">星链节点名</label><input v-model="form.siteNodeName" class="sp-input" placeholder="例如：可爱的星链" /></div>
        </div>
        <div class="sp-form-item">
          <label class="sp-form-label">站点简介</label>
          <textarea v-model="form.siteDescription" class="sp-textarea" placeholder="用于友链邀请、站点展示等场景，建议填写一句清晰的站点介绍"></textarea>
        </div>
        <div class="sp-form-grid-2">
          <div class="sp-form-item"><label class="sp-form-label">星链头像链接</label><input v-model="form.siteNodeAvatar" class="sp-input" placeholder="https://example.com/avatar.png" /></div>
          <div class="sp-form-item"><label class="sp-form-label">站点 RSS</label><input v-model="form.siteRssUrl" class="sp-input" placeholder="https://your-site.com/rss.xml" /></div>
        </div>
      </div>
    </div>

    <!-- 注册与凭据 -->
    <div class="sp-card sp-card--amber">
      <div class="sp-card-title">
        <div class="sp-card-title-main">
          <span class="sp-dot" style="background:#f59e0b"></span>
          <span>注册与凭据</span>
        </div>
        <div class="sp-title-actions">
          <button class="sp-header-btn sp-header-btn-primary" :disabled="busy" @click="onRegisterSite">{{ registering ? "处理中..." : registerActionLabel }}</button>
          <button class="sp-header-btn" :disabled="busy" @click="openBoardingDialog">重新登舱</button>
        </div>
      </div>
      <div class="sp-card-body">
        <div class="sp-form-grid-2">
          <div class="sp-form-item"><label class="sp-form-label">站点编号</label><input :value="props.credentials.siteId || '-'" class="sp-input" readonly /></div>
          <div class="sp-form-item"><label class="sp-form-label">创建时间</label><input :value="props.credentials.createdAt || '-'" class="sp-input" readonly /></div>
        </div>
        <div class="sp-form-item">
          <label class="sp-form-label">接入密钥</label>
          <div class="sp-input-row">
            <input :value="maskedApiKey" class="sp-input" readonly />
            <button type="button" class="sp-input-btn" @click="onToggleRevealApiKey">{{ revealApiKey ? "隐藏" : "显示" }}</button>
            <button type="button" class="sp-input-btn" @click="onCopyApiKey">复制</button>
          </div>
        </div>
      </div>
    </div>

    <!-- 最近同步 -->
    <div class="sp-card sp-card--cyan">
      <div class="sp-card-title">
        <div class="sp-card-title-main">
          <span class="sp-dot" style="background:#06b6d4"></span>
          <span>最近同步</span>
        </div>
        <div class="sp-title-actions">
          <button class="sp-header-btn" :disabled="busy || !hasCredentials || syncing" @click="onSyncNow">{{ syncing ? "同步中..." : "重新同步" }}</button>
        </div>
      </div>
      <div class="sp-card-body">
        <div v-if="lastSyncResult" class="sp-protocol-grid">
          <div class="sp-protocol-item">
            <span class="sp-protocol-label">返回状态</span>
            <span class="sp-protocol-value">{{ lastSyncResult.status }}</span>
          </div>
          <div class="sp-protocol-item">
            <span class="sp-protocol-label">提示</span>
            <span class="sp-protocol-value">{{ showStatusValue(lastSyncResult.message) }}</span>
          </div>
          <div class="sp-protocol-item">
            <span class="sp-protocol-label">完成时间</span>
            <span class="sp-protocol-value">{{ showStatusValue(lastSyncResult.pushedAt || lastSyncResult.updatedAt) }}</span>
          </div>
        </div>
        <div v-else class="sp-inline-note">这里显示服务端记录的最近一次同步状态。</div>
      </div>
    </div>

    <!-- 前台显示 -->
    <div class="sp-card sp-card--violet">
      <div class="sp-card-title">
        <span class="sp-dot" style="background:#0ea5e9"></span>
        前台显示
      </div>
      <div class="sp-card-body">
        <div class="sp-toggle-item sp-toggle-last">
          <div class="sp-toggle-info">
            <span class="sp-toggle-label">显示前台挂件</span>
            <span class="sp-toggle-desc">关闭后将不再显示前台挂件。</span>
          </div>
          <label class="sp-toggle"><input type="checkbox" v-model="widgetEnabled" /><span class="sp-toggle-slider"></span></label>
        </div>
      </div>
    </div>

    <!-- 主星实时播报 -->
    <div class="sp-card sp-card--cyan">
      <div class="sp-card-title">
        <span class="sp-dot" style="background:#06b6d4"></span>
        主星实时播报
      </div>
      <div class="sp-card-body sp-card-body--stack">
        <div class="sp-toggle-item">
          <div class="sp-toggle-info">
            <span class="sp-toggle-label">启用主星实时播报</span>
            <span class="sp-toggle-desc">开启后，主星推送的接入、同步、文章推荐等消息会通过前台吉祥物气泡展示。</span>
          </div>
          <label class="sp-toggle"><input type="checkbox" v-model="realtimeEnabled" /><span class="sp-toggle-slider"></span></label>
        </div>
      </div>
    </div>

    <!-- 重新登舱弹窗 -->
    <div v-if="boardingDialogVisible" class="sp-modal-mask" @click.self="closeBoardingDialog">
      <div class="sp-modal">
        <div class="sp-modal-title">重新登舱</div>
        <div class="sp-modal-sub">通过联系邮箱恢复接入信息，并自动重新同步。</div>
        <div class="sp-form-item">
          <label class="sp-form-label">邮箱</label>
          <input v-model="boardingEmail" class="sp-input" placeholder="admin@example.com" :disabled="sendingCode || restoring" />
        </div>
        <div class="sp-form-item">
          <label class="sp-form-label">验证码</label>
          <div class="sp-otp-row">
            <div class="sp-otp-boxes">
              <input
                v-for="(_, idx) in OTP_LENGTH"
                :key="idx"
                :ref="(el) => setOtpRef(el, idx)"
                v-model="otpDigits[idx]"
                class="sp-otp-cell"
                type="text"
                inputmode="numeric"
                maxlength="1"
                :disabled="restoring"
                @input="onOtpInput(idx)"
                @keydown="onOtpKeydown($event, idx)"
                @paste="onOtpPaste"
              />
            </div>
            <button type="button" class="sp-input-btn" :disabled="sendingCode || restoring" @click="onSendBoardingCode">{{ sendingCode ? "发送中" : "发送验证码" }}</button>
          </div>
          <div v-if="boardingExpiresAt" class="sp-inline-note">有效期至：{{ boardingExpiresAt }}</div>
        </div>
        <div class="sp-actions sp-actions-end">
          <button class="sp-header-btn" :disabled="sendingCode || restoring" @click="closeBoardingDialog">取消</button>
          <button class="sp-header-btn sp-header-btn-primary" :disabled="restoring" @click="onRestoreByBoardingCode">{{ restoring ? "恢复中..." : "恢复并同步" }}</button>
        </div>
      </div>
    </div>

    <!-- 接入星链弹窗 -->
    <div v-if="joinDialogVisible" class="sp-modal-mask" @click.self="closeJoinDialog">
      <div class="sp-modal">
        <div class="sp-modal-title">接入星链</div>
        <div class="sp-modal-sub">系统将把签发码发送到你的联系邮箱 {{ form.contactEmail || "—" }}，收到后填入下方完成接入。</div>
        <div class="sp-form-item sp-consent-block">
          <details class="sp-consent-details">
            <summary class="sp-consent-summary">查看《星链接入与数据同步说明》</summary>
            <div class="sp-consent-text">
              <div class="sp-consent-section">
                <p class="sp-consent-section-title">一、接入后您将获得的能力</p>
                <p>接入主星「{{ hubBaseUrl }}」后，您的站点将加入"博客星球"创作者网络，与全网独立博主互联互通。</p>
                <ul>
                  <li><strong>扩大曝光</strong>：站点与博文将出现在主星首页的"探索"与"信号流"中</li>
                  <li><strong>拓宽圈子</strong>：自动加入主题星系，与同好建立连接</li>
                  <li><strong>互通友链</strong>：通过签发码邀请协议与其它接入站点一键建立友链</li>
                  <li><strong>聚合发现</strong>：友链关系会被纳入图谱可视化</li>
                </ul>
              </div>
              <div class="sp-consent-section">
                <p class="sp-consent-section-title">二、同步至主星的数据范围</p>
                <p><strong>站点公开内容</strong>：站点名称、URL、节点标识与头像、RSS、友链、博文公开元数据。<strong>联系邮箱</strong>用于身份识别与系统通知。</p>
              </div>
              <div class="sp-consent-section">
                <p class="sp-consent-section-title">三、隐私与边界声明</p>
                <p>上述数据均<strong>来源于本站点已对外发布、可被公众直接访问的公开内容</strong>，不涉及任何非公开信息。</p>
              </div>
            </div>
          </details>
          <label class="sp-consent-check">
            <input type="checkbox" v-model="joinConsentChecked" :disabled="registering || requestingInvitation" />
            <span>我已阅读并同意《星链接入与数据同步说明》，授权本插件按上述范围将我的站点公开数据与联系邮箱同步至主星「{{ hubBaseUrl }}」。</span>
          </label>
        </div>
        <div class="sp-form-item">
          <label class="sp-form-label">签发码</label>
          <div class="sp-input-row">
            <input v-model="joinCode" class="sp-input" placeholder="例如 bphub-A3F7B2C1" :disabled="registering" />
            <button type="button" class="sp-input-btn" :disabled="requestingInvitation || registering || !joinConsentChecked" @click="onRequestInvitationCode">{{ requestingInvitation ? "发送中" : "发送签发码" }}</button>
          </div>
          <div v-if="joinExpiresAt" class="sp-inline-note">有效期至：{{ joinExpiresAt }}</div>
          <div v-else class="sp-inline-note">一张签发码仅可注册一个站点，请妥善保管。</div>
        </div>
        <div class="sp-actions sp-actions-end">
          <button class="sp-header-btn" :disabled="requestingInvitation || registering" @click="closeJoinDialog">取消</button>
          <button class="sp-header-btn sp-header-btn-primary" :disabled="registering || !joinCode.trim() || !joinConsentChecked" @click="onConfirmJoinPlanet">{{ registering ? "接入中..." : "确认" }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stats-wrapper{padding:16px 20px;flex:1;overflow-y:auto;min-height:0;display:flex;flex-direction:column}
.sp-toast{position:sticky;top:0;z-index:30;margin-bottom:10px;padding:10px 14px;border-radius:10px;font-size:12px;font-weight:600}
.sp-toast-ok{background:#ecfdf5;color:#047857}
.sp-toast-err{background:#fef2f2;color:#b91c1c}
.sp-card{background:transparent;border:1px solid rgba(0,0,0,.05);border-radius:20px;padding:12px 14px;margin-bottom:10px;overflow:hidden;display:flex;flex-direction:column;flex-shrink:0}
.sp-card--blue{background:transparent;height:440px}
.sp-card--amber{background:transparent;height:200px}
.sp-card--cyan{background:transparent;height:140px}
.sp-card--violet{background:transparent}
.sp-card-title{display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:13px;font-weight:600;color:#1e293b;margin-bottom:10px;flex-shrink:0}
.sp-card-title-main{display:flex;align-items:center;gap:8px;min-width:0}
.sp-title-separator{color:#94a3b8;font-weight:600}
.sp-title-actions{display:flex;align-items:center;gap:14px;flex-shrink:0}
.sp-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.sp-card-body{padding:0;flex:1;min-height:0;overflow:hidden}
.sp-form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.sp-form-item{margin-bottom:8px}
.sp-form-item:last-child{margin-bottom:0}
.sp-form-label{display:block;font-size:12px;font-weight:500;color:#64748b;margin-bottom:4px}
.sp-input{width:100%;padding:6px 10px;border:1px solid rgba(203,213,225,.88);border-radius:8px;font-size:13px;background:rgba(255,255,255,.88);box-sizing:border-box;transition:border-color .15s,box-shadow .15s;color:#1e293b}
.sp-input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,.1)}
.sp-input::placeholder{color:#cbd5e1}
.sp-input[readonly]{background:#f8fafc;color:#334155}
.sp-textarea{width:100%;min-height:44px;padding:7px 10px;border:1px solid rgba(203,213,225,.88);border-radius:8px;font-size:13px;background:rgba(255,255,255,.88);box-sizing:border-box;transition:border-color .15s,box-shadow .15s;color:#1e293b;resize:vertical;line-height:1.45}
.sp-textarea:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,.1)}
.sp-textarea::placeholder{color:#cbd5e1}
.sp-input-row{display:flex;gap:10px;align-items:center}
.sp-input-row .sp-input{flex:1}
.sp-input-btn{display:inline-flex;align-items:center;outline:none;padding:5px 14px;border:2px dashed #64748b;border-radius:15px;background-color:#f1f5f9;color:#64748b;font-size:11px;font-weight:600;cursor:pointer;transition:transform .2s ease-out;box-shadow:0 0 0 3px #f1f5f9,1.5px 1.5px 3px 1px rgba(0,0,0,.15);white-space:nowrap}
.sp-input-btn:hover:not(:disabled){transform:translateY(-4px) translateX(-2px);box-shadow:0 0 0 3px #f1f5f9,2px 5px 0 0 currentColor}
.sp-input-btn:active:not(:disabled){transform:translateY(1px) translateX(1px);box-shadow:0 0 0 3px #f1f5f9,0 0 0 0 currentColor}
.sp-input-btn:disabled{opacity:.5;cursor:not-allowed}
.sp-otp-row{display:flex;gap:10px;align-items:center}
.sp-otp-boxes{display:flex;gap:6px}
.sp-otp-cell{width:36px;height:40px;border:1px solid rgba(203,213,225,.88);border-radius:8px;font-size:18px;font-weight:600;text-align:center;background:rgba(255,255,255,.88);color:#1e293b;transition:border-color .15s,box-shadow .15s;padding:0;box-sizing:border-box}
.sp-otp-cell:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,.15)}
.sp-otp-cell:disabled{opacity:.5;background:#f8fafc;cursor:not-allowed}
.sp-status-pill{display:inline-flex;align-items:center;height:20px;padding:0 8px;border-radius:999px;font-size:11px;font-weight:600}
.sp-status-pill.ok{background:#ecfdf5;color:#059669}
.sp-status-pill.pending{background:#eff6ff;color:#2563eb}
.sp-actions{display:flex;align-items:center;gap:14px;margin-top:14px}
.sp-actions-end{justify-content:flex-end}
.sp-header-btn{display:inline-flex;align-items:center;outline:none;padding:5px 14px;border:2px dashed #64748b;border-radius:15px;background-color:#f1f5f9;color:#64748b;font-size:11px;font-weight:600;cursor:pointer;transition:transform .2s ease-out;box-shadow:0 0 0 3px #f1f5f9,1.5px 1.5px 3px 1px rgba(0,0,0,.15);white-space:nowrap}
.sp-header-btn:hover:not(:disabled){transform:translateY(-4px) translateX(-2px);box-shadow:0 0 0 3px #f1f5f9,2px 5px 0 0 currentColor}
.sp-header-btn:active:not(:disabled){transform:translateY(1px) translateX(1px);box-shadow:0 0 0 3px #f1f5f9,0 0 0 0 currentColor}
.sp-header-btn:disabled{opacity:.5;cursor:not-allowed}
.sp-header-btn-primary{border-color:#075985;color:#075985;background-color:#f0f9ff;box-shadow:0 0 0 3px #f0f9ff,1.5px 1.5px 3px 1px rgba(0,0,0,.15)}
.sp-header-btn-primary:hover:not(:disabled){box-shadow:0 0 0 3px #f0f9ff,2px 5px 0 0 #075985}
.sp-inline-note{font-size:11px;color:#94a3b8;margin-top:6px}
.sp-protocol-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}
.sp-protocol-item{background:#f8fafc;border-radius:8px;padding:8px}
.sp-protocol-label{display:block;font-size:11px;color:#64748b}
.sp-protocol-value{display:block;font-size:12px;color:#1f2937;font-weight:600;margin-top:2px;word-break:break-word}
.sp-card-body--stack{display:flex;flex-direction:column;gap:10px}
.sp-toggle-item{display:flex;align-items:center;justify-content:space-between;padding:12px;border:1px solid rgba(221,214,254,.62);border-radius:12px;background:linear-gradient(135deg,rgba(255,255,255,.9),rgba(245,243,255,.72));gap:12px}
.sp-card--cyan .sp-toggle-item{border-color:rgba(125,211,252,.58);background:linear-gradient(135deg,rgba(255,255,255,.92),rgba(236,254,255,.72))}
.sp-toggle-last{border-bottom:none}
.sp-toggle-info{display:flex;flex-direction:column;gap:4px;min-width:0}
.sp-toggle-label{font-size:13px;font-weight:500;color:#1e293b}
.sp-toggle-desc{font-size:11px;color:#94a3b8;line-height:1.55}
.sp-toggle{position:relative;display:inline-block;width:36px;height:20px;flex-shrink:0}
.sp-toggle input{opacity:0;width:0;height:0}
.sp-toggle-slider{position:absolute;cursor:pointer;inset:0;background:#e2e8f0;border-radius:20px;transition:.2s}
.sp-toggle-slider::before{content:'';position:absolute;height:16px;width:16px;left:2px;bottom:2px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.sp-toggle input:checked+.sp-toggle-slider{background:linear-gradient(90deg,#8b5cf6,#06b6d4)}
.sp-toggle input:checked+.sp-toggle-slider::before{transform:translateX(16px)}
.sp-modal-mask{position:fixed;inset:0;background:rgba(15,23,42,.35);display:flex;align-items:center;justify-content:center;z-index:99}
.sp-modal{width:380px;max-width:calc(100vw - 32px);background:#fff;border:2px dashed #64748b;border-radius:18px;padding:18px 20px;box-shadow:0 0 0 3px #fff,4px 6px 0 0 rgba(15,23,42,.12)}
.sp-modal-title{font-size:14px;font-weight:600;color:#0f172a}
.sp-modal-sub{font-size:12px;color:#64748b;margin-top:4px;margin-bottom:12px;line-height:1.6}
.sp-consent-block{margin-bottom:12px;padding:10px 12px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc}
.sp-consent-details{margin:0}
.sp-consent-summary{cursor:pointer;font-size:12px;font-weight:600;color:#3b82f6;outline:none;list-style:none;display:inline-flex;align-items:center;gap:4px}
.sp-consent-summary::-webkit-details-marker{display:none}
.sp-consent-summary::before{content:"▸";display:inline-block;transition:transform .15s ease;color:#94a3b8}
.sp-consent-details[open] .sp-consent-summary::before{transform:rotate(90deg)}
.sp-consent-summary:hover{color:#2563eb}
.sp-consent-text{margin-top:8px;padding:10px 12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;line-height:1.65;color:#334155;max-height:320px;overflow-y:auto}
.sp-consent-text p{margin:0 0 6px 0}
.sp-consent-text strong{color:#0f172a;font-weight:600}
.sp-consent-text ul{margin:4px 0 6px 18px;padding:0}
.sp-consent-text li{margin:3px 0;list-style:disc}
.sp-consent-section{margin-bottom:12px;padding-bottom:8px;border-bottom:1px dashed #e2e8f0}
.sp-consent-section:last-child{margin-bottom:0;padding-bottom:0;border-bottom:none}
.sp-consent-section-title{font-weight:600;color:#0f172a;font-size:12.5px;margin:0 0 4px 0}
.sp-consent-check{margin-top:10px;display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:12px;line-height:1.5;color:#1e293b;user-select:none}
.sp-consent-check input[type=checkbox]{margin-top:2px;width:14px;height:14px;cursor:pointer;accent-color:#3b82f6;flex-shrink:0}
.sp-consent-check span{flex:1}
</style>
