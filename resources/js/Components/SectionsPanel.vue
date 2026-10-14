<script setup lang="ts">
import { computed, ref } from "vue";
import type { Page } from "../types";
import { statuses } from "../types";
import Icon from "./Icon.vue";

const props = defineProps<{
    pages: Page[];
    canEdit?: boolean;
    moving?: boolean;
}>();
const emit = defineEmits<{
    open: [id: string];
    move: [target: { id: string; parentId: string | null }];
}>();
const query = ref("");
const collapsed = ref(new Set<string>());
const draggingId = ref<string | null>(null);
const dropTarget = ref<string | null>(null);
const rootTarget = "root";
const normalize = (text: string) =>
    text.toLocaleLowerCase("ru").replaceAll("ё", "е").trim();
const indexed = computed(
    () => new Map(props.pages.map((page) => [page.id, page])),
);
const children = computed(() => {
    const groups = new Map<string | null, Page[]>();
    for (const page of props.pages) {
        const parent =
            page.parent_id && indexed.value.has(page.parent_id)
                ? page.parent_id
                : null;
        const list = groups.get(parent) ?? [];
        list.push(page);
        groups.set(parent, list);
    }
    return groups;
});
const sectionCount = computed(
    () => props.pages.filter((page) => page.kind === "section").length,
);
const matches = computed(() => {
    const terms = normalize(query.value).split(/\s+/).filter(Boolean);
    return new Set(
        props.pages
            .filter((page) =>
                terms.every((term) => normalize(page.title).includes(term)),
            )
            .map((page) => page.id),
    );
});
const visible = computed(() => {
    if (!query.value.trim()) return new Set(indexed.value.keys());
    const ids = new Set(matches.value);
    for (const id of matches.value) {
        let parent = indexed.value.get(id)?.parent_id;
        const visited = new Set<string>();
        while (parent && !visited.has(parent)) {
            visited.add(parent);
            ids.add(parent);
            parent = indexed.value.get(parent)?.parent_id;
        }
    }
    return ids;
});
const rows = computed(() => {
    const result: {
        page: Page;
        depth: number;
        count: number;
        expanded: boolean;
        path: string;
    }[] = [];
    const visited = new Set<string>();
    function walk(parent: string | null, depth: number, path: string[]) {
        for (const page of children.value.get(parent) ?? []) {
            if (visited.has(page.id) || !visible.value.has(page.id)) continue;
            visited.add(page.id);
            const count = children.value.get(page.id)?.length ?? 0;
            const expanded =
                !!query.value.trim() || !collapsed.value.has(page.id);
            result.push({
                page,
                depth,
                count,
                expanded,
                path: path.join(" / "),
            });
            if (expanded) walk(page.id, depth + 1, [...path, page.title]);
        }
    }
    walk(null, 0, []);
    return result;
});
function noun(count: number, forms: [string, string, string]) {
    const last = count % 10;
    const ending = count % 100;
    return forms[
        ending >= 11 && ending <= 14
            ? 2
            : last === 1
              ? 0
              : last >= 2 && last <= 4
                ? 1
                : 2
    ];
}
function toggle(id: string) {
    const next = new Set(collapsed.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    collapsed.value = next;
}
function collapseAll() {
    collapsed.value = new Set(props.pages.map((page) => page.id));
}
function open(event: MouseEvent, id: string) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)
        return;
    event.preventDefault();
    emit("open", id);
}
function canDrop(
    parentId: string | null,
    sourceId = draggingId.value,
): boolean {
    if (!props.canEdit || props.moving) return false;
    if (parentId && indexed.value.get(parentId)?.kind !== "section")
        return false;
    if (!sourceId) return true; // A page dragged from the sidebar is checked on drop.
    const source = indexed.value.get(sourceId);
    if (!source || source.parent_id === parentId) return false;
    const visited = new Set<string>();
    let ancestor = parentId;
    while (ancestor) {
        if (ancestor === sourceId || visited.has(ancestor)) return false;
        visited.add(ancestor);
        ancestor = indexed.value.get(ancestor)?.parent_id ?? null;
    }
    return true;
}
function startDrag(event: DragEvent, id: string) {
    if (!props.canEdit || props.moving) {
        event.preventDefault();
        return;
    }
    draggingId.value = id;
    event.dataTransfer?.setData("application/x-kb-page", id);
    if (event.dataTransfer) event.dataTransfer.effectAllowed = "move";
}
function endDrag() {
    draggingId.value = null;
    dropTarget.value = null;
}
function dragOver(event: DragEvent, parentId: string | null) {
    if (
        !event.dataTransfer?.types.includes("application/x-kb-page") ||
        !canDrop(parentId)
    )
        return;
    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
    dropTarget.value = parentId ?? rootTarget;
}
function dragLeave(event: DragEvent) {
    if (
        !(event.currentTarget as HTMLElement).contains(
            event.relatedTarget as Node | null,
        )
    )
        dropTarget.value = null;
}
function drop(event: DragEvent, parentId: string | null) {
    const id = event.dataTransfer?.getData("application/x-kb-page");
    event.preventDefault();
    if (id && canDrop(parentId, id)) {
        if (parentId) collapsed.value.delete(parentId);
        emit("move", { id, parentId });
    }
    endDrag();
}
</script>

<template>
    <section class="sections-panel" aria-labelledby="sections-title">
        <div class="sections-heading">
            <div>
                <p class="sections-eyebrow">Навигация по базе</p>
                <h1 id="sections-title">Разделы базы знаний</h1>
                <p class="sections-description">
                    Вся структура на одной странице. Выберите раздел или
                    материал, чтобы открыть его.
                </p>
            </div>
            <div class="sections-counts">
                <strong>{{ sectionCount }}</strong
                ><span>{{
                    noun(sectionCount, ["раздел", "раздела", "разделов"])
                }}</span
                ><i aria-hidden="true" /><strong>{{
                    pages.length - sectionCount
                }}</strong
                ><span>{{
                    noun(pages.length - sectionCount, [
                        "материал",
                        "материала",
                        "материалов",
                    ])
                }}</span>
            </div>
        </div>
        <div class="sections-controls">
            <label class="sections-search"
                ><Icon name="Search" :size="19" /><input
                    v-model="query"
                    type="search"
                    aria-label="Поиск раздела или материала"
                    placeholder="Найти раздел или материал по названию" /><button
                    v-if="query"
                    class="icon-button"
                    aria-label="Очистить поиск по структуре"
                    @click="query = ''"
                >
                    <Icon name="X" :size="16" /></button
            ></label>
            <div class="sections-expand">
                <button
                    class="button quiet"
                    :disabled="!!query.trim() || !pages.length"
                    @click="collapsed = new Set()"
                >
                    Развернуть всё</button
                ><button
                    class="button quiet"
                    :disabled="!!query.trim() || !pages.length"
                    @click="collapseAll"
                >
                    Свернуть всё
                </button>
            </div>
        </div>
        <div
            v-if="canEdit"
            class="sections-move-bar"
            :class="{
                'is-drop-target': dropTarget === rootTarget,
                'is-moving': moving,
            }"
            @dragover="dragOver($event, null)"
            @dragleave="dragLeave"
            @drop.stop="drop($event, null)"
        >
            <Icon
                :name="moving ? 'LoaderCircle' : 'FolderUp'"
                :size="19"
                :class="{ spin: moving }"
            />
            <span role="status">{{
                moving
                    ? "Перемещаем…"
                    : dropTarget === rootTarget
                      ? "Отпустите, чтобы вынести в корень базы"
                      : "Перетащите на папку — вложить. Сюда — вынести в корень."
            }}</span>
        </div>
        <p v-if="query.trim()" class="sections-search-note" role="status">
            Найдено: {{ matches.size }}. Родительские разделы показаны для
            ориентира.
        </p>
        <div v-if="!pages.length" class="empty-state">
            <Icon name="Folder" :size="32" />
            <h2>Разделов пока нет</h2>
            <p>Здесь появится структура доступных вам материалов.</p>
        </div>
        <div v-else-if="!rows.length" class="empty-state">
            <h2>Ничего не найдено</h2>
            <p>Попробуйте другое название или очистите поиск.</p>
            <button class="button" @click="query = ''">Очистить поиск</button>
        </div>
        <ul v-else class="sections-tree" aria-label="Структура базы знаний">
            <li
                v-for="row in rows"
                :key="row.page.id"
                class="sections-tree-item"
                :class="{
                    'is-root': row.depth === 0,
                    'is-section': row.page.kind === 'section',
                    'is-match': query.trim() && matches.has(row.page.id),
                }"
                :style="{ '--section-depth': Math.min(row.depth, 8) }"
            >
                <div
                    class="sections-tree-row"
                    :class="{
                        'is-dragging': draggingId === row.page.id,
                        'is-drop-target': dropTarget === row.page.id,
                    }"
                    :draggable="canEdit && !moving"
                    @dragstart.stop="startDrag($event, row.page.id)"
                    @dragend="endDrag"
                    @dragover="dragOver($event, row.page.id)"
                    @dragleave="dragLeave"
                    @drop.stop="drop($event, row.page.id)"
                >
                    <Icon
                        v-if="canEdit"
                        name="GripVertical"
                        :size="16"
                        class="sections-drag-grip"
                        aria-hidden="true"
                    />
                    <button
                        v-if="row.count && !query.trim()"
                        class="sections-toggle"
                        :aria-expanded="row.expanded"
                        :aria-label="`${row.expanded ? 'Свернуть' : 'Развернуть'}: ${row.page.title}`"
                        @click="toggle(row.page.id)"
                    >
                        <Icon
                            :name="
                                row.expanded ? 'ChevronDown' : 'ChevronRight'
                            "
                            :size="18"
                        /></button
                    ><span
                        v-else
                        class="sections-toggle-spacer"
                        aria-hidden="true"
                    />
                    <Icon
                        :name="
                            row.page.kind === 'section' ? 'Folder' : 'FileText'
                        "
                        :size="21"
                        class="sections-item-icon"
                    />
                    <div class="sections-item-text">
                        <a
                            :draggable="false"
                            :href="`/app/${row.page.id}`"
                            @click="open($event, row.page.id)"
                            >{{ row.page.title }}</a
                        ><small v-if="row.path && query.trim()">{{
                            row.path
                        }}</small
                        ><small v-else>{{
                            row.page.kind === "section"
                                ? row.count
                                    ? `Внутри: ${row.count}`
                                    : "Пустой раздел"
                                : statuses[row.page.status] || "Материал"
                        }}</small>
                    </div>
                    <Icon
                        v-if="row.page.visibility === 'restricted'"
                        name="Lock"
                        :size="15"
                        class="muted"
                    />
                    <a
                        :draggable="false"
                        class="sections-open"
                        :href="`/app/${row.page.id}`"
                        :aria-label="`Открыть: ${row.page.title}`"
                        @click="open($event, row.page.id)"
                        ><span>Открыть</span
                        ><Icon name="ArrowUpRight" :size="17"
                    /></a>
                </div>
            </li>
        </ul>
    </section>
</template>
