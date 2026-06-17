import { reactive, toRefs } from "vue";
import { api } from "../api/client";

export interface CredentialsView {
  siteId: string;
  apiKeyMask: string;
  hasApiKey: boolean;
  createdAt: string;
  nodeName: string;
  category: string;
  nodeAvatar: string;
}

export interface ConnectionView {
  siteName: string;
  siteUrl: string;
  siteDescription: string;
  siteRssUrl: string;
  siteAvatarUrl: string;
  contactEmail: string;
  siteNodeName: string;
  siteNodeAvatar: string;
}

interface StatusState {
  loading: boolean;
  registered: boolean;
  credentials: CredentialsView;
  connection: ConnectionView;
  hubBaseUrl: string;
  error: string;
}

const emptyCredentials: CredentialsView = {
  siteId: "",
  apiKeyMask: "",
  hasApiKey: false,
  createdAt: "",
  nodeName: "",
  category: "",
  nodeAvatar: ""
};

const emptyConnection: ConnectionView = {
  siteName: "",
  siteUrl: "",
  siteDescription: "",
  siteRssUrl: "",
  siteAvatarUrl: "",
  contactEmail: "",
  siteNodeName: "",
  siteNodeAvatar: ""
};

const state = reactive<StatusState>({
  loading: false,
  registered: false,
  credentials: { ...emptyCredentials },
  connection: { ...emptyConnection },
  hubBaseUrl: "",
  error: ""
});

interface StatusPayload {
  registered: boolean;
  credentials: CredentialsView;
  connection: ConnectionView;
  hubBaseUrl: string;
}

export function useStatus() {
  async function fetchStatus() {
    state.loading = true;
    state.error = "";
    try {
      // /status 与其余接口统一：业务字段包在 data 下，读 resp.data。
      const resp = await api.get<StatusPayload>("/status");
      const raw = (resp.data || {}) as unknown as Record<string, unknown>;
      state.registered = Boolean(raw.registered ?? resp.success);
      if (raw.credentials) {
        state.credentials = raw.credentials as CredentialsView;
      }
      if (raw.connection) {
        state.connection = raw.connection as ConnectionView;
      }
      if (raw.hubBaseUrl) {
        state.hubBaseUrl = String(raw.hubBaseUrl);
      }
    } catch (e) {
      state.error = e instanceof Error ? e.message : "加载状态失败";
    } finally {
      state.loading = false;
    }
  }

  return {
    ...toRefs(state),
    fetchStatus
  };
}
