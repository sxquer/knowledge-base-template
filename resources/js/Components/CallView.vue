<script setup lang="ts">
import { computed, inject, nextTick, onBeforeUnmount, ref, watch } from "vue";
import { api } from "../api";
import Icon from "./Icon.vue";
export interface TimedWord {
    word: string;
    start: number;
    end: number;
    speaker?: number;
}
export interface CallData {
    id: string;
    page_id: string | null;
    title: string;
    status: string;
    asset_id?: string;
    transcript?: string;
    words?: TimedWord[];
    segments?: { text: string; start: number; end: number }[];
    duration?: number;
    error?: string;
    completion_revision?: number;
    updated_at?: string;
    transcription_provider?: string;
    transcription_model?: string;
}
const props = defineProps<{
    recordingId: string;
    editable?: boolean;
    snapshot?: CallData;
    publication?: string;
    canListen?: boolean;
}>();
const inheritedCanListen = inject<{ value: boolean } | undefined>(
    "canListenCalls",
    undefined,
);
const canListen = computed(
    () => props.canListen ?? inheritedCanListen?.value ?? true,
);
const call = ref<CallData | null>(props.snapshot ?? null);
const audio = ref<HTMLAudioElement>();
const transcript = ref<HTMLElement>();
const expanded = ref(false);
const follow = ref(true);
const currentTime = ref(0);
const playbackRate = ref(1);
const loadError = ref("");
const playbackError = ref(false);
const retrying = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let frame = 0;
let generation = 0;
let notified = 0;
const labels: Record<string, string> = {
    queued: "В очереди",
    retrying: "Повторяем обработку",
    downloading: "Загружаем запись",
    transcribing: "Расшифровываем",
    waiting_config: "Ожидает подключения ИИ",
    ready: "Расшифровка готова",
    failed: "Не удалось обработать",
    cancelled: "Обработка остановлена",
};
const words = computed(() => call.value?.words ?? []);
const active = computed(() => {
    let left = 0,
        right = words.value.length - 1,
        index = -1;
    while (left <= right) {
        const middle = (left + right) >> 1;
        if (words.value[middle].start <= currentTime.value) {
            index = middle;
            left = middle + 1;
        } else right = middle - 1;
    }
    return index >= 0 && currentTime.value <= words.value[index].end
        ? index
        : -1;
});
const groups = computed(() => {
    const output: { start: number; words: TimedWord[]; speaker?: number }[] =
        [];
    words.value.forEach((word, index) => {
        const previous = output[output.length - 1];
        if (
            !previous ||
            previous.speaker !== word.speaker ||
            previous.words.length >= 40
        ) {
            output.push({ start: index, words: [word], speaker: word.speaker });
        } else previous.words.push(word);
    });
    return output;
});
const mediaUrl = computed(() =>
    call.value?.asset_id
        ? `/media/${call.value.asset_id}${props.publication ? "?publication=" + encodeURIComponent(props.publication) : ""}`
        : "",
);
const time = (seconds: number) =>
    `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, "0")}`;
const canRetry = computed(
    () =>
        props.editable &&
        !props.snapshot &&
        ["failed", "cancelled", "ready"].includes(call.value?.status ?? ""),
);
async function load(version: number) {
    if (props.snapshot || version !== generation) return;
    try {
        const result = await api<CallData>(`/api/calls/${props.recordingId}`);
        if (version !== generation) return;
        call.value = result;
        loadError.value = "";
        if (
            result.completion_revision &&
            notified !== result.completion_revision
        ) {
            notified = result.completion_revision;
            window.dispatchEvent(
                new CustomEvent("kb-call-completed", {
                    detail: {
                        pageId: result.page_id,
                        revision: result.completion_revision,
                        updatedAt: result.updated_at,
                    },
                }),
            );
        }
    } catch (e: any) {
        if (version === generation)
            loadError.value = e.message || "Не удалось загрузить звонок.";
    }
    if (
        version === generation &&
        !["ready", "failed", "cancelled"].includes(call.value?.status ?? "")
    ) {
        timer = setTimeout(
            () => {
                if (document.hidden) {
                    timer = setTimeout(() => load(version), 10000);
                } else void load(version);
            },
            call.value?.status === "waiting_config" ? 10000 : 3000,
        );
    }
}
watch(
    () => [props.recordingId, props.snapshot] as const,
    () => {
        clearTimeout(timer);
        generation++;
        call.value = props.snapshot ?? null;
        loadError.value = "";
        if (!props.snapshot) void load(generation);
    },
    { immediate: true },
);
function tick() {
    if (audio.value) {
        currentTime.value = audio.value.currentTime;
        if (!audio.value.paused) frame = requestAnimationFrame(tick);
    }
}
function sync() {
    currentTime.value = audio.value?.currentTime ?? 0;
}
function playing() {
    cancelAnimationFrame(frame);
    tick();
}
function paused() {
    cancelAnimationFrame(frame);
    sync();
}
function seek(seconds: number) {
    if (audio.value) {
        audio.value.currentTime = seconds;
        currentTime.value = seconds;
        void audio.value.play().catch(() => {});
    }
}
function speed() {
    if (audio.value) audio.value.playbackRate = playbackRate.value;
}
watch(active, async (index) => {
    if (!expanded.value || !follow.value || index < 0) return;
    await nextTick();
    const container = transcript.value,
        word = container?.querySelector<HTMLElement>(`[data-word="${index}"]`);
    if (container && word) {
        const top = word.offsetTop;
        if (
            top < container.scrollTop + 16 ||
            top > container.scrollTop + container.clientHeight - 45
        )
            container.scrollTo({
                top: Math.max(0, top - 80),
                behavior: "smooth",
            });
    }
});
async function retry() {
    retrying.value = true;
    loadError.value = "";
    try {
        call.value = await api<CallData>(
            `/api/calls/${props.recordingId}/retry`,
            "POST",
            { retranscribe: call.value?.status === "ready" },
        );
        clearTimeout(timer);
        void load(generation);
    } catch (e: any) {
        loadError.value = e.message;
    } finally {
        retrying.value = false;
    }
}
onBeforeUnmount(() => {
    generation++;
    clearTimeout(timer);
    cancelAnimationFrame(frame);
});
defineExpose({ seek });
</script>
<template>
    <section
        class="call-widget"
        :aria-label="call?.title || 'Звонок'"
        @mousedown.stop
    >
        <div class="call-heading">
            <div class="call-title">
                <span class="call-icon"><Icon name="Phone" :size="18" /></span>
                <div>
                    <strong>{{ call?.title || "Звонок" }}</strong
                    ><small
                        >Аудиозапись
                        <template v-if="call?.duration"
                            >· {{ time(call.duration) }}</template
                        ></small
                    >
                </div>
            </div>
            <span class="call-status" :class="call?.status" role="status">{{
                labels[call?.status ?? ""] || "Загружаем…"
            }}</span>
        </div>
        <div v-if="mediaUrl && canListen" class="call-player">
            <audio
                ref="audio"
                controls
                preload="metadata"
                :src="mediaUrl"
                @timeupdate="sync"
                @play="playing"
                @pause="paused"
                @ended="paused"
                @error="playbackError = true"
                @loadedmetadata="
                    playbackError = false;
                    speed();
                "
            />
            <label class="call-speed"
                ><span class="sr-only">Скорость воспроизведения</span
                ><select
                    v-model.number="playbackRate"
                    @change="speed"
                    aria-label="Скорость воспроизведения"
                >
                    <option :value="0.75">0,75×</option>
                    <option :value="1">1×</option>
                    <option :value="1.25">1,25×</option>
                    <option :value="1.5">1,5×</option>
                    <option :value="2">2×</option>
                </select></label
            >
        </div>
        <p v-else-if="mediaUrl" class="muted">
            Прослушивание доступно после выдачи права администратором.
        </p>
        <p v-if="playbackError" class="call-error">
            Не удалось воспроизвести запись. Обновите страницу или проверьте
            соединение.
        </p>
        <p
            v-if="call?.status === 'waiting_config' && canListen"
            class="call-note"
        >
            Запись доступна для прослушивания. Расшифровка начнётся, когда
            администратор подключит сервис распознавания в «MCP и подключения».
        </p>
        <p
            v-else-if="
                ['queued', 'downloading', 'transcribing'].includes(
                    call?.status ?? '',
                )
            "
            class="call-note"
        >
            Готовим расшифровку в фоне. Можно закрыть статью — обработка
            продолжится.
        </p>
        <p v-if="call?.error || loadError" class="call-error" role="alert">
            {{ loadError || call?.error }}
        </p>
        <button
            v-if="canRetry"
            type="button"
            class="button small"
            :disabled="retrying"
            @click="retry"
        >
            {{
                retrying
                    ? "Запускаем…"
                    : call?.status === "ready"
                      ? "Распознать заново"
                      : "Повторить обработку"
            }}
        </button>
        <button
            v-if="loadError"
            type="button"
            class="text-button"
            @click="load(generation)"
        >
            Обновить состояние
        </button>
        <template v-if="call?.status === 'ready' || call?.transcript">
            <p v-if="call.transcription_provider === 'xai'" class="call-note">
                Распознано xAI · Grok
            </p>
            <p v-if="call.status !== 'ready'" class="call-note">
                Предыдущая расшифровка сохранена до завершения новой обработки.
            </p>
            <button
                type="button"
                class="call-expand"
                :aria-expanded="expanded"
                @click="expanded = !expanded"
            >
                <Icon
                    :name="expanded ? 'ChevronUp' : 'ChevronDown'"
                    :size="17"
                />{{
                    expanded
                        ? "Свернуть расшифровку"
                        : "Развернуть расшифровку"
                }}<span v-if="words.length">{{ words.length }} слов</span>
            </button>
            <div v-if="expanded" class="call-transcript">
                <div class="call-transcript-tools">
                    <span>Нажмите на слово, чтобы перейти к записи</span
                    ><label v-if="words.length"
                        ><input type="checkbox" v-model="follow" />Следить за
                        текстом</label
                    >
                </div>
                <div ref="transcript" class="call-transcript-scroll">
                    <div
                        v-for="group in groups"
                        :key="group.start"
                        class="call-paragraph"
                    >
                        <button
                            class="call-time"
                            type="button"
                            @click="seek(group.words[0].start)"
                        >
                            {{ time(group.words[0].start) }}
                        </button>
                        <p>
                            <span
                                v-if="group.speaker !== undefined"
                                class="call-speaker"
                                >Спикер {{ group.speaker + 1 }}</span
                            >
                            <template
                                v-for="(word, index) in group.words"
                                :key="group.start + index"
                                ><button
                                    type="button"
                                    class="call-word"
                                    :class="{
                                        active: active === group.start + index,
                                    }"
                                    :data-word="group.start + index"
                                    :aria-current="
                                        active === group.start + index
                                            ? 'true'
                                            : undefined
                                    "
                                    :aria-label="
                                        word.word + ' — ' + time(word.start)
                                    "
                                    @click="seek(word.start)"
                                >
                                    {{ word.word }}</button
                                >{{ " " }}</template
                            >
                        </p>
                    </div>
                    <template v-if="!words.length && call.segments?.length"
                        ><button
                            v-for="(segment, index) in call.segments"
                            :key="index"
                            type="button"
                            class="call-segment"
                            :class="{
                                active:
                                    currentTime >= segment.start &&
                                    currentTime < segment.end,
                            }"
                            @click="seek(segment.start)"
                        >
                            <span class="call-time">{{
                                time(segment.start)
                            }}</span
                            >{{ segment.text }}
                        </button></template
                    >
                    <p v-else-if="!words.length" class="call-plain">
                        {{ call.transcript || "В записи не обнаружена речь." }}
                    </p>
                </div>
                <small class="call-note"
                    >Автоматическая расшифровка — возможны неточности.<template
                        v-if="!words.length"
                    >
                        Метки слов не получены;
                        {{
                            call.segments?.length
                                ? "доступен переход по фразам."
                                : "доступен полный текст."
                        }}</template
                    ></small
                >
            </div>
        </template>
    </section>
</template>
