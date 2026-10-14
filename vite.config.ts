import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";
export default defineConfig({
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (
                        id.includes("node_modules/@tiptap/") ||
                        id.includes("node_modules/prosemirror-")
                    )
                        return "editor";
                },
            },
        },
    },
    plugins: [
        laravel({
            input: [
                "resources/js/app.ts",
                "resources/js/public-calls.ts",
                "resources/css/app.css",
            ],
            refresh: true,
        }),
        vue(),
    ],
});
