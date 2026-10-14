<script setup lang="ts">
import type { DocNode, Widget } from "../types";
import { computed } from "vue";
import WidgetView from "./WidgetView.vue";
import CallView from "./CallView.vue";
import CallReferenceView from "./CallReferenceView.vue";
import PasswordView from "./PasswordView.vue";
const props = defineProps<{
    node: DocNode;
    widgets: Widget[];
    publication?: string;
}>();
const tags: Record<string, string> = {
    doc: "div",
    paragraph: "p",
    blockquote: "blockquote",
    bulletList: "ul",
    orderedList: "ol",
    listItem: "li",
    taskList: "ul",
    taskItem: "li",
    codeBlock: "pre",
    table: "table",
    tableRow: "tr",
    tableCell: "td",
    tableHeader: "th",
};
const tag = computed(() =>
    props.node.type === "heading"
        ? "h" + Math.min(6, Math.max(1, props.node.attrs?.level ?? 2))
        : (tags[props.node.type] ?? "div"),
);
const style = computed(() => ({
    fontWeight: props.node.marks?.some((m) => m.type === "bold")
        ? "700"
        : undefined,
    fontStyle: props.node.marks?.some((m) => m.type === "italic")
        ? "italic"
        : undefined,
    textDecoration: props.node.marks?.some((m) => m.type === "strike")
        ? "line-through"
        : props.node.marks?.some((m) => m.type === "underline")
          ? "underline"
          : undefined,
}));
const href = computed(
    () => props.node.marks?.find((m) => m.type === "link")?.attrs?.href,
);
const media = computed(
    () =>
        "/media/" +
        props.node.attrs?.assetId +
        (props.publication ? "?publication=" + props.publication : ""),
);
const youtube = computed(() => {
    try {
        const u = new URL(props.node.attrs?.src);
        const id =
            u.hostname === "youtu.be"
                ? u.pathname.slice(1)
                : (u.searchParams.get("v") ?? u.pathname.split("/").pop());
        return (
            "https://www.youtube-nocookie.com/embed/" +
            encodeURIComponent(id ?? "")
        );
    } catch {
        return "";
    }
});
</script>
<template>
    <CallReferenceView
        v-if="node.type === 'callReference'"
        :recording-id="node.attrs?.recordingId"
    />
    <template v-else-if="node.type === 'text'"
        ><a
            v-if="href"
            :href="href"
            :style="style"
            target="_blank"
            rel="noopener noreferrer"
            >{{ node.text }}</a
        ><code v-else-if="node.marks?.some((m) => m.type === 'code')">{{
            node.text
        }}</code
        ><span v-else :style="style">{{ node.text }}</span></template
    ><br v-else-if="node.type === 'hardBreak'" />
    <hr v-else-if="node.type === 'horizontalRule'" />
    <CallView
        v-else-if="node.type === 'callRecording'"
        :recording-id="node.attrs?.recordingId"
        :snapshot="node.attrs?.snapshot"
        :publication="publication"
    />
    <PasswordView
        v-else-if="node.type === 'passwordReference'"
        :password-entry-id="node.attrs?.passwordEntryId"
    />
    <WidgetView
        v-else-if="node.type === 'kbWidget'"
        :definition="widgets.find((w) => w.key === node.attrs?.definition)"
        :data="node.attrs?.data ?? {}"
    /><template v-else-if="node.type === 'media' || node.type === 'image'"
        ><img
            v-if="node.attrs?.kind === 'image' || node.type === 'image'"
            :src="media"
            :alt="node.attrs?.alt ?? ''"
        /><audio
            v-else-if="node.attrs?.kind === 'audio'"
            :src="media"
            controls
            preload="metadata"
        /><video
            v-else-if="node.attrs?.kind === 'video'"
            :src="media"
            controls
            preload="metadata"
        /><a v-else :href="media" target="_blank" rel="noopener"
            >Открыть вложение</a
        ></template
    ><iframe
        v-else-if="node.type === 'youtube'"
        :src="youtube"
        title="Видеопроигрыватель"
        allowfullscreen
        loading="lazy"
    /><component v-else :is="tag" :id="node.attrs?.id" :data-type="node.type"
        ><input
            v-if="node.type === 'taskItem'"
            type="checkbox"
            :checked="node.attrs?.checked"
            disabled /><DocumentNode
            v-for="(child, i) in node.content"
            :key="child.attrs?.id ?? i"
            :node="child"
            :widgets="widgets"
            :publication="publication"
    /></component>
</template>
