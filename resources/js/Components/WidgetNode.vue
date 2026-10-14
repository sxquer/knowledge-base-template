<script setup lang="ts">
import { inject } from "vue";
import type { Ref } from "vue";
import { NodeViewWrapper, nodeViewProps } from "@tiptap/vue-3";
import type { Widget } from "../types";
import WidgetView from "./WidgetView.vue";
const props = defineProps(nodeViewProps);
const widgets = inject<Ref<Widget[]>>("widgets");
</script>
<template>
    <NodeViewWrapper class="widget-node" contenteditable="false"
        ><WidgetView
            :definition="widgets?.find((w) => w.key === node.attrs.definition)"
            :data="node.attrs.data ?? {}"
            :editable="editor.isEditable"
            @change="updateAttributes({ data: $event })"
    /></NodeViewWrapper>
</template>
