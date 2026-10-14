<script setup lang="ts">
import { watch, onBeforeUnmount, ref } from "vue";
import { useEditor, EditorContent, VueNodeViewRenderer } from "@tiptap/vue-3";
import { Node, mergeAttributes } from "@tiptap/core";
import StarterKit from "@tiptap/starter-kit";
import { TableKit } from "@tiptap/extension-table";
import TaskList from "@tiptap/extension-task-list";
import TaskItem from "@tiptap/extension-task-item";
import Placeholder from "@tiptap/extension-placeholder";
import UniqueID from "@tiptap/extension-unique-id";
import Youtube from "@tiptap/extension-youtube";
import type { DocNode, Widget } from "../types";
import WidgetNode from "./WidgetNode.vue";
import MediaNode from "./MediaNode.vue";
import CallNode from "./CallNode.vue";
import CallReferenceNode from "./CallReferenceNode.vue";
import PasswordNode from "./PasswordNode.vue";
import Icon from "./Icon.vue";
import Modal from "./Modal.vue";
import { api } from "../api";
const props = defineProps<{
    modelValue: DocNode;
    editable: boolean;
    pageId: string;
    widgets: Widget[];
    canAddCall: boolean;
}>();
const emit = defineEmits([
    "update:modelValue",
    "error",
    "add-call",
    "add-meeting",
    "add-password",
]);
const more = ref(false);
const mediaDialog = ref(false);
const linkDialog = ref(false);
const tableToolsVisible = ref(false);
const url = ref("");
const uploading = ref(false);
const KbWidget = Node.create({
    name: "kbWidget",
    group: "block",
    atom: true,
    draggable: true,
    addAttributes() {
        return {
            definition: { default: "" },
            schemaVersion: { default: 1 },
            data: { default: {} },
        };
    },
    parseHTML() {
        return [{ tag: "div[data-kb-widget]" }];
    },
    renderHTML({ HTMLAttributes }) {
        return [
            "div",
            mergeAttributes(HTMLAttributes, { "data-kb-widget": "" }),
        ];
    },
    addNodeView() {
        return VueNodeViewRenderer(WidgetNode);
    },
});
const Media = Node.create({
    name: "media",
    group: "block",
    atom: true,
    draggable: true,
    addAttributes() {
        return {
            assetId: { default: "" },
            kind: { default: "file" },
            title: { default: "" },
            mime: { default: "" },
        };
    },
    parseHTML() {
        return [{ tag: "div[data-kb-media]" }];
    },
    renderHTML({ HTMLAttributes }) {
        return [
            "div",
            mergeAttributes(HTMLAttributes, { "data-kb-media": "" }),
        ];
    },
    addNodeView() {
        return VueNodeViewRenderer(MediaNode);
    },
});
const CallRecording = Node.create({
    name: "callRecording",
    group: "block",
    atom: true,
    draggable: true,
    addAttributes() {
        return { recordingId: { default: "" } };
    },
    parseHTML() {
        return [{ tag: "div[data-kb-call]" }];
    },
    renderHTML({ HTMLAttributes }) {
        return ["div", mergeAttributes(HTMLAttributes, { "data-kb-call": "" })];
    },
    addNodeView() {
        return VueNodeViewRenderer(CallNode);
    },
});
const CallReference = CallRecording.extend({
    name: "callReference",
    parseHTML() {
        return [{ tag: "div[data-kb-call-reference]" }];
    },
    renderHTML({ HTMLAttributes }) {
        return [
            "div",
            mergeAttributes(HTMLAttributes, { "data-kb-call-reference": "" }),
        ];
    },
    addNodeView() {
        return VueNodeViewRenderer(CallReferenceNode);
    },
});
const PasswordReference = Node.create({
    name: "passwordReference",
    group: "block",
    atom: true,
    draggable: true,
    addAttributes() {
        return { passwordEntryId: { default: "" } };
    },
    parseHTML() {
        return [{ tag: "div[data-kb-password]" }];
    },
    renderHTML({ HTMLAttributes }) {
        return [
            "div",
            mergeAttributes(HTMLAttributes, { "data-kb-password": "" }),
        ];
    },
    addNodeView() {
        return VueNodeViewRenderer(PasswordNode);
    },
});
const editor = useEditor({
    content: props.modelValue as any,
    editable: props.editable,
    extensions: [
        StarterKit.configure({ link: { openOnClick: false } }),
        TableKit.configure({ table: { resizable: true } }),
        TaskList,
        TaskItem.configure({ nested: true }),
        Placeholder.configure({
            placeholder: "Напишите текст или добавьте блок…",
        }),
        UniqueID.configure({
            attributeName: "id",
            types: [
                "paragraph",
                "heading",
                "blockquote",
                "bulletList",
                "orderedList",
                "listItem",
                "taskList",
                "taskItem",
                "codeBlock",
                "horizontalRule",
                "table",
                "tableRow",
                "tableCell",
                "tableHeader",
                "media",
                "youtube",
                "kbWidget",
                "callRecording",
                "callReference",
                "passwordReference",
            ],
        }),
        Youtube.configure({ nocookie: true }),
        KbWidget,
        Media,
        CallRecording,
        CallReference,
        PasswordReference,
    ],
    onCreate: ({ editor }) => {
        tableToolsVisible.value = editor.isActive("table");
    },
    onSelectionUpdate: ({ editor }) => {
        tableToolsVisible.value = editor.isActive("table");
    },
    onUpdate: ({ editor }) => {
        tableToolsVisible.value = editor.isActive("table");
        emit("update:modelValue", editor.getJSON());
    },
});
watch(
    () => props.editable,
    (v) => editor.value?.setEditable(v),
);
watch(
    () => props.modelValue,
    (v) => {
        if (
            editor.value &&
            JSON.stringify(v) !== JSON.stringify(editor.value.getJSON())
        )
            editor.value.commands.setContent(v as any, { emitUpdate: false });
    },
);
onBeforeUnmount(() => editor.value?.destroy());
function widget(w: Widget) {
    editor.value
        ?.chain()
        .focus()
        .insertContent({
            type: "kbWidget",
            attrs: {
                definition: w.key,
                schemaVersion: w.schema_version,
                data: Object.fromEntries(
                    w.fields.map((f) => [
                        f.key,
                        f.type === "boolean" ? false : "",
                    ]),
                ),
            },
        })
        .run();
    more.value = false;
}
function insertPasswordReference(passwordEntryId: string) {
    editor.value
        ?.chain()
        .focus()
        .insertContent({
            type: "passwordReference",
            attrs: { passwordEntryId },
        })
        .run();
    more.value = false;
}
async function upload(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    uploading.value = true;
    try {
        const form = new FormData();
        form.append("file", file);
        const result = await api(
            `/api/pages/${props.pageId}/assets`,
            "POST",
            form,
        );
        const kind = result.mime.startsWith("image/")
            ? "image"
            : result.mime.startsWith("audio/")
              ? "audio"
              : result.mime.startsWith("video/")
                ? "video"
                : "file";
        editor.value
            ?.chain()
            .focus()
            .insertContent({
                type: "media",
                attrs: { assetId: result.id, kind },
            })
            .run();
        mediaDialog.value = false;
    } catch (e: any) {
        emit("error", e.message);
    } finally {
        uploading.value = false;
    }
}
function youtube() {
    if (!/^https:\/\/(www\.)?(youtube\.com|youtu\.be)\//.test(url.value)) {
        emit("error", "Нужна ссылка на YouTube");
        return;
    }
    editor.value?.commands.setYoutubeVideo({ src: url.value });
    mediaDialog.value = false;
    url.value = "";
}
function link() {
    if (!/^(https?:\/\/|mailto:)/.test(url.value)) {
        emit("error", "Используйте http, https или mailto");
        return;
    }
    editor.value
        ?.chain()
        .focus()
        .extendMarkRange("link")
        .setLink({ href: url.value })
        .run();
    linkDialog.value = false;
    url.value = "";
}
defineExpose({ editor, insertPasswordReference });
</script>
<template>
    <div v-if="editor" class="rich-editor">
        <div v-if="editable" class="editor-toolbar" aria-label="Форматирование">
            <button
                title="Жирный"
                :class="{ active: editor.isActive('bold') }"
                @click="editor.chain().focus().toggleBold().run()"
            >
                <Icon name="Bold" :size="16" /></button
            ><button
                title="Курсив"
                :class="{ active: editor.isActive('italic') }"
                @click="editor.chain().focus().toggleItalic().run()"
            >
                <Icon name="Italic" :size="16" /></button
            ><span class="toolbar-rule" /><button
                title="Заголовок"
                @click="
                    editor.chain().focus().toggleHeading({ level: 2 }).run()
                "
            >
                <Icon name="Heading2" :size="18" /></button
            ><button
                title="Список"
                @click="editor.chain().focus().toggleBulletList().run()"
            >
                <Icon name="List" :size="17" /></button
            ><button
                title="Чеклист"
                @click="editor.chain().focus().toggleTaskList().run()"
            >
                <Icon name="ListChecks" :size="17" /></button
            ><button
                title="Цитата"
                @click="editor.chain().focus().toggleBlockquote().run()"
            >
                <Icon name="Quote" :size="17" /></button
            ><button
                title="Таблица"
                @click="
                    editor
                        .chain()
                        .focus()
                        .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
                        .run()
                "
            >
                <Icon name="Table" :size="17" /></button
            ><template v-if="tableToolsVisible"
                ><span class="toolbar-rule" />
                <button
                    title="Вставить строку выше текущей"
                    @click="editor.chain().focus().addRowBefore().run()"
                >
                    Строка ↑</button
                ><button
                    title="Вставить строку ниже текущей"
                    @click="editor.chain().focus().addRowAfter().run()"
                >
                    Строка ↓</button
                ><button
                    title="Вставить столбец слева от текущего"
                    @click="editor.chain().focus().addColumnBefore().run()"
                >
                    Столбец ←</button
                ><button
                    title="Вставить столбец справа от текущего"
                    @click="editor.chain().focus().addColumnAfter().run()"
                >
                    Столбец →
                </button></template
            >
            ><button title="Ссылка" @click="linkDialog = true">
                <Icon name="Link" :size="17" /></button
            ><button title="Файл, аудио или видео" @click="mediaDialog = true">
                <Icon name="Paperclip" :size="17" /></button
            ><span class="toolbar-rule" />
            <div class="relative">
                <button @click="more = !more" class="add-widget">
                    <Icon name="Blocks" :size="16" />Виджет<Icon
                        name="ChevronDown"
                        :size="12"
                    />
                </button>
                <div v-if="more" class="popover">
                    <button
                        v-if="canAddCall"
                        @click="
                            more = false;
                            emit('add-call');
                        "
                    >
                        <Icon name="Phone" />Звонок с расшифровкой
                    </button>
                    <button
                        @click="
                            more = false;
                            emit('add-meeting');
                        "
                    >
                        <Icon name="Mic" />Планёрка без расшифровки
                    </button>
                    <button
                        @click="
                            more = false;
                            emit('add-password');
                        "
                    >
                        <Icon name="Vault" />Данные доступа
                    </button>
                    <button v-for="w in widgets" :key="w.id" @click="widget(w)">
                        <Icon name="Blocks" />{{ w.name }}
                    </button>
                    <p v-if="!widgets.length">
                        Создайте тип в разделе «Виджеты».
                    </p>
                </div>
            </div>
            <div class="toolbar-spacer" />
            <button
                title="Отменить"
                @click="editor.chain().focus().undo().run()"
            >
                <Icon name="Undo2" :size="16" />
            </button>
        </div>
        <EditorContent :editor="editor" class="document-content" />
    </div>
    <Modal
        v-if="mediaDialog"
        title="Добавить медиа"
        @close="mediaDialog = false"
        ><div class="modal-body">
            <label class="upload-zone"
                ><Icon name="Upload" :size="24" /><strong>{{
                    uploading ? "Загружаем…" : "Выберите файл"
                }}</strong
                ><span>Изображение, MP3, MP4, WebM или PDF · до 200 МБ</span
                ><input
                    type="file"
                    :disabled="uploading"
                    accept="image/png,image/jpeg,image/webp,image/gif,audio/mpeg,audio/wav,video/mp4,video/webm,application/pdf"
                    @change="upload" /></label
            ><label class="form-field"
                >Или ссылка на YouTube<input
                    v-model="url"
                    placeholder="https://youtube.com/watch?v=…" /></label
            ><button class="button primary" @click="youtube">
                Встроить видео
            </button>
        </div></Modal
    >
    <Modal v-if="linkDialog" title="Добавить ссылку" @close="linkDialog = false"
        ><form class="modal-body" @submit.prevent="link">
            <label class="form-field"
                >Адрес<input
                    v-model="url"
                    type="url"
                    required
                    placeholder="https://…"
                    autofocus /></label
            ><button class="button primary">Вставить ссылку</button>
        </form></Modal
    >
</template>
