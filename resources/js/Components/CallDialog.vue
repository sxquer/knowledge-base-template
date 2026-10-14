<script setup lang="ts">
import { ref, onMounted } from "vue";
import type { Page } from "../types";
import { api } from "../api";
import Modal from "./Modal.vue";
const props = defineProps<{ page: Page }>();
const emit = defineEmits<{ close: []; created: [page: Page] }>();
const mode = ref("reference");
const title = ref("Звонок");
const source = ref("");
const reference = ref("");
const query = ref("");
const calls = ref<any[]>([]);
const busy = ref(false);
const error = ref("");
let requestId: string = crypto.randomUUID();
let fingerprint = "";
async function find() {
    try {
        const result: any = await api(
            `/api/calls?state=transcribed&q=${encodeURIComponent(query.value)}`,
        );
        calls.value = result.data;
    } catch (e: any) {
        error.value = e.message;
    }
}
function recordingId() {
    const value = reference.value.trim();
    if (/^[a-f0-9-]{36}$/i.test(value)) return value;
    const url = new URL(value, location.origin);
    if (
        url.origin !== location.origin ||
        url.pathname !== "/app/calls" ||
        !url.searchParams.get("call")
    )
        throw new Error(
            "Вставьте внутреннюю ссылку на звонок из этой системы.",
        );
    return url.searchParams.get("call");
}
async function submit() {
    if (busy.value) return;
    busy.value = true;
    error.value = "";
    try {
        const data =
            mode.value === "reference"
                ? { recording_id: recordingId() }
                : {
                      title: title.value.trim(),
                      source_url: source.value.trim(),
                  };
        const nextFingerprint = JSON.stringify({
            ...data,
            expected_revision: props.page.revision,
        });
        if (fingerprint && fingerprint !== nextFingerprint)
            requestId = crypto.randomUUID();
        fingerprint = nextFingerprint;
        const result = await api<{ page: Page }>(
            `/api/pages/${props.page.id}/${mode.value === "reference" ? "call-references" : "calls"}`,
            "POST",
            {
                ...data,
                expected_revision: props.page.revision,
                request_id: requestId,
            },
        );
        source.value = "";
        emit("created", result.page);
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
onMounted(find);
</script>
<template>
    <Modal title="Добавить звонок" @close="!busy && emit('close')">
        <form class="modal-body" @submit.prevent="submit">
            <div class="call-toolbar">
                <button
                    type="button"
                    class="button"
                    :class="{ primary: mode === 'reference' }"
                    @click="mode = 'reference'"
                >
                    Из раздела «Звонки»</button
                ><button
                    type="button"
                    class="button"
                    :class="{ primary: mode === 'new' }"
                    @click="mode = 'new'"
                >
                    Новая запись по ссылке
                </button>
            </div>
            <template v-if="mode === 'reference'">
                <label class="form-field"
                    >Ссылка на звонок из системы<input
                        v-model="reference"
                        required
                        placeholder="https://…/app/calls?call=…"
                /></label>
                <div class="call-toolbar">
                    <input
                        v-model="query"
                        placeholder="Поиск по названию"
                        aria-label="Поиск разобранного звонка"
                    /><button type="button" class="button" @click="find">
                        Найти
                    </button>
                </div>
                <label class="form-field"
                    >Или выберите из списка<select
                        @change="
                            reference = ($event.target as HTMLSelectElement)
                                .value
                        "
                    >
                        <option value="">Выберите звонок</option>
                        <option
                            v-for="call in calls"
                            :key="call.id"
                            :value="call.id"
                        >
                            {{ call.title }} ·
                            {{
                                call.score == null
                                    ? "без общего балла"
                                    : call.score + "/100"
                            }}
                        </option>
                    </select></label
                >
                <p class="muted">
                    В статью добавится ссылка на карточку звонка с расшифровкой.
                    Повторная обработка не запускается. Просмотр доступен
                    сотрудникам с доступом к звонку.
                </p>
            </template>
            <template v-else>
                <label class="form-field"
                    >Название<input v-model="title" required maxlength="180"
                /></label>
                <label class="form-field"
                    >Ссылка на аудиозапись<input
                        v-model="source"
                        type="url"
                        required
                        maxlength="4096"
                        autocomplete="off"
                        placeholder="https://…"
                /></label>
                <p class="muted">
                    Прямая HTTPS-ссылка на запись Sipuni или ранее
                    импортированный файл Drive, до 25 МБ. Аудио сохранится в
                    базе и будет отправлено выбранному сервису расшифровки.
                </p>
            </template>
            <p v-if="error" class="call-error" role="alert">{{ error }}</p>
            <div class="modal-actions">
                <button
                    type="button"
                    class="button"
                    :disabled="busy"
                    @click="emit('close')"
                >
                    Отмена</button
                ><button class="button primary" :disabled="busy">
                    {{ busy ? "Добавляем…" : "Добавить звонок" }}
                </button>
            </div>
        </form>
    </Modal>
</template>
