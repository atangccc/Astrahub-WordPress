declare module "*.vue" {
  import type { DefineComponent } from "vue";
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
  export default component;
}

interface AstraHubBootstrap {
  restBase: string;
  restNonce: string;
  hubBaseUrl: string;
  registered: boolean;
  __dev?: boolean;
}

interface Window {
  WP_ASTRAHUB_BOOTSTRAP?: AstraHubBootstrap;
}
