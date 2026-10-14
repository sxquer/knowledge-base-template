<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from "@tiptap/vue-3";
defineProps(nodeViewProps);
</script>
<template>
    <NodeViewWrapper class="media-node" contenteditable="false"
        ><div v-if="node.attrs.kind === 'meeting'" class="call-widget">
            <strong>{{ node.attrs.title }}</strong>
            <p class="call-note">Запись планёрки</p>
            <video
                v-if="node.attrs.mime?.startsWith('video/')"
                controls
                preload="metadata"
                :src="'/media/' + node.attrs.assetId"
            />
            <audio
                v-else
                controls
                preload="metadata"
                :src="'/media/' + node.attrs.assetId"
            />
        </div>
        <img
            v-else-if="node.attrs.kind === 'image'"
            :src="'/media/' + node.attrs.assetId"
            :alt="node.attrs.name || ''"
        /><audio
            v-else-if="node.attrs.kind === 'audio'"
            controls
            preload="metadata"
            :src="'/media/' + node.attrs.assetId"
        /><video
            v-else-if="node.attrs.kind === 'video'"
            controls
            preload="metadata"
            :src="'/media/' + node.attrs.assetId"
        /><a
            v-else
            :href="'/media/' + node.attrs.assetId"
            target="_blank"
            rel="noopener"
            >Открыть вложение</a
        ></NodeViewWrapper
    >
</template>
