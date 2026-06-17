import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import { resolve } from "node:path";

// 构建产物输出到插件的 assets/ 目录，固定文件名，便于 PHP enqueue。
// dev 模式直接用 index.html（带 mock bootstrap）独立预览整套后台 UI。
export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: resolve(__dirname, "../assets/dist"),
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: resolve(__dirname, "src/main.ts"),
      output: {
        format: "iife",
        inlineDynamicImports: true,
        entryFileNames: "wp-astrahub-admin.js",
        assetFileNames: (assetInfo) => {
          if (assetInfo.name && assetInfo.name.endsWith(".css")) {
            return "wp-astrahub-admin.css";
          }
          return "[name][extname]";
        }
      }
    }
  },
  server: {
    port: 5273,
    open: "/index.html"
  }
});
