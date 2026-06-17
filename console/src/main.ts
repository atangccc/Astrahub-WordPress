import { createApp } from "vue";
import AstraHubApp from "./AstraHubApp.vue";
import "./styles/console.css";

const mountEl = document.getElementById("wp-astrahub-app");
if (mountEl) {
  createApp(AstraHubApp).mount(mountEl);
}
