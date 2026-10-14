<script setup lang="ts">
import { ref, onMounted } from "vue";
import { api } from "../api";
import { actions, date, paragraph, statuses } from "../types";
import Icon from "./Icon.vue";
const props = defineProps<{ canEdit: boolean }>();
const emit = defineEmits(["open", "error"]);
const since = ref(
    new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10),
);
const changes = ref<any[]>([]);
const pages = ref<any[]>([]);
const labels: Record<string, string> = {
    title: "Название",
    parent_id: "Раздел",
    status: "Статус",
    visibility: "Доступ",
    owner_label: "Владелец",
    sources: "Источники",
};
function value(key: string, v: any): string {
    if (key === "parent_id")
        return v
            ? (pages.value.find((p) => p.id === v)?.title ?? "Другой раздел")
            : "Корень базы";
    if (v === null || v === undefined || v === "") return "Не указано";
    if (key === "status") return statuses[v] ?? v;
    if (key === "visibility") return v === "team" ? "Команда" : "Автор и админ";
    if (key === "sources")
        return (
            v.map((s: any) => s.title || s.url).join("; ") || "Нет источников"
        );
    return String(v);
}
function details(c: any): string[] {
    return [
        c.reason,
        ...Object.entries(c.metadata ?? {}).map(
            ([k, v]: any) =>
                `${labels[k] ?? k}: ${value(k, v.before)} → ${value(k, v.after)}`,
        ),
        ...c.diff.flatMap((d: any) =>
            d.before === d.after
                ? ["Изменено форматирование или параметры блока"]
                : [
                      d.before ? "Было: " + d.before : "",
                      d.after ? "Стало: " + d.after : "",
                  ],
        ),
    ].filter(Boolean);
}
const loading = ref(false);
const creating = ref(false);
async function load() {
    loading.value = true;
    try {
        pages.value = await api("/api/pages");
        changes.value = await api(
            "/api/changes?since=" +
                encodeURIComponent(
                    new Date(since.value + "T00:00:00").toISOString(),
                ),
        );
    } catch (e: any) {
        emit("error", e.message);
    } finally {
        loading.value = false;
    }
}
function text() {
    return changes.value
        .map(
            (
                c,
            ) => `## ${actions[c.action] ?? c.action}: ${c.title} · v${c.number}
${date(c.at)}

${details(c).join("\n\n")}

[Открыть материал](${location.origin}/app/${c.page_id})`,
        )
        .join("\n\n");
}
function download() {
    const blob = new Blob([`# Обновления базы с ${since.value}\n\n${text()}`], {
        type: "text/markdown;charset=utf-8",
    });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `Обновления-${since.value}.md`;
    link.click();
    URL.revokeObjectURL(link.href);
}
async function save() {
    creating.value = true;
    try {
        const p = await api("/api/pages", "POST", {
            title: `Обновления базы с ${since.value}`,
            document: {
                type: "doc",
                content: changes.value.flatMap((c) => [
                    {
                        type: "heading",
                        attrs: { level: 2 },
                        content: [
                            {
                                type: "text",
                                text: `${actions[c.action] ?? c.action}: ${c.title} · v${c.number}`,
                            },
                        ],
                    },
                    paragraph(date(c.at)),
                    ...details(c).map(paragraph),
                    {
                        type: "paragraph",
                        content: [
                            {
                                type: "text",
                                text: "Открыть материал",
                                marks: [
                                    {
                                        type: "link",
                                        attrs: {
                                            href: `${location.origin}/app/${c.page_id}`,
                                        },
                                    },
                                ],
                            },
                        ],
                    },
                ]),
            },
            status: "draft",
            visibility: "restricted",
            owner_label: "Автор сводки",
            reason: "Сформирован документ по журналу изменений",
        });
        emit("open", p.id);
    } catch (e: any) {
        emit("error", e.message);
    } finally {
        creating.value = false;
    }
}
onMounted(load);
</script>
<template>
    <section class="feature-page">
        <div class="feature-icon"><Icon name="History" :size="30" /></div>
        <h1>Что изменилось</h1>
        <p class="subtitle">
            Новые знания, правки и публикации — с точными версиями.
        </p>
        <div class="filter-bar">
            <label
                >Начиная с
                <input type="date" v-model="since" @change="load" /></label
            ><button
                class="button"
                @click="download"
                :disabled="!changes.length"
            >
                <Icon name="Download" />Скачать</button
            ><button
                v-if="canEdit"
                class="button dark"
                @click="save"
                :disabled="creating || !changes.length"
            >
                Сохранить документ
            </button>
        </div>
        <p class="muted" v-if="loading">Собираем журнал…</p>
        <div class="empty-state" v-else-if="!changes.length">
            <Icon name="Check" :size="30" />
            <h3>Изменений за период нет</h3>
            <p>Попробуйте выбрать более раннюю дату.</p>
        </div>
        <div v-for="c in changes" :key="c.id" class="change-item">
            <div class="change-line">
                <span :class="['change-action', c.action]">{{
                    actions[c.action] ?? c.action
                }}</span
                ><span class="muted mono"
                    >{{ date(c.at) }} · v{{ c.number }}</span
                >
            </div>
            <button
                class="text-button change-title"
                @click="emit('open', c.page_id)"
            >
                {{ c.title }}<Icon name="ArrowUpRight" :size="16" />
            </button>
            <p>{{ c.reason }}</p>
            <details
                v-if="c.diff.length || Object.keys(c.metadata ?? {}).length"
            >
                <summary>Посмотреть изменения</summary>
                <div class="diff-block" v-for="d in c.diff">
                    <p v-if="d.before === d.after">
                        Изменено форматирование или параметры блока
                    </p>
                    <del v-if="d.before">{{ d.before }}</del
                    ><ins v-if="d.after">{{ d.after }}</ins>
                </div>
                <p class="metadata-diff" v-for="(v, key) in c.metadata">
                    <strong>{{ labels[String(key)] ?? key }}:</strong>
                    {{ value(String(key), (v as any).before) }} →
                    {{ value(String(key), (v as any).after) }}
                </p>
            </details>
        </div>
    </section>
</template>
