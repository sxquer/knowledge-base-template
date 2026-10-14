<script setup lang="ts">
import {
    ref,
    computed,
    onMounted,
    onBeforeUnmount,
    provide,
    watch,
    nextTick,
} from "vue";
import { Head, router } from "@inertiajs/vue3";
import type { Page, Widget, DocNode } from "../types";
import { statuses, actions, date, paragraph } from "../types";
import { api, ApiError } from "../api";
import Icon from "../Components/Icon.vue";
import TreeNode from "../Components/TreeNode.vue";
import SectionsPanel from "../Components/SectionsPanel.vue";
import Modal from "../Components/Modal.vue";
import RichEditor from "../Components/RichEditor.vue";
import CallDialog from "../Components/CallDialog.vue";
import MeetingDialog from "../Components/MeetingDialog.vue";
import CallsPanel from "../Components/CallsPanel.vue";
import DocumentNode from "../Components/DocumentNode.vue";
import UpdatesPanel from "../Components/UpdatesPanel.vue";
import WidgetsPanel from "../Components/WidgetsPanel.vue";
import PasswordsPanel from "../Components/PasswordsPanel.vue";
import PasswordPicker from "../Components/PasswordPicker.vue";
import AuditPanel from "../Components/AuditPanel.vue";
import SettingsPanel from "../Components/SettingsPanel.vue";
const props = defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        role: string;
        can_view_calls: boolean;
        can_listen_calls: boolean;
    };
}>();
const canEdit = computed(() => ["admin", "editor"].includes(props.user.role));
const canViewCalls = computed(
    () => props.user.role === "admin" || props.user.can_view_calls,
);
const canListenCalls = computed(
    () =>
        props.user.role === "admin" ||
        (canViewCalls.value && props.user.can_listen_calls),
);
provide("canListenCalls", canListenCalls);
const pages = ref<Page[]>([]);
const widgets = ref<Widget[]>([]);
provide("widgets", widgets);
const page = ref<Page | null>(null);
const section = ref("document");
const loading = ref(true);
const mobileNav = ref(false);
const panel = ref("properties");
const error = ref("");
const toast = ref("");
const saving = ref(false);
const dirty = ref(false);
const failed = ref(false);
let sequence = 0;
let selectionSequence = 0;
const moving = ref(false);
const AUTO_SAVE_DELAY_MS = 5000;
let saveTimer: ReturnType<typeof setTimeout> | undefined;
let savePromise: Promise<boolean> | null = null;
let lastChangeAt = 0;
let forcePendingSave = false;
const searchOpen = ref(false);
const query = ref("");
const results = ref<any[]>([]);
const searchMode = ref("text");
const searchBusy = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let searchSeq = 0;
const createOpen = ref(false);
const newTitle = ref("");
const newParent = ref<string | null>(null);
const newKind = ref("article");
const creating = ref(false);
const shareOpen = ref(false);
const exportOpen = ref(false);
const callOpen = ref(false);
const meetingOpen = ref(false);
const passwordPickerOpen = ref(false);
const richEditor = ref<any>(null);
const indexed = ref(false);
const publicSlug = ref("");
const selectedChildren = ref<string[]>([]);
const publishing = ref(false);
const revisions = ref<any[]>([]);
const selectedRevision = ref<any>(null);
const historyOpen = ref(false);
const historyListOpen = ref(false);
const propertiesOpen = ref(false);
const archiveOpen = ref(false);
const logoutOpen = ref(false);
const rootDropActive = ref(false);
const roots = computed(() => pages.value.filter((p) => !p.parent_id));
const parent = computed(() =>
    pages.value.find((p) => p.id === page.value?.parent_id),
);
const headings = computed(
    () =>
        page.value?.document.content
            ?.filter((n) => n.type === "heading")
            .map((n) => ({
                id: n.attrs?.id,
                text: n.content?.map((t) => t.text ?? "").join(""),
            })) ?? [],
);
const children = computed(() =>
    pages.value.filter((p) => p.parent_id === page.value?.id),
);
const descendants = computed(() => {
    if (!page.value) return [];
    const found = new Set([page.value.id]);
    let changed = true;
    while (changed) {
        changed = false;
        for (const p of pages.value)
            if (p.parent_id && found.has(p.parent_id) && !found.has(p.id)) {
                found.add(p.id);
                changed = true;
            }
    }
    return pages.value.filter(
        (p) => p.id !== page.value?.id && found.has(p.id),
    );
});
const sectionTitles: Record<string, string> = {
    overview: "Обзор",
    sections: "Разделы базы знаний",
    updates: "Обновления",
    audit: "Проверка знаний",
    widgets: "Виджеты",
    calls: "Звонки",
    passwords: "Пароли",
    settings: "MCP и подключения",
};
const nav = computed(() => [
    { id: "overview", label: "Обзор", icon: "House" },
    { id: "updates", label: "Обновления", icon: "History" },
    { id: "audit", label: "Проверка знаний", icon: "ScanText" },
    { id: "widgets", label: "Виджеты", icon: "Blocks" },
    ...(canViewCalls.value
        ? [{ id: "calls", label: "Звонки", icon: "Phone" }]
        : []),
    { id: "passwords", label: "Пароли", icon: "Vault" },
]);
const initials = computed(() =>
    props.user.name
        .split(" ")
        .slice(0, 2)
        .map((s) => s[0])
        .join(""),
);
const publicUrl = computed(() =>
    page.value?.publication
        ? location.origin +
          "/p/" +
          (page.value.publication.slug || page.value.publication.id)
        : "",
);
function addSource(e: Event) {
    const target = e.target as HTMLFormElement;
    const f = new FormData(target);
    if (!page.value) return;
    page.value.sources ??= [];
    page.value.sources.push({
        title: String(f.get("title")),
        url: String(f.get("url")),
        checked_at: new Date().toISOString().slice(0, 10),
    });
    changed();
    target.reset();
}
function err(message: string) {
    error.value = message;
}
async function refresh() {
    const [loadedPages, loadedWidgets] = await Promise.all([
        api<Page[]>("/api/pages"),
        api<Widget[]>("/api/widgets"),
    ]);
    pages.value = loadedPages;
    widgets.value = loadedWidgets;
}
function updateTree(pageData: Page) {
    const index = pages.value.findIndex((item) => item.id === pageData.id);
    if (index >= 0) pages.value[index] = { ...pages.value[index], ...pageData };
    else pages.value.push(pageData);
}
function stash() {
    if (page.value)
        sessionStorage.setItem(
            `draft:${props.user.id}:${page.value.id}`,
            JSON.stringify({ page: page.value, base: page.value.revision }),
        );
}
function changed() {
    if (!canEdit.value) return;
    sequence++;
    dirty.value = true;
    failed.value = false;
    lastChangeAt = Date.now();
    stash();
    scheduleAutoSave();
}
function scheduleAutoSave() {
    clearTimeout(saveTimer);
    const remaining = Math.max(
        0,
        AUTO_SAVE_DELAY_MS - (Date.now() - lastChangeAt),
    );
    saveTimer = setTimeout(() => void save(false), remaining);
}
async function save(immediate = true): Promise<boolean> {
    clearTimeout(saveTimer);
    if (savePromise) {
        if (immediate) forcePendingSave = true;
        return savePromise;
    }
    if (!page.value || !dirty.value) return true;
    savePromise = (async () => {
        saving.value = true;
        try {
            while (dirty.value && page.value) {
                const saveImmediately = immediate || forcePendingSave;
                if (
                    !saveImmediately &&
                    Date.now() - lastChangeAt < AUTO_SAVE_DELAY_MS
                ) {
                    scheduleAutoSave();
                    break;
                }
                const current = page.value;
                const seq = sequence;
                const payload = {
                    ...JSON.parse(JSON.stringify(current)),
                    expected_revision: current.revision,
                    reason: "Редактирование в базе знаний",
                };
                const saved = await api<Page>(
                    "/api/pages/" + current.id,
                    "PUT",
                    payload,
                    { "Idempotency-Key": crypto.randomUUID() },
                );
                if (page.value?.id === current.id) {
                    page.value.revision = saved.revision;
                    page.value.updated_at = saved.updated_at;
                    page.value.publication = saved.publication;
                    page.value.suggested_slug = saved.suggested_slug;
                    const i = pages.value.findIndex((p) => p.id === saved.id);
                    if (i >= 0)
                        pages.value[i] = { ...pages.value[i], ...saved };
                    if (seq === sequence) {
                        dirty.value = false;
                        sessionStorage.removeItem(
                            `draft:${props.user.id}:${saved.id}`,
                        );
                    } else {
                        stash();
                        if (!saveImmediately) {
                            scheduleAutoSave();
                            break;
                        }
                    }
                }
            }
            if (!dirty.value) forcePendingSave = false;
            failed.value = false;
            return true;
        } catch (e: any) {
            failed.value = true;
            err(e.message);
            return false;
        } finally {
            saving.value = false;
            savePromise = null;
        }
    })();
    return savePromise;
}
async function select(id: string, push = true, loaded?: Page) {
    const request = ++selectionSequence;
    if (!(await save())) return;
    if (request !== selectionSequence) return;
    loading.value = true;
    error.value = "";
    mobileNav.value = false;
    try {
        const resolved = loaded ?? (await api<Page>("/api/pages/" + id));
        if (request !== selectionSequence) return;
        page.value = resolved;
        dirty.value = false;
        failed.value = false;
        const local = sessionStorage.getItem(`draft:${props.user.id}:${id}`);
        if (local) {
            try {
                const stored = JSON.parse(local);
                page.value = stored.page;
                dirty.value = true;
                if (stored.base !== resolved.revision) {
                    failed.value = true;
                    err(
                        "Есть несохранённые локальные правки, но серверная версия уже изменилась. Скачайте свои правки перед загрузкой новой версии.",
                    );
                } else {
                    toast.value = "Восстановлен несохранённый черновик.";
                }
            } catch {
                sessionStorage.removeItem(`draft:${props.user.id}:${id}`);
            }
        }
        section.value = "document";
        panel.value = "properties";
        if (push) history.pushState({}, "", `/app/${id}`);
        await nextTick();
        document.querySelector(".main-scroll")?.scrollTo(0, 0);
    } catch (e: any) {
        if (request === selectionSequence) err(e.message);
    } finally {
        if (request === selectionSequence) loading.value = false;
    }
}
function allowRootDrop(event: DragEvent) {
    if (!event.dataTransfer?.types.includes("application/x-kb-page")) return;
    event.preventDefault();
    rootDropActive.value = true;
    if (event.dataTransfer) event.dataTransfer.dropEffect = "move";
}
function leaveRootDrop() {
    rootDropActive.value = false;
}
async function moveInTree({
    id,
    parentId,
}: {
    id: string;
    parentId: string | null;
}) {
    rootDropActive.value = false;
    if (
        !canEdit.value ||
        moving.value ||
        id === parentId ||
        !pages.value.some((p) => p.id === id)
    )
        return;
    moving.value = true;
    try {
        if (!(await save())) return;
        const source =
            page.value?.id === id
                ? page.value
                : await api<Page>("/api/pages/" + id);
        if (source.parent_id === parentId) return;
        const saved = await api<Page>(
            "/api/pages/" + id,
            "PUT",
            {
                ...source,
                parent_id: parentId,
                expected_revision: source.revision,
                reason: parentId
                    ? "Перемещение в раздел через дерево базы"
                    : "Перемещение на верхний уровень через дерево базы",
            },
            { "Idempotency-Key": crypto.randomUUID() },
        );
        updateTree(saved);
        if (page.value?.id === saved.id) {
            // Keep edits typed while the move request was in flight.
            page.value.parent_id = saved.parent_id;
            page.value.revision = saved.revision;
            page.value.updated_at = saved.updated_at;
            if (dirty.value) stash();
        }
        toast.value = parentId
            ? "Материал вложен в раздел."
            : "Материал перемещён на верхний уровень.";
    } catch (e: any) {
        err(e.message);
    } finally {
        moving.value = false;
    }
}
function dropToRoot(event: DragEvent) {
    event.preventDefault();
    const id = event.dataTransfer?.getData("application/x-kb-page");
    if (id) void moveInTree({ id, parentId: null });
}
async function navigate(id: string) {
    if (id === "calls" && !canViewCalls.value) return;
    if (!(await save())) return;
    selectionSequence++;
    loading.value = false;
    section.value = id;
    mobileNav.value = false;
    history.pushState({}, "", `/app/${id}`);
    await nextTick();
    document.querySelector(".main-scroll")?.scrollTo(0, 0);
}
async function reload() {
    if (!page.value) return;
    sessionStorage.removeItem(`draft:${props.user.id}:${page.value.id}`);
    dirty.value = false;
    await select(page.value.id);
}
function exportDraft() {
    const blob = new Blob([JSON.stringify(page.value, null, 2)], {
        type: "application/json",
    });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "Мои правки.json";
    a.click();
    URL.revokeObjectURL(a.href);
}
function makePage() {
    newParent.value =
        page.value?.kind === "section"
            ? page.value.id
            : (page.value?.parent_id ?? null);
    newTitle.value = "";
    newKind.value = "article";
    createOpen.value = true;
}
async function create() {
    creating.value = true;
    try {
        const p = await api<Page>("/api/pages", "POST", {
            title: newTitle.value,
            parent_id: newParent.value,
            kind: newKind.value,
            document: { type: "doc", content: [paragraph("")] },
            status: "draft",
            reason: "Создание страницы",
        });
        updateTree(p);
        createOpen.value = false;
        await select(p.id, true, p);
    } catch (e: any) {
        err(e.message);
    } finally {
        creating.value = false;
    }
}
async function showHistory() {
    if (!(await save()) || !page.value) return;
    revisions.value = await api(`/api/pages/${page.value.id}/revisions`);
    panel.value = "history";
    selectedRevision.value = null;
    if (window.innerWidth <= 1050) historyListOpen.value = true;
}
async function restore() {
    if (!page.value || !selectedRevision.value) return;
    try {
        const result = await api<Page>(
            `/api/pages/${page.value.id}/restore/${selectedRevision.value.id}`,
            "POST",
            { expected_revision: page.value.revision },
        );
        page.value = result;
        dirty.value = false;
        historyOpen.value = false;
        await refresh();
        await showHistory();
        toast.value = "Версия восстановлена новой редакцией.";
    } catch (e: any) {
        err(e.message);
    }
}
async function showCall() {
    if (canEdit.value && canViewCalls.value && (await save()))
        callOpen.value = true;
}
async function showMeeting() {
    if (canEdit.value && (await save())) meetingOpen.value = true;
}
async function showPasswordPicker() {
    if (canEdit.value && (await save())) passwordPickerOpen.value = true;
}
function addPasswordReference(passwordEntryId: string) {
    richEditor.value?.insertPasswordReference(passwordEntryId);
    passwordPickerOpen.value = false;
}
async function callCreated(saved: Page) {
    page.value = saved;
    dirty.value = false;
    callOpen.value = false;
    sessionStorage.removeItem(`draft:${props.user.id}:${saved.id}`);
    await refresh();
    toast.value = "Звонок добавлен. Запись и расшифровка готовятся в фоне.";
}
async function meetingCreated(saved: Page) {
    page.value = saved;
    dirty.value = false;
    meetingOpen.value = false;
    sessionStorage.removeItem(`draft:${props.user.id}:${saved.id}`);
    await refresh();
    toast.value = "Запись планёрки добавлена без расшифровки.";
}
function callCompleted(event: Event) {
    const data = (event as CustomEvent).detail;
    if (
        page.value &&
        page.value.id === data.pageId &&
        page.value.revision + 1 === data.revision &&
        !saving.value
    ) {
        page.value.revision = data.revision;
        page.value.updated_at = data.updatedAt;
    }
}
async function showShare() {
    if (!canEdit.value && !page.value?.publication) return;
    if (canEdit.value && !(await save())) return;
    publicSlug.value =
        page.value?.publication?.slug || page.value?.suggested_slug || "";
    indexed.value = page.value?.publication?.indexed ?? false;
    selectedChildren.value = [];
    shareOpen.value = true;
}
async function showExport() {
    if (!page.value) return;
    if (canEdit.value && !(await save())) return;
    exportOpen.value = true;
}
function exportPage(format: "pdf" | "docx") {
    if (!page.value) return;
    const link = document.createElement("a");
    link.href = `/api/pages/${page.value.id}/export/${format}`;
    link.download = "";
    document.body.appendChild(link);
    link.click();
    link.remove();
}
async function publish() {
    if (!canEdit.value || !page.value) return;
    publishing.value = true;
    try {
        const result = await api(
            `/api/pages/${page.value.id}/publish`,
            "POST",
            {
                expected_revision: page.value.revision,
                indexed: indexed.value,
                slug: publicSlug.value,
                children: descendants.value
                    .filter((p) => selectedChildren.value.includes(p.id))
                    .map((p) => ({ id: p.id, revision: p.revision })),
            },
        );
        page.value = result.page;
        await refresh();
        toast.value = "Редакция опубликована. Ссылка готова.";
    } catch (e: any) {
        err(e.message);
    } finally {
        publishing.value = false;
    }
}
async function unpublish() {
    if (!canEdit.value || !page.value) return;
    try {
        page.value = await api(
            `/api/pages/${page.value.id}/publish`,
            "DELETE",
            { expected_revision: page.value.revision },
        );
        toast.value = "Публичный доступ закрыт.";
        await refresh();
    } catch (e: any) {
        err(e.message);
    }
}
async function archive() {
    if (!page.value) return;
    page.value.status = "archived";
    changed();
    if (await save()) {
        archiveOpen.value = false;
        await refresh();
        page.value = null;
        section.value = "overview";
        toast.value = "Материал перемещён в архив. История сохранена.";
    }
}
async function copyPublic() {
    try {
        await navigator.clipboard.writeText(publicUrl.value);
        toast.value = "Ссылка скопирована";
    } catch {
        err("Скопируйте ссылку из поля.");
    }
}
function goHeading(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: "smooth", block: "start" });
}
watch(query, () => {
    clearTimeout(searchTimer);
    const seq = ++searchSeq;
    searchTimer = setTimeout(async () => {
        if (!query.value.trim()) {
            results.value = [];
            return;
        }
        searchBusy.value = true;
        try {
            const r = await api(
                "/api/search?q=" + encodeURIComponent(query.value),
            );
            if (seq === searchSeq) {
                results.value = r.results;
                searchMode.value = r.mode;
                if (r.warning) toast.value = r.warning;
            }
        } catch (e: any) {
            if (seq === searchSeq) err(e.message);
        } finally {
            if (seq === searchSeq) searchBusy.value = false;
        }
    }, 250);
});
function keydown(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key === "k") {
        e.preventDefault();
        searchOpen.value = !searchOpen.value;
    }
    if ((e.metaKey || e.ctrlKey) && e.key === "s") {
        e.preventDefault();
        void save();
    }
}
function unload(e: BeforeUnloadEvent) {
    if (dirty.value) {
        e.preventDefault();
        e.returnValue = "";
    }
}
async function pop() {
    const id = location.pathname.split("/")[2];
    if (id === "calls" && !canViewCalls.value) section.value = "overview";
    else if (sectionTitles[id]) section.value = id;
    else if (id) await select(id, false);
    else section.value = "overview";
}
onMounted(async () => {
    window.addEventListener("kb-call-completed", callCompleted);
    document.addEventListener("keydown", keydown);
    window.addEventListener("beforeunload", unload);
    window.addEventListener("popstate", pop);
    try {
        const id = location.pathname.split("/")[2];
        const initialPage =
            id && !sectionTitles[id] ? api<Page>("/api/pages/" + id) : null;
        const [, loaded] = await Promise.all([refresh(), initialPage]);
        if (id === "calls" && !canViewCalls.value) section.value = "overview";
        else if (sectionTitles[id]) section.value = id;
        else if (id && loaded) await select(id, false, loaded);
        else section.value = "overview";
    } catch (e: any) {
        err(e.message);
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => {
    clearTimeout(saveTimer);
    clearTimeout(searchTimer);
    window.removeEventListener("kb-call-completed", callCompleted);
    document.removeEventListener("keydown", keydown);
    window.removeEventListener("beforeunload", unload);
    window.removeEventListener("popstate", pop);
});
</script>
<template>
    <Head
        :title="
            section === 'document'
                ? (page?.title ?? 'База знаний')
                : sectionTitles[section]
        "
    />
    <div class="workspace">
        <div v-if="mobileNav" class="nav-scrim" @click="mobileNav = false" />
        <aside :class="['sidebar', { open: mobileNav }]">
            <div class="sidebar-brand">
                <a
                    href="/app"
                    @click.prevent="navigate('overview')"
                    class="brand"
                    >База<span>знаний</span><i /></a
                ><span>База знаний</span>
            </div>
            <button class="search-launch" @click="searchOpen = true">
                <Icon name="Search" /><span>Поиск по базе знаний</span
                ><kbd>⌘ K</kbd>
            </button>
            <nav class="primary-nav">
                <button
                    v-for="n in nav"
                    :class="{ active: section === n.id }"
                    @click="navigate(n.id)"
                >
                    <Icon :name="n.icon" />{{ n.label }}
                </button>
            </nav>
            <div
                :class="[
                    'section-label',
                    { 'root-drop-active': rootDropActive },
                ]"
                :title="
                    canEdit
                        ? 'Перетащите сюда, чтобы вынести на верхний уровень'
                        : undefined
                "
                @dragover="canEdit && allowRootDrop($event)"
                @dragleave="leaveRootDrop"
                @drop="canEdit && dropToRoot($event)"
            >
                <button
                    class="sections-nav-link"
                    :class="{ active: section === 'sections' }"
                    :aria-current="section === 'sections' ? 'page' : undefined"
                    @click="navigate('sections')"
                    title="Открыть всю структуру базы"
                >
                    {{
                        rootDropActive
                            ? "Отпустите — в корень базы"
                            : "Разделы базы знаний"
                    }}<Icon name="ArrowUpRight" :size="13" /></button
                ><button
                    v-if="canEdit"
                    class="icon-button"
                    title="Новая страница"
                    @click="makePage"
                >
                    <Icon name="Plus" :size="16" />
                </button>
            </div>
            <nav class="tree">
                <TreeNode
                    v-for="p in roots"
                    :key="p.id"
                    :page="p"
                    :pages="pages"
                    :selected="section === 'document' ? (page?.id ?? '') : ''"
                    :can-edit="canEdit"
                    @select="select"
                    @move="moveInTree"
                />
                <div
                    v-if="canEdit"
                    :class="['tree-root-drop', { active: rootDropActive }]"
                    @dragover="allowRootDrop"
                    @dragleave="leaveRootDrop"
                    @drop="dropToRoot"
                >
                    Перетащите сюда, чтобы вынести на верхний уровень
                </div>
                <p v-if="!roots.length && !loading" class="sidebar-empty">
                    Разделов пока нет.<br />Создайте первую страницу.
                </p>
            </nav>
            <div class="sidebar-bottom">
                <button
                    :class="[
                        'connection-link',
                        { active: section === 'settings' },
                    ]"
                    @click="navigate('settings')"
                >
                    <Icon name="Cable" />MCP и подключения<Icon
                        name="ChevronRight"
                        :size="14"
                    /></button
                ><button class="account" @click="logoutOpen = true">
                    <span class="avatar">{{ initials }}</span
                    ><span
                        ><strong>{{ user.name }}</strong
                        ><small>{{
                            user.role === "admin"
                                ? "Администратор"
                                : user.role === "editor"
                                  ? "Редактор"
                                  : "Читатель"
                        }}</small></span
                    ><Icon name="ChevronDown" :size="14" />
                </button>
            </div>
        </aside>
        <div class="workspace-body">
            <header class="topbar">
                <button
                    class="icon-button mobile-menu"
                    aria-label="Открыть меню"
                    @click="mobileNav = true"
                >
                    <Icon name="Menu" />
                </button>
                <div class="breadcrumbs" v-if="section === 'document' && page">
                    <button v-if="parent" @click="select(parent.id)">
                        {{ parent.title }}</button
                    ><span v-if="parent">/</span
                    ><strong>{{ page.title }}</strong>
                </div>
                <strong v-else class="topbar-title">{{
                    sectionTitles[section]
                }}</strong>
                <div
                    class="topbar-actions"
                    v-if="section === 'document' && page"
                >
                    <button
                        class="icon-button mobile-properties"
                        aria-label="Свойства страницы"
                        @click="propertiesOpen = true"
                    >
                        <Icon name="SlidersHorizontal" /></button
                    ><span :class="['save-status', { error: failed }]">{{
                        failed
                            ? "Не сохранено"
                            : saving
                              ? "Сохраняем…"
                              : dirty
                                ? "Есть правки"
                                : "Сохранено"
                    }}</span
                    ><button
                        class="button quiet"
                        aria-label="Скачать"
                        @click="showExport"
                    >
                        <Icon name="Download" /><span>Скачать</span></button
                    ><button
                        class="button quiet"
                        aria-label="История"
                        @click="showHistory"
                    >
                        <Icon name="History" /><span>История</span></button
                    ><button
                        class="button quiet"
                        v-if="canEdit || page.publication"
                        aria-label="Поделиться"
                        @click="showShare"
                    >
                        <Icon name="Share2" /><span>Поделиться</span></button
                    ><button
                        v-if="canEdit"
                        class="button primary"
                        @click="showShare"
                    >
                        {{
                            page.publication
                                ? "Обновить публикацию"
                                : "Опубликовать"
                        }}
                    </button>
                </div>
            </header>
            <div v-if="error" class="global-error" role="alert">
                <Icon name="CircleAlert" /><span>{{ error }}</span
                ><template v-if="failed"
                    ><button @click="exportDraft">Скачать мои правки</button
                    ><button @click="reload">
                        Загрузить серверную версию
                    </button></template
                ><button
                    class="icon-button"
                    @click="error = ''"
                    aria-label="Закрыть ошибку"
                >
                    <Icon name="X" :size="15" />
                </button>
            </div>
            <div v-if="toast" class="toast" role="status">
                <Icon name="Check" :size="16" />{{ toast
                }}<button
                    class="icon-button"
                    @click="toast = ''"
                    aria-label="Закрыть уведомление"
                >
                    <Icon name="X" :size="14" />
                </button>
            </div>
            <div
                :class="[
                    'content-layout',
                    { 'with-properties': section === 'document' && page },
                ]"
            >
                <main class="main-scroll">
                    <SectionsPanel
                        v-show="!loading && section === 'sections'"
                        :pages="pages"
                        :can-edit="canEdit"
                        :moving="moving"
                        @open="select"
                        @move="moveInTree"
                    />
                    <div v-if="loading" class="loading-state">
                        <Icon name="LoaderCircle" class="spin" />Загружаем базу…
                    </div>
                    <article
                        v-else-if="section === 'document' && page"
                        class="document"
                        :key="page.id"
                    >
                        <Icon
                            :name="
                                page.kind === 'section' ? 'Folder' : 'FileText'
                            "
                            :size="40"
                            class="document-symbol"
                        /><textarea
                            v-if="canEdit"
                            class="title-input"
                            v-model="page.title"
                            @input="changed"
                            aria-label="Название статьи"
                            rows="2"
                            maxlength="220"
                        />
                        <h1 v-else>{{ page.title }}</h1>
                        <input
                            v-if="canEdit"
                            class="subtitle-input"
                            v-model="page.subtitle"
                            @input="changed"
                            aria-label="Описание статьи"
                            placeholder="Добавьте короткое описание"
                        />
                        <p v-else class="subtitle">{{ page.subtitle }}</p>
                        <div class="document-meta">
                            <span
                                ><i :class="['status-dot', page.status]" />{{
                                    statuses[page.status]
                                }}</span
                            ><span
                                ><Icon name="Users" :size="14" />{{
                                    page.owner_label
                                }}</span
                            ><span
                                ><Icon name="Files" :size="14" />Версия
                                {{ page.revision }}</span
                            >
                        </div>
                        <RichEditor
                            ref="richEditor"
                            :model-value="page.document"
                            :editable="canEdit"
                            :page-id="page.id"
                            :widgets="widgets"
                            :can-add-call="canViewCalls"
                            @update:model-value="
                                page.document = $event;
                                changed();
                            "
                            @error="err"
                            @add-call="showCall"
                            @add-meeting="showMeeting"
                            @add-password="showPasswordPicker"
                        />
                        <div class="child-pages" v-if="children.length">
                            <button
                                v-for="child in children"
                                @click="select(child.id)"
                            >
                                <Icon
                                    :name="
                                        child.kind === 'section'
                                            ? 'Folder'
                                            : 'FileText'
                                    "
                                />{{ child.title
                                }}<Icon name="ArrowUpRight" :size="15" />
                            </button>
                        </div>
                        <button
                            v-if="page.kind === 'section' && canEdit"
                            class="button add-child"
                            @click="makePage"
                        >
                            <Icon name="Plus" />Добавить вложенную страницу
                        </button>
                        <footer class="document-footer">
                            <span>{{
                                page.sources?.length
                                    ? "Материал связан с источниками"
                                    : "Добавьте источники в свойствах документа"
                            }}</span
                            ><span>Изменено {{ date(page.updated_at) }}</span>
                        </footer>
                    </article>
                    <section
                        v-else-if="section === 'overview'"
                        class="feature-page"
                    >
                        <div class="feature-icon">
                            <Icon name="BookOpen" :size="32" />
                        </div>
                        <div class="heading-actions">
                            <h1>База знаний</h1>
                            <button
                                v-if="canEdit"
                                class="button primary"
                                @click="makePage"
                            >
                                <Icon name="Plus" />Новая страница
                            </button>
                        </div>
                        <p class="subtitle">
                            Рабочие знания команды. С контекстом, источниками и
                            историей.
                        </p>
                        <div class="overview-stats">
                            <div>
                                <strong>{{
                                    pages.filter((p) => p.kind === "article")
                                        .length
                                }}</strong
                                ><span>статей</span>
                            </div>
                            <div>
                                <strong>{{
                                    pages.filter((p) => p.kind === "section")
                                        .length
                                }}</strong
                                ><span>разделов</span>
                            </div>
                            <div>
                                <strong>{{
                                    pages.filter((p) => p.status === "review")
                                        .length
                                }}</strong
                                ><span>на проверке</span>
                            </div>
                        </div>
                        <h2>Последние изменения</h2>
                        <button
                            v-for="p in [...pages]
                                .sort((a, b) =>
                                    b.updated_at.localeCompare(a.updated_at),
                                )
                                .slice(0, 12)"
                            class="catalog-row"
                            @click="select(p.id)"
                        >
                            <Icon
                                :name="
                                    p.kind === 'section' ? 'Folder' : 'FileText'
                                "
                            /><span
                                ><strong>{{ p.title }}</strong
                                ><small>{{ statuses[p.status] }}</small></span
                            ><span class="muted mono">{{
                                date(p.updated_at)
                            }}</span
                            ><Icon name="ArrowUpRight" :size="16" />
                        </button>
                        <div v-if="!pages.length" class="empty-state">
                            <Icon name="FolderPlus" :size="34" />
                            <h3>Начните с первого раздела</h3>
                            <p>
                                Добавьте страницу или выполните импорт
                                существующих материалов.
                            </p>
                            <button
                                v-if="canEdit"
                                class="button dark"
                                @click="makePage"
                            >
                                Создать страницу
                            </button>
                        </div>
                    </section>
                    <UpdatesPanel
                        v-else-if="section === 'updates'"
                        :can-edit="canEdit"
                        @open="refresh().then(() => select($event))"
                        @error="err"
                    /><WidgetsPanel
                        v-else-if="section === 'widgets'"
                        :widgets="widgets"
                        :can-edit="canEdit"
                        @refresh="refresh"
                        @error="err"
                    /><CallsPanel
                        v-else-if="section === 'calls'"
                        :admin="user.role === 'admin'"
                        :can-edit="canEdit"
                        :can-listen="canListenCalls"
                    /><PasswordsPanel
                        v-else-if="section === 'passwords'"
                        :admin="user.role === 'admin'"
                        @error="err"
                    /><AuditPanel
                        v-else-if="section === 'audit'"
                        @open="select"
                        @error="err"
                    /><SettingsPanel
                        v-else-if="section === 'settings'"
                        :admin="user.role === 'admin'"
                        @error="err"
                    />
                </main>
                <aside class="properties" v-if="section === 'document' && page">
                    <div class="property-tabs">
                        <button
                            :class="{ active: panel === 'properties' }"
                            @click="panel = 'properties'"
                        >
                            Свойства</button
                        ><button
                            :class="{ active: panel === 'history' }"
                            @click="showHistory"
                        >
                            История
                        </button>
                    </div>
                    <template v-if="panel === 'properties'"
                        ><div class="property-fields">
                            <label
                                ><Icon name="CircleDot" /><span>Статус</span
                                ><select
                                    v-model="page.status"
                                    :disabled="!canEdit"
                                    @change="changed"
                                >
                                    <option value="draft">Черновик</option>
                                    <option value="review">На проверке</option>
                                    <option value="current">Актуально</option>
                                </select></label
                            ><label
                                ><Icon name="Users" /><span>Владелец</span
                                ><input
                                    v-model="page.owner_label"
                                    :disabled="!canEdit"
                                    @change="changed" /></label
                            ><label
                                ><Icon name="LockKeyhole" /><span>Доступ</span
                                ><select
                                    v-model="page.visibility"
                                    :disabled="!canEdit"
                                    @change="changed"
                                >
                                    <option value="team">Команда</option>
                                    <option value="restricted">
                                        Автор и админ
                                    </option>
                                </select></label
                            ><label
                                ><Icon name="Folder" /><span>Раздел</span
                                ><select
                                    v-model="page.parent_id"
                                    :disabled="!canEdit"
                                    @change="changed"
                                >
                                    <option :value="null">Корень базы</option>
                                    <option
                                        v-for="p in pages.filter(
                                            (p) =>
                                                p.id !== page?.id &&
                                                !descendants.some(
                                                    (d) => d.id === p.id,
                                                ),
                                        )"
                                        :value="p.id"
                                    >
                                        {{ p.title }}
                                    </option>
                                </select></label
                            ><label
                                ><Icon name="Tag" /><span>Область</span
                                ><input
                                    v-model="page.area"
                                    placeholder="Не указана"
                                    :disabled="!canEdit"
                                    @change="changed"
                            /></label>
                        </div>
                        <div class="source-list">
                            <h3>
                                <Icon name="Database" :size="15" />Источники
                            </h3>
                            <div
                                v-for="(source, i) in page.sources"
                                class="source-entry"
                            >
                                <a
                                    v-if="source.url"
                                    :href="source.url"
                                    target="_blank"
                                    rel="noopener"
                                    >{{ source.title
                                    }}<Icon name="ArrowUpRight" :size="12" /></a
                                ><span v-else>{{ source.title }}</span
                                ><button
                                    v-if="canEdit"
                                    class="icon-button"
                                    @click="
                                        page.sources.splice(i, 1);
                                        changed();
                                    "
                                    aria-label="Удалить источник"
                                >
                                    <Icon name="X" :size="12" />
                                </button>
                            </div>
                            <details v-if="canEdit">
                                <summary>Добавить источник</summary>
                                <form @submit.prevent="addSource">
                                    <input
                                        name="title"
                                        placeholder="Название"
                                        required
                                        aria-label="Название источника"
                                    /><input
                                        name="url"
                                        type="url"
                                        required
                                        placeholder="https://…"
                                        aria-label="Ссылка на источник"
                                    /><button class="button small">
                                        Добавить
                                    </button>
                                </form>
                            </details>
                        </div>
                        <nav class="page-toc" v-if="headings.length">
                            <h3>
                                <Icon name="List" :size="16" />На этой странице
                            </h3>
                            <button
                                v-for="h in headings"
                                @click="goHeading(h.id)"
                            >
                                {{ h.text }}
                            </button>
                        </nav>
                        <div v-if="page.publication" class="publication-info">
                            <Icon name="Globe" :size="16" /><span
                                >Есть публичная редакция</span
                            ><a :href="publicUrl" target="_blank" rel="noopener"
                                ><Icon name="ArrowUpRight" :size="16"
                            /></a>
                        </div>
                        <button
                            v-if="canEdit"
                            class="archive-link"
                            @click="archiveOpen = true"
                        >
                            <Icon name="Archive" :size="15" />В архив
                        </button></template
                    >
                    <div v-else class="revision-list">
                        <p class="muted">
                            Каждое изменение сохранено. Возврат создаёт новую
                            версию.
                        </p>
                        <button
                            v-for="rev in revisions"
                            @click="
                                selectedRevision = rev;
                                historyOpen = true;
                            "
                        >
                            <span class="revision-number"
                                >v{{ rev.number }}</span
                            ><span
                                ><strong>{{
                                    actions[rev.action] ?? rev.action
                                }}</strong
                                ><small>{{ date(rev.created_at) }}</small
                                ><small>{{ rev.reason }}</small></span
                            ><Icon name="ChevronRight" :size="14" />
                        </button>
                    </div>
                </aside>
            </div>
        </div>
    </div>
    <Modal
        v-if="searchOpen"
        title="Поиск по базе"
        wide
        @close="searchOpen = false"
        ><div class="search-dialog">
            <div class="search-input">
                <Icon name="Search" /><input
                    v-model="query"
                    placeholder="Что хотите найти?"
                    autofocus
                /><Icon v-if="searchBusy" name="LoaderCircle" class="spin" />
            </div>
            <span class="search-hint"
                >{{
                    searchMode === "hybrid"
                        ? "По словам и смыслу"
                        : "Поиск по словам"
                }}
                · только доступные вам материалы</span
            ><button
                v-for="result in results"
                class="search-result"
                @click="
                    searchOpen = false;
                    select(result.id);
                "
            >
                <Icon name="FileText" /><span
                    ><strong>{{ result.title }}</strong
                    ><small>{{ result.snippet }}</small></span
                ><Icon name="ArrowUpRight" :size="16" />
            </button>
            <p
                class="search-empty"
                v-if="query && !searchBusy && !results.length"
            >
                Совпадений нет. Попробуйте другие слова.
            </p>
            <p class="search-empty" v-if="!query">
                Название, фрагмент текста или вопрос своими словами.
            </p>
        </div></Modal
    >
    <Modal v-if="createOpen" title="Новая страница" @close="createOpen = false"
        ><form class="modal-body" @submit.prevent="create">
            <label class="form-field"
                >Название<input
                    v-model="newTitle"
                    required
                    autofocus
                    maxlength="220"
                    placeholder="О чём этот материал?"
            /></label>
            <div class="form-grid">
                <label class="form-field"
                    >Тип<select v-model="newKind">
                        <option value="article">Статья</option>
                        <option value="section">Раздел</option>
                    </select></label
                ><label class="form-field"
                    >Расположение<select v-model="newParent">
                        <option :value="null">Корень базы</option>
                        <option v-for="p in pages" :value="p.id">
                            {{ p.title }}
                        </option>
                    </select></label
                >
            </div>
            <p class="muted">
                Новая страница создаётся как внутренний черновик.
            </p>
            <div class="modal-actions">
                <button class="button primary" :disabled="creating">
                    Создать страницу
                </button>
            </div>
        </form></Modal
    >
    <CallDialog
        v-if="callOpen && page"
        :page="page"
        @close="callOpen = false"
        @created="callCreated"
    />
    <MeetingDialog
        v-if="meetingOpen && page"
        :page="page"
        @close="meetingOpen = false"
        @created="meetingCreated"
    />
    <PasswordPicker
        v-if="passwordPickerOpen"
        @close="passwordPickerOpen = false"
        @select="addPasswordReference"
    />
    <Modal v-if="exportOpen && page" title="Скачать" @close="exportOpen = false"
        ><div class="modal-body">
            <div class="share-preview">
                <Icon name="Download" :size="24" />
                <div>
                    <strong>{{ page.title }}</strong
                    ><span v-if="page.kind === 'section'"
                        >Раздел и все вложенные статьи будут собраны в один файл
                        с оглавлением.</span
                    ><span v-else
                        >Будет выгружена текущая сохранённая версия
                        статьи.</span
                    >
                </div>
            </div>
            <p class="muted">
                PDF удобен для чтения и печати, DOCX — для дальнейшего
                редактирования в Word.
            </p>
            <div class="modal-actions">
                <button class="button" @click="exportPage('docx')">
                    <Icon name="FileText" />DOCX</button
                ><button class="button primary" @click="exportPage('pdf')">
                    <Icon name="Download" />PDF
                </button>
            </div>
        </div></Modal
    >
    <Modal
        v-if="shareOpen && page"
        :title="canEdit ? 'Публикация страницы' : 'Публичная ссылка'"
        @close="shareOpen = false"
        ><div class="modal-body">
            <div class="share-preview">
                <Icon
                    :name="page.kind === 'section' ? 'Folder' : 'FileText'"
                    :size="24"
                />
                <div>
                    <strong>{{ page.title }}</strong
                    ><span>{{
                        canEdit
                            ? "Будет опубликована текущая сохранённая редакция."
                            : "Доступна опубликованная редакция страницы."
                    }}</span>
                </div>
            </div>
            <label v-if="canEdit" class="check-row"
                ><input
                    type="checkbox"
                    v-model="indexed"
                    :disabled="!canEdit"
                /><span>Разрешить индексацию поисковиками</span></label
            >
            <p class="muted">
                Без индексации страница всё равно доступна любому, у кого есть
                ссылка. Черновые правки не обновляют публикацию автоматически.
            </p>
            <label v-if="canEdit" class="field">
                <span>Адрес публичной страницы</span>
                <div class="copy-field">
                    <span>/p/</span
                    ><input
                        v-model="publicSlug"
                        aria-label="Адрес публичной страницы"
                        maxlength="180"
                        placeholder="nazvanie-stranitsy"
                    />
                </div>
                <small class="muted"
                    >Латинские буквы, цифры и дефисы. При смене адреса прежняя
                    ссылка перестанет открываться.</small
                >
            </label>
            <div v-if="descendants.length && canEdit" class="publish-children">
                <h3>Вложенные страницы</h3>
                <p class="muted">Выберите, какие из них тоже открыть.</p>
                <label v-for="child in descendants" class="check-row"
                    ><input
                        type="checkbox"
                        :value="child.id"
                        v-model="selectedChildren"
                    /><span
                        >{{ child.title }}
                        <small>v{{ child.revision }}</small></span
                    ></label
                >
            </div>
            <div v-if="publicUrl" class="copy-field">
                <input
                    :value="publicUrl"
                    readonly
                    aria-label="Публичная ссылка"
                /><button
                    class="icon-button"
                    @click="copyPublic"
                    aria-label="Скопировать публичную ссылку"
                >
                    <Icon name="Copy" /></button
                ><a
                    :href="publicUrl"
                    target="_blank"
                    rel="noopener"
                    aria-label="Открыть публичную страницу"
                    ><Icon name="ArrowUpRight"
                /></a>
            </div>
            <div class="modal-actions" v-if="canEdit">
                <button
                    v-if="page.publication"
                    class="button"
                    @click="unpublish"
                >
                    Снять публикацию</button
                ><button
                    class="button primary"
                    :disabled="publishing"
                    @click="publish"
                >
                    {{
                        publishing
                            ? "Публикуем…"
                            : page.publication
                              ? "Опубликовать изменения"
                              : "Опубликовать"
                    }}
                </button>
            </div>
        </div></Modal
    >
    <Modal
        v-if="historyOpen && selectedRevision"
        :title="'Редакция ' + selectedRevision.number"
        wide
        @close="historyOpen = false"
        ><div class="modal-body revision-preview">
            <p class="muted">
                {{ date(selectedRevision.created_at) }} ·
                {{ selectedRevision.reason }}
            </p>
            <h1>{{ selectedRevision.snapshot.title }}</h1>
            <DocumentNode
                class="document-content"
                :node="selectedRevision.snapshot.document"
                :widgets="widgets"
            />
            <div class="modal-actions">
                <button v-if="canEdit" class="button dark" @click="restore">
                    Восстановить эту редакцию
                </button>
            </div>
        </div></Modal
    >
    <Modal
        v-if="archiveOpen"
        title="Переместить в архив?"
        @close="archiveOpen = false"
        ><div class="modal-body">
            <p>
                Статья исчезнет из актуального поиска. Публикация будет снята,
                история останется доступна.
            </p>
            <div class="modal-actions">
                <button class="button" @click="archiveOpen = false">
                    Отмена</button
                ><button class="button dark" @click="archive">В архив</button>
            </div>
        </div></Modal
    >

    <Modal
        v-if="historyListOpen"
        title="История страницы"
        @close="historyListOpen = false"
        ><div class="modal-body revision-list">
            <button
                v-for="rev in revisions"
                @click="
                    selectedRevision = rev;
                    historyListOpen = false;
                    historyOpen = true;
                "
            >
                <span class="revision-number">v{{ rev.number }}</span
                ><span
                    ><strong>{{ actions[rev.action] ?? rev.action }}</strong
                    ><small>{{ date(rev.created_at) }}</small
                    ><small>{{ rev.reason }}</small></span
                >
            </button>
        </div></Modal
    >
    <Modal
        v-if="propertiesOpen && page"
        title="Свойства страницы"
        @close="propertiesOpen = false"
        ><div class="modal-body">
            <label class="form-field"
                >Статус<select
                    v-model="page.status"
                    :disabled="!canEdit"
                    @change="changed"
                >
                    <option value="draft">Черновик</option>
                    <option value="review">На проверке</option>
                    <option value="current">Актуально</option>
                </select></label
            ><label class="form-field"
                >Владелец<input
                    v-model="page.owner_label"
                    :disabled="!canEdit"
                    @change="changed" /></label
            ><label class="form-field"
                >Доступ<select
                    v-model="page.visibility"
                    :disabled="!canEdit"
                    @change="changed"
                >
                    <option value="team">Команда</option>
                    <option value="restricted">Автор и админ</option>
                </select></label
            ><label class="form-field"
                >Раздел<select
                    v-model="page.parent_id"
                    :disabled="!canEdit"
                    @change="changed"
                >
                    <option :value="null">Корень базы</option>
                    <option
                        v-for="p in pages.filter(
                            (p) =>
                                p.id !== page?.id &&
                                !descendants.some((d) => d.id === p.id),
                        )"
                        :value="p.id"
                    >
                        {{ p.title }}
                    </option>
                </select></label
            >
            <div class="modal-actions">
                <button
                    class="button primary"
                    @click="
                        async () => {
                            if (await save()) propertiesOpen = false;
                        }
                    "
                >
                    Готово
                </button>
            </div>
        </div></Modal
    >
    <Modal v-if="logoutOpen" title="Учётная запись" @close="logoutOpen = false"
        ><div class="modal-body">
            <h3>{{ user.name }}</h3>
            <p>{{ user.email }}</p>
            <button
                class="button"
                @click="
                    async () => {
                        if (await save()) router.post('/logout');
                    }
                "
            >
                Выйти
            </button>
        </div></Modal
    >
</template>
