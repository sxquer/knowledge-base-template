<script setup lang="ts">
import { ref } from "vue";
import type { Page } from "../types";
import { api } from "../api";
import Modal from "./Modal.vue";

const props = defineProps<{ page: Page }>();
const emit = defineEmits<{ close: []; created: [page: Page] }>();
const title = ref("");
const file = ref<File | null>(null);
const busy = ref(false);
const error = ref("");
let requestId = crypto.randomUUID();
let fingerprint = "";

function selected(event: Event) {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (file.value && !title.value)
        title.value = file.value.name.replace(/\.[^.]+$/, "");
}

async function submit() {
    if (busy.value || !file.value) return;
    busy.value = true;
    error.value = "";
    try {
        const nextFingerprint = JSON.stringify([
            props.page.id,
            props.page.revision,
            title.value.trim(),
            file.value.name,
            file.value.size,
            file.value.lastModified,
        ]);
        if (fingerprint && fingerprint !== nextFingerprint)
            requestId = crypto.randomUUID();
        fingerprint = nextFingerprint;
        const form = new FormData();
        form.append("file", file.value);
        form.append("title", title.value.trim());
        form.append("expected_revision", String(props.page.revision));
        form.append("request_id", requestId);
        const result = await api<{ page: Page }>(
            `/api/pages/${props.page.id}/meetings`,
            "POST",
            form,
        );
        emit("created", result.page);
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal title="Добавить планёрку" @close="!busy && emit('close')">
        <form class="modal-body" @submit.prevent="submit">
            <label class="form-field"
                >Название<input v-model="title" required maxlength="180"
            /></label>
            <label class="upload-zone"
                ><strong>{{ file?.name || "Выберите запись" }}</strong
                ><span>Аудио или видео MP4/WebM · до 200 МБ</span
                ><input
                    type="file"
                    required
                    :disabled="busy"
                    accept="audio/*,video/mp4,video/webm"
                    @change="selected"
            /></label>
            <p class="muted">Запись сохранится в статье без расшифровки.</p>
            <p v-if="error" class="call-error" role="alert">{{ error }}</p>
            <div class="modal-actions">
                <button
                    type="button"
                    class="button"
                    :disabled="busy"
                    @click="emit('close')"
                >
                    Отмена
                </button>
                <button class="button primary" :disabled="busy || !file">
                    {{ busy ? "Загружаем…" : "Добавить планёрку" }}
                </button>
            </div>
        </form>
    </Modal>
</template>
