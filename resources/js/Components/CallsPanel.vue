<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from "vue";
import { api } from "../api";
import CallView from "./CallView.vue";
import CallSettings from "./CallSettings.vue";
const props = defineProps<{
    admin: boolean;
    canEdit: boolean;
    canListen: boolean;
}>();
const tab = ref<"calls" | "connection" | "rubric">("calls");
const rows = ref<any[]>([]);
const selected = ref<any>(null);
const player = ref<any>(null);
const query = ref("");
const state = ref("");
const operator = ref("");
const operators = ref<string[]>([]);
const dateFrom = ref("");
const dateTo = ref("");
const reviewFilter = ref("");
const reviewingCall = ref("");
const timezone = ref("Europe/Moscow");
let listRequest = 0;
const currentPage = ref(1);
const lastPage = ref(1);
const total = ref(0);
const evaluationEnabled = ref(false);
const currentRubricVersion = ref<number | null>(null);
const error = ref("");
const notice = ref("");
const busy = ref(false);
const loading = ref(true);
const sync = ref<any>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let active = true;
let selection = 0;
const labels: Record<string, string> = {
    pending: "Ожидает разбора",
    paused: "Оценка выключена",
    queued: "В очереди",
    downloading: "Загружаем аудио",
    transcribing: "Расшифровываем",
    retrying: "Повторяем",
    evaluating: "Оцениваем",
    waiting_config: "Нужно подключение ИИ",
    ready: "Готово",
    failed: "Ошибка обработки",
    cancelled: "Остановлено",
};
const criterionLabels: Record<string, string> = {
    met: "Выполнено",
    partial: "Частично",
    missed: "Пропущено",
    not_applicable: "Неприменимо",
    insufficient_evidence: "Недостаточно данных",
};
const directions: Record<string, string> = {
    incoming: "Входящий",
    outgoing: "Исходящий",
    internal: "Внутренний",
    unknown: "—",
};
function date(value?: string) {
    return value
        ? new Date(value).toLocaleString("ru-RU", { timeZone: timezone.value })
        : "—";
}
function duration(seconds?: number) {
    return seconds == null
        ? "—"
        : `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, "0")}`;
}
function status(call: any) {
    return (
        labels[
            call.status === "ready" ? call.evaluation_status : call.status
        ] || call.status
    );
}
function jevAnswerCount(run: any) {
    return Object.values(run.jev_answers || {}).filter(
        (entry: any) => entry?.answer?.choice,
    ).length;
}
function jevWeight(run: any, id: string | number) {
    return run.rubric_snapshot?.criteria?.find(
        (criterion: any) => criterion.id === id,
    )?.weight;
}
function jevPoints(run: any, id: string | number, entry: any) {
    const weight = jevWeight(run, id);
    if (typeof weight !== "number") return null;
    switch (entry.answer?.choice) {
        case "met":
            return weight;
        case "partial":
            return weight / 2;
        case "missed":
            return 0;
        default:
            return null;
    }
}
function jevProbabilities(probabilities: any) {
    if (!probabilities || typeof probabilities !== "object") return [];
    return Object.entries(probabilities)
        .filter(([, value]) => typeof value === "number")
        .map(([choice, value]) => ({
            choice,
            percent: Math.round((value as number) * 100),
        }));
}
function isGenericReason(reason: string) {
    return [
        "Решение JEV без подтверждающей цитаты; проверьте по расшифровке.",
        "Решение JEV подтверждено цитатой.",
    ].includes(reason);
}
async function load() {
    const request = ++listRequest;
    const params = new URLSearchParams({
        q: query.value,
        state: state.value,
        operator: operator.value,
        date_from: dateFrom.value,
        date_to: dateTo.value,
        review: reviewFilter.value,
        page: String(currentPage.value),
    });
    const result: any = await api(`/api/calls?${params}`);
    if (!active || request !== listRequest) return;
    operators.value = result.operators;
    timezone.value = result.timezone;
    rows.value = result.data;
    evaluationEnabled.value = result.evaluation_enabled;
    currentRubricVersion.value = result.rubric_version;
    total.value = result.total;
    lastPage.value = result.last_page;
    if (props.admin) {
        const config: any = await api("/api/call-library/settings");
        if (active) sync.value = config.sync;
    }
}
async function search() {
    currentPage.value = 1;
    try {
        await load();
        error.value = "";
    } catch (e: any) {
        error.value = e.message;
    }
}
async function resetFilters() {
    query.value = "";
    state.value = "";
    operator.value = "";
    dateFrom.value = "";
    dateTo.value = "";
    reviewFilter.value = "";
    await search();
}
async function toggleReview(call: any) {
    reviewingCall.value = call.id;
    error.value = "";
    try {
        const result: any = await api(`/api/calls/${call.id}/review`, "PUT", {
            reviewed: !call.reviewed_at,
        });
        call.reviewed_at = result.reviewed_at;
        if (selected.value?.id === call.id)
            selected.value.reviewed_at = result.reviewed_at;
        await load();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        reviewingCall.value = "";
    }
}
async function open(id: string) {
    const seq = ++selection;
    selected.value = null;
    error.value = "";
    try {
        const result = await api(`/api/calls/${id}`);
        if (!active || seq !== selection) return;
        selected.value = result;
        history.replaceState(
            null,
            "",
            `/app/calls?call=${encodeURIComponent(id)}`,
        );
    } catch (e: any) {
        if (seq === selection) error.value = e.message;
    }
}
function back() {
    selection++;
    selected.value = null;
    history.replaceState(null, "", "/app/calls");
}
async function refresh() {
    if (!active) return;
    try {
        if (selected.value && !document.hidden && !reviewingCall.value) {
            const id = selected.value.id;
            const result = await api(`/api/calls/${id}`);
            if (active && selected.value?.id === id) selected.value = result;
        }
        if (tab.value === "calls" && !document.hidden) await load();
    } catch (e: any) {
        if (active) error.value = e.message;
    }
    if (active) timer = setTimeout(refresh, 6000);
}
async function evaluate() {
    busy.value = true;
    error.value = "";
    try {
        const id = selected.value.id;
        await api(`/api/calls/${id}/evaluate`, "POST");
        selected.value = await api(`/api/calls/${id}`);
    } catch (e: any) {
        error.value =
            e.status === 429
                ? "Слишком много запросов на оценку. Подождите минуту и попробуйте снова."
                : e.message;
    } finally {
        busy.value = false;
    }
}
async function importCalls() {
    busy.value = true;
    error.value = "";
    try {
        await api("/api/call-library/sync", "POST");
        notice.value =
            "Загрузка поставлена в очередь. Новые звонки появятся автоматически.";
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function copyLink() {
    try {
        await navigator.clipboard.writeText(
            `${location.origin}/app/calls?call=${selected.value.id}`,
        );
        notice.value =
            "Ссылка скопирована. В статье выберите виджет звонка и вставьте её.";
    } catch {
        notice.value = `Ссылка: ${location.origin}/app/calls?call=${selected.value.id}`;
    }
}
async function paginate(offset: number) {
    currentPage.value += offset;
    try {
        await load();
    } catch (e: any) {
        error.value = e.message;
    }
}
onMounted(async () => {
    try {
        await load();
        const id = new URLSearchParams(location.search).get("call");
        if (id) await open(id);
    } catch (e: any) {
        error.value = e.message;
    } finally {
        loading.value = false;
        if (active) timer = setTimeout(refresh, 6000);
    }
});
onBeforeUnmount(() => {
    active = false;
    selection++;
    clearTimeout(timer);
});
</script>
<template>
    <section class="calls-panel">
        <div class="call-settings-tabs" role="tablist" aria-label="Звонки">
            <button
                class="button"
                role="tab"
                :class="{ primary: tab === 'calls' }"
                :aria-selected="tab === 'calls'"
                @click="tab = 'calls'"
            >
                Звонки · {{ total }}</button
            ><button
                v-if="admin"
                class="button"
                role="tab"
                :class="{ primary: tab === 'connection' }"
                :aria-selected="tab === 'connection'"
                @click="tab = 'connection'"
            >
                Подключения и запуск
            </button>
            <button
                v-if="admin"
                class="button"
                role="tab"
                :class="{ primary: tab === 'rubric' }"
                :aria-selected="tab === 'rubric'"
                @click="tab = 'rubric'"
            >
                Эталон оценки
            </button>
        </div>
        <p v-if="error" role="alert" class="call-error">{{ error }}</p>
        <p v-if="notice" role="status">{{ notice }}</p>
        <p v-if="!evaluationEnabled && tab === 'calls'" class="muted">
            Автооценка выключена. Готовый звонок можно оценить вручную в его
            карточке.
        </p>
        <CallSettings
            v-if="tab !== 'calls' && admin"
            :section="tab === 'rubric' ? 'rubric' : 'connection'"
        />
        <template v-else-if="selected">
            <div class="call-toolbar">
                <button class="button" @click="back">← Все звонки</button
                ><button
                    class="button call-review-button"
                    :class="{ 'is-reviewed': selected.reviewed_at }"
                    :aria-pressed="!!selected.reviewed_at"
                    :disabled="!!reviewingCall"
                    @click="toggleReview(selected)"
                    title="Личная отметка, видна только вам"
                >
                    {{
                        selected.reviewed_at ? "✓ Разобран" : "Разобран"
                    }}</button
                ><button class="button" @click="copyLink">
                    Скопировать ссылку</button
                ><button
                    v-if="canEdit && selected.status === 'ready'"
                    class="button primary"
                    :disabled="
                        busy ||
                        ['queued', 'evaluating', 'retrying'].includes(
                            selected.evaluation_status,
                        )
                    "
                    @click="evaluate"
                >
                    {{
                        selected.evaluation
                            ? selected.rubric_snapshot?.version !==
                              currentRubricVersion
                                ? "Оценить по новому эталону"
                                : "Повторить оценку"
                            : "Оценить звонок"
                    }}
                </button>
            </div>
            <h2>{{ selected.title }}</h2>
            <p class="muted">
                {{ selected.operator_name || "Сотрудник не указан" }} ·
                {{ date(selected.called_at) }} · Разговор
                {{ duration(selected.talk_seconds) }} ·
                {{ directions[selected.direction] || "Добавлен вручную" }}
            </p>
            <CallView
                ref="player"
                :key="selected.id"
                :recording-id="selected.id"
                :editable="canEdit"
                :can-listen="canListen"
            />
            <div class="call-evaluation-head">
                <strong class="call-score"
                    >{{ selected.score == null ? "—" : selected.score
                    }}<small>/100</small></strong
                >
                <div>
                    <h3>{{ status(selected) }}</h3>
                    <p v-if="selected.rubric_snapshot">
                        {{ selected.rubric_snapshot.title }} · версия
                        {{ selected.rubric_snapshot.version
                        }}<span v-if="selected.rubric_snapshot.provisional">
                            · условный эталон</span
                        >
                    </p>
                    <p
                        v-if="
                            selected.rubric_snapshot &&
                            selected.rubric_snapshot.version !==
                                currentRubricVersion
                        "
                        class="muted"
                    >
                        Доступна версия эталона {{ currentRubricVersion }}.
                    </p>
                    <p class="muted">Оценка ИИ, требует проверки человеком.</p>
                </div>
            </div>
            <p v-if="selected.evaluation_error" class="call-error">
                {{ selected.evaluation_error }}
            </p>
            <template v-if="selected.evaluation">
                <p
                    v-if="selected.evaluation.score_complete === false"
                    class="muted"
                >
                    {{
                        selected.score == null
                            ? "Недостаточно данных для общего балла."
                            : "Балл предварительный: проверьте ответы JEV по расшифровке."
                    }}
                    <span v-if="selected.evaluation.low_confidence_count">
                        Низкая уверенность JEV:
                        {{ selected.evaluation.low_confidence_count }}.
                    </span>
                    <span v-if="selected.evaluation.insufficient_count">
                        Недостаточно данных:
                        {{ selected.evaluation.insufficient_count }}.
                    </span>
                </p>
                <p v-if="selected.evaluation.total_count != null" class="muted">
                    В итог вошло {{ selected.evaluation.answered_count }} из
                    {{ selected.evaluation.total_count }} критериев · весовое
                    покрытие {{ selected.evaluation.coverage_percent }}%.
                    Неприменимо: {{ selected.evaluation.not_applicable_count }}.
                </p>
                <p class="muted">
                    JEV выбирает ответ. Баллы рассчитываются по весу критерия:
                    «Выполнено» — весь вес, «Частично» — половина, «Пропущено» —
                    0.
                </p>
                <h3>Итог разговора</h3>
                <p class="call-summary">{{ selected.evaluation.summary }}</p>
                <div
                    v-for="criterion in selected.evaluation.criteria"
                    :key="criterion.id"
                    class="call-criterion-result"
                >
                    <div class="call-toolbar">
                        <h3>{{ criterion.title }}</h3>
                    </div>
                    <p class="call-criterion-question">
                        {{ criterion.jev_question || criterion.description }}
                    </p>
                    <div class="call-criterion-decision">
                        <span>
                            Ответ JEV:
                            <strong>{{
                                criterionLabels[criterion.status] ||
                                criterion.status
                            }}</strong>
                        </span>
                        <span>
                            Баллы по эталону:
                            <strong
                                >{{
                                    criterion.points == null
                                        ? "—"
                                        : criterion.points
                                }}
                                / {{ criterion.weight }}</strong
                            >
                        </span>
                    </div>
                    <p v-if="criterion.confidence != null" class="muted">
                        Уверенность JEV:
                        {{ Math.round(criterion.confidence * 100) }}%
                    </p>
                    <details
                        v-if="jevProbabilities(criterion.probabilities).length"
                        class="call-jev-probabilities"
                    >
                        <summary>Вероятности ответов JEV</summary>
                        <p
                            v-for="item in jevProbabilities(
                                criterion.probabilities,
                            )"
                            :key="item.choice"
                        >
                            {{ criterionLabels[item.choice] || item.choice }}:
                            {{ item.percent }}%
                        </p>
                    </details>
                    <p
                        v-if="
                            criterion.quote &&
                            criterion.reason &&
                            !isGenericReason(criterion.reason)
                        "
                        class="muted"
                    >
                        Пояснение ИИ: {{ criterion.reason }}
                    </p>
                    <blockquote v-if="criterion.quote">
                        {{ criterion.quote }}
                        <button
                            v-if="criterion.start != null"
                            class="button"
                            @click="player?.seek(criterion.start)"
                        >
                            ▶ {{ duration(criterion.start) }}
                        </button>
                    </blockquote>
                </div>
                <h3 v-if="selected.evaluation.recommendations?.length">
                    Что улучшить
                </h3>
                <ul v-if="selected.evaluation.recommendations?.length">
                    <li
                        v-for="(tip, index) in selected.evaluation
                            .recommendations"
                        :key="index"
                    >
                        {{ tip }}
                    </li>
                </ul>
                <p class="muted">
                    Оценено {{ date(selected.evaluated_at) }}. Смена эталона не
                    пересчитывает готовые оценки автоматически.
                </p>
            </template>
            <section
                v-if="selected.evaluation_runs?.length"
                class="call-evaluation-log"
            >
                <h3>История оценок и ответы JEV</h3>
                <details v-for="run in selected.evaluation_runs" :key="run.id">
                    <summary>
                        {{
                            run.trigger === "manual"
                                ? "Ручная оценка"
                                : "Автооценка"
                        }}
                        · {{ date(run.created_at) }} · эталон v{{
                            run.rubric_snapshot.version
                        }}
                        · {{ labels[run.status] || run.status }} · ответы JEV
                        {{ jevAnswerCount(run) }}/{{
                            run.rubric_snapshot.criteria.length
                        }}
                        <span v-if="run.score != null">
                            · {{ run.score }}/100</span
                        >
                    </summary>
                    <p v-if="run.error" class="call-error">{{ run.error }}</p>
                    <p
                        v-if="!Object.keys(run.jev_answers || {}).length"
                        class="muted"
                    >
                        Ответы JEV ещё не получены.
                    </p>
                    <div
                        v-for="(entry, id) in run.jev_answers"
                        :key="id"
                        class="call-jev-answer"
                    >
                        <strong>{{ entry.title }}</strong>
                        <p>{{ entry.question }}</p>
                        <p v-if="entry.answer">
                            Ответ JEV:
                            {{
                                criterionLabels[entry.answer.choice] ||
                                entry.answer.choice ||
                                "нет ответа"
                            }}
                            <span v-if="entry.answer.confidence != null">
                                · уверенность
                                {{ Math.round(entry.answer.confidence * 100) }}%
                            </span>
                            <span v-if="jevWeight(run, id) != null">
                                · баллы по эталону
                                {{ jevPoints(run, id, entry) ?? "—" }} /
                                {{ jevWeight(run, id) }}
                            </span>
                        </p>
                        <details
                            v-if="
                                jevProbabilities(entry.answer?.probabilities)
                                    .length
                            "
                            class="call-jev-probabilities"
                        >
                            <summary>Вероятности ответов JEV</summary>
                            <p
                                v-for="item in jevProbabilities(
                                    entry.answer.probabilities,
                                )"
                                :key="item.choice"
                            >
                                {{
                                    criterionLabels[item.choice] || item.choice
                                }}: {{ item.percent }}%
                            </p>
                        </details>
                        <p v-else class="muted">Ответ не получен.</p>
                    </div>
                </details>
            </section>
        </template>
        <template v-else>
            <form class="call-toolbar call-filters" @submit.prevent="search">
                <input
                    v-model="query"
                    class="call-search"
                    placeholder="Найти звонок по названию"
                    aria-label="Поиск звонков"
                /><select
                    v-model="state"
                    aria-label="Статус звонка"
                    @change="search"
                >
                    <option value="">Все статусы</option>
                    <option value="ready">Оценены ИИ</option>
                    <option value="transcribed">Расшифрованы</option>
                    <option value="pending">Ожидают обработки</option>
                    <option value="failed">С ошибкой</option></select
                ><label
                    >Сотрудник<select v-model="operator" @change="search">
                        <option value="">Все сотрудники</option>
                        <option
                            v-for="name in operators"
                            :key="name"
                            :value="name"
                        >
                            {{ name }}
                        </option>
                    </select></label
                >
                <label
                    >Дата с<input
                        v-model="dateFrom"
                        type="date"
                        :max="dateTo || undefined"
                        @change="search"
                /></label>
                <label
                    >Дата по<input
                        v-model="dateTo"
                        type="date"
                        :min="dateFrom || undefined"
                        @change="search"
                /></label>
                <label
                    >Мои отметки<select v-model="reviewFilter" @change="search">
                        <option value="">Все звонки</option>
                        <option value="reviewed">Разобраны мной</option>
                        <option value="unreviewed">
                            Ещё не разобраны мной
                        </option>
                    </select></label
                >
                <button class="button">Найти</button
                ><button type="button" class="button" @click="resetFilters">
                    Сбросить</button
                ><button
                    v-if="admin"
                    type="button"
                    class="button primary"
                    :disabled="busy"
                    @click="importCalls"
                >
                    Загрузить из Sipuni
                </button>
            </form>
            <p class="muted">
                Даты и время: {{ timezone }}. «Разобран» — ваша личная отметка.
            </p>
            <p v-if="sync?.finished_at" class="muted">
                Последняя загрузка: {{ date(sync.finished_at) }} · новых
                звонков: {{ sync.imported }}
            </p>
            <p v-if="sync?.error" class="call-error">{{ sync.error }}</p>
            <p v-if="loading" class="muted">Загружаем звонки…</p>
            <div v-else-if="!rows.length" class="empty-state">
                <h3>
                    {{
                        query ||
                        state ||
                        operator ||
                        dateFrom ||
                        dateTo ||
                        reviewFilter
                            ? "По этим фильтрам звонков нет"
                            : "Звонков пока нет"
                    }}
                </h3>
                <p
                    v-if="
                        query ||
                        state ||
                        operator ||
                        dateFrom ||
                        dateTo ||
                        reviewFilter
                    "
                >
                    Измените фильтры или сбросьте их, чтобы увидеть остальные
                    звонки.
                </p>
                <p v-else>
                    Подключите Sipuni в настройках. Система загрузит подходящие
                    разговоры, подготовит расшифровки. Оценка запускается только
                    после её включения в настройках.
                </p>
            </div>
            <div v-else class="calls-table-wrap">
                <table class="calls-table">
                    <thead>
                        <tr>
                            <th>Звонок</th>
                            <th>Сотрудник</th>
                            <th>Разговор</th>
                            <th>Статус</th>
                            <th>Оценка ИИ</th>
                            <th>Моя отметка</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="call in rows" :key="call.id">
                            <td>
                                <button
                                    class="call-title-button"
                                    @click="open(call.id)"
                                >
                                    {{ call.title }}</button
                                ><small
                                    >{{
                                        date(call.called_at || call.created_at)
                                    }}
                                    ·
                                    {{
                                        directions[call.direction] || "Вручную"
                                    }}</small
                                >
                            </td>
                            <td>{{ call.operator_name || "Не указан" }}</td>
                            <td>{{ duration(call.talk_seconds) }}</td>
                            <td>{{ status(call) }}</td>
                            <td>
                                <strong>{{
                                    call.score == null
                                        ? "—"
                                        : call.score + "/100"
                                }}</strong>
                            </td>
                            <td>
                                <button
                                    class="button call-review-button"
                                    :class="{ 'is-reviewed': call.reviewed_at }"
                                    :aria-pressed="!!call.reviewed_at"
                                    :disabled="!!reviewingCall"
                                    @click="toggleReview(call)"
                                    :aria-label="`${call.reviewed_at ? 'Снять отметку' : 'Отметить как разобранный'}: ${call.title}`"
                                    title="Личная отметка, видна только вам"
                                >
                                    {{
                                        call.reviewed_at
                                            ? "✓ Разобран"
                                            : "Разобран"
                                    }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="lastPage > 1" class="call-toolbar">
                <button
                    class="button"
                    :disabled="currentPage <= 1"
                    @click="paginate(-1)"
                >
                    Назад</button
                ><span>{{ currentPage }} / {{ lastPage }}</span
                ><button
                    class="button"
                    :disabled="currentPage >= lastPage"
                    @click="paginate(1)"
                >
                    Далее
                </button>
            </div>
        </template>
    </section>
</template>
