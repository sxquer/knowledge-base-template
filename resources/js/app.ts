import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import "../css/app.css";
createInertiaApp({
    title: (title) => `${title} · База знаний`,
    resolve: (name) => {
        const pages = import.meta.glob("./Pages/*.vue");
        return (pages[`./Pages/${name}.vue`]() as Promise<any>).then(
            (m) => m.default,
        );
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
