<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from "@tiptap/vue-3";
import CallView from "./CallView.vue";
defineProps(nodeViewProps);
</script>
<template>
    <NodeViewWrapper class="call-node" contenteditable="false"
        ><CallView
            :recording-id="node.attrs.recordingId"
            :editable="editor.isEditable"
    /></NodeViewWrapper>
</template>
