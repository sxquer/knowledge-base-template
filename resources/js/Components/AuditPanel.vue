<script setup lang="ts">
import { ref, onBeforeUnmount } from "vue";
import { api } from "../api";
import Icon from "./Icon.vue";
const emit = defineEmits(["open", "error"]);
const topic = ref("");
const audit = ref<any>(null);
const busy = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
async function start() {
    busy.value = true;
    try {
        audit.value = await api("/api/audits", "POST", { topic: topic.value });
        poll();
    } catch (e: any) {
        emit("error", e.message);
        busy.value = false;
    }
}
async function poll() {
    try {
        audit.value = await api("/api/audits/" + audit.value.id);
        if (["queued", "running"].includes(audit.value.status)) {
            timer = setTimeout(poll, 2000);
        } else busy.value = false;
    } catch (e: any) {
        emit("error", e.message);
        busy.value = false;
    }
}
async function apply(conflict: number, option: number) {
    busy.value = true;
    try {
        audit.value = await api(`/api/audits/${audit.value.id}/apply`, "POST", {
            conflict,
            option,
        });
    } catch (e: any) {
        emit("error", e.message);
    } finally {
        busy.value = false;
    }
}
onBeforeUnmount(() => clearTimeout(timer));
</script>
<template>
    <section class="feature-page">
        <div class="feature-icon"><Icon name="ScanText" :size="30" /></div>
        <h1>Проверка знаний</h1>
        <p class="subtitle">
            Найдите расхождения. Выберите, какой вариант оставить.
        </p>
        <form class="audit-form" @submit.prevent="start">
            <label class="form-field"
                >Что проверить<textarea
                    v-model="topic"
                    required
                    rows="3"
                    maxlength="500"
                    placeholder="Например: условия передачи лида от оператора менеджеру"
                /></label
            ><button class="button primary" :disabled="busy">
                <Icon
                    :name="busy ? 'LoaderCircle' : 'Sparkles'"
                    :class="{ spin: busy }"
                />{{ busy ? "Проверяем…" : "Проверить через OpenRouter" }}
            </button>
        </form>
        <div class="note">
            <Icon name="Info" />
            <p>
                Проверка запускается только по запросу. ИИ показывает источники
                и предлагает правки. До вашего выбора документы остаются без
                изменений.
            </p>
        </div>
        <div v-if="audit?.status === 'failed'" class="error-box">
            {{ audit.error }}
        </div>
        <template v-if="audit?.status === 'complete'"
            ><div class="audit-summary">
                <h2>Результат проверки</h2>
                <p>{{ audit.report.summary }}</p>
                <span class="muted"
                    >Проверено {{ audit.report.coverage.checked_chunks }} из
                    {{ audit.report.coverage.total_chunks }} фрагментов
                    доступной базы. Выводы ИИ требуют проверки.</span
                >
            </div>
            <div v-if="!audit.report.conflicts.length" class="empty-state">
                <Icon name="Check" :size="30" />
                <h3>Подтверждённых расхождений не найдено</h3>
                <p>Это результат в пределах указанного охвата.</p>
            </div>
            <article
                class="conflict"
                v-for="(c, i) in audit.report.conflicts"
                :key="i"
            >
                <div class="change-line">
                    <span class="warning-label"
                        >Предполагаемое противоречие</span
                    ><span v-if="audit.report.applied.includes(i)" class="blue"
                        >Исправлено</span
                    >
                </div>
                <h3>{{ c.title }}</h3>
                <p>{{ c.explanation }}</p>
                <blockquote v-for="o in c.occurrences">
                    <p>«{{ o.quote }}»</p>
                    <button
                        class="text-button"
                        @click="emit('open', o.page_id)"
                    >
                        {{ o.title }}<Icon name="ArrowUpRight" :size="14" />
                    </button>
                </blockquote>
                <details v-for="(option, j) in c.options">
                    <summary>{{ option.label }}</summary>
                    <div class="diff-block" v-for="patch in option.patches">
                        <del>{{ patch.before }}</del
                        ><ins>{{ patch.after }}</ins>
                    </div>
                    <button
                        class="button dark"
                        :disabled="busy || audit.report.applied.includes(i)"
                        @click="apply(Number(i), Number(j))"
                    >
                        Применить эти правки
                    </button>
                </details>
            </article></template
        >
    </section>
</template>
