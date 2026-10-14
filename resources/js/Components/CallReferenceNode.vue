<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from "@tiptap/vue-3";
import CallReferenceView from "./CallReferenceView.vue";
defineProps(nodeViewProps);
</script>
<template>
    <NodeViewWrapper contenteditable="false"
        ><CallReferenceView :recording-id="node.attrs.recordingId"
    /></NodeViewWrapper>
</template>
