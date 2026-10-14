<script setup lang="ts">
import { ref, computed, watch } from "vue";
import type { Page } from "../types";
import Icon from "./Icon.vue";
const props = withDefaults(
    defineProps<{
        page: Page;
        pages: Page[];
        selected: string;
        canEdit?: boolean;
        depth?: number;
    }>(),
    { depth: 0 },
);
const emit = defineEmits(["select", "move"]);
const expanded = ref(props.depth === 0);
const dragging = ref(false);
const dropTarget = ref(false);
const children = computed(() =>
    props.pages.filter((p) => p.parent_id === props.page.id),
);
watch(
    () => props.selected,
    (selected) => {
        const seen = new Set<string>();
        let current = props.pages.find((p) => p.id === selected);
        while (current?.parent_id && !seen.has(current.id)) {
            seen.add(current.id);
            if (current.parent_id === props.page.id) {
                expanded.value = true;
                break;
            }
            current = props.pages.find((p) => p.id === current?.parent_id);
        }
    },
    { immediate: true },
);
function startDrag(event: DragEvent) {
    if (!props.canEdit) return;
    dragging.value = true;
    event.dataTransfer?.setData("application/x-kb-page", props.page.id);
    if (event.dataTransfer) event.dataTransfer.effectAllowed = "move";
}
function endDrag() {
    dragging.value = false;
    dropTarget.value = false;
}
function allowDrop(event: DragEvent) {
    if (
        !props.canEdit ||
        props.page.kind !== "section" ||
        !event.dataTransfer?.types.includes("application/x-kb-page")
    )
        return;
    event.preventDefault();
    dropTarget.value = true;
    if (event.dataTransfer) event.dataTransfer.dropEffect = "move";
}
function leaveDrop(event: DragEvent) {
    if (
        !(event.currentTarget as HTMLElement).contains(
            event.relatedTarget as Node | null,
        )
    )
        dropTarget.value = false;
}
function drop(event: DragEvent) {
    if (!props.canEdit || props.page.kind !== "section") return;
    event.preventDefault();
    dropTarget.value = false;
    const id = event.dataTransfer?.getData("application/x-kb-page");
    if (id && id !== props.page.id)
        emit("move", { id, parentId: props.page.id });
}
</script>
<template>
    <div>
        <div
            :class="[
                'tree-row',
                {
                    selected: page.id === selected,
                    dragging,
                    'drop-target': dropTarget,
                },
            ]"
            :style="{ paddingLeft: 12 + depth * 16 + 'px' }"
            :draggable="canEdit"
            @dragstart="startDrag"
            @dragend="endDrag"
            @dragover="allowDrop"
            @dragleave="leaveDrop"
            @drop="drop"
        >
            <button
                class="tree-chevron"
                :aria-label="expanded ? 'Свернуть' : 'Развернуть'"
                @click="expanded = !expanded"
                v-if="children.length"
            >
                <Icon
                    :name="expanded ? 'ChevronDown' : 'ChevronRight'"
                    :size="13"
                /></button
            ><span v-else class="tree-chevron" /><button
                class="tree-label"
                @click="emit('select', page.id)"
            >
                <Icon
                    :name="page.kind === 'section' ? 'Folder' : 'FileText'"
                    :size="16"
                /><span>{{ page.title }}</span
                ><Icon
                    v-if="page.visibility === 'restricted'"
                    name="Lock"
                    :size="12"
                />
            </button>
        </div>
        <template v-if="expanded"
            ><TreeNode
                v-for="child in children"
                :key="child.id"
                :page="child"
                :pages="pages"
                :selected="selected"
                :can-edit="canEdit"
                :depth="depth + 1"
                @select="emit('select', $event)"
                @move="emit('move', $event)"
        /></template>
    </div>
</template>
