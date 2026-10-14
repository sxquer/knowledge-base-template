<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from "@tiptap/vue-3";
import PasswordView from "./PasswordView.vue";
defineProps(nodeViewProps);
</script>
<template>
    <NodeViewWrapper class="password-node" contenteditable="false">
        <PasswordView :password-entry-id="node.attrs.passwordEntryId" />
    </NodeViewWrapper>
</template>
