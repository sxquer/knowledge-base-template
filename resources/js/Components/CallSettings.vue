<script setup lang="ts">
import { onMounted, ref } from "vue";
import { api } from "../api";
const props = defineProps<{ section: "connection" | "rubric" }>();
const settings = ref<any>(null);
const connection = ref<any>(null);
const rubric = ref<any>(null);
const secret = ref("");
const busy = ref(false);
const error = ref("");
const notice = ref("");
function copyRubric(value: any) {
    return JSON.parse(JSON.stringify(value));
}
async function load() {
    try {
        settings.value = await api("/api/call-library/settings");
        connection.value = {
            enabled: settings.value.enabled,
            evaluation_enabled: settings.value.evaluation_enabled,
            account: settings.value.account,
            min_talk_seconds: settings.value.min_talk_seconds,
            start_date: settings.value.start_date,
            timezone: settings.value.timezone,
        };
        rubric.value = copyRubric(settings.value.rubric);
    } catch (e: any) {
        error.value = e.message;
    }
}
async function save(kind: "connection" | "rubric") {
    busy.value = true;
    error.value = "";
    notice.value = "";
    try {
        settings.value = await api("/api/call-library/settings", "PUT", {
            ...settings.value,
            ...(kind === "connection" ? connection.value : {}),
            rubric: kind === "rubric" ? rubric.value : settings.value.rubric,
            secret: kind === "connection" ? secret.value || null : null,
        });
        if (kind === "connection") {
            secret.value = "";
            connection.value = {
                ...connection.value,
                enabled: settings.value.enabled,
                evaluation_enabled: settings.value.evaluation_enabled,
                account: settings.value.account,
            };
            notice.value = "Подключения и режим оценки сохранены.";
        } else {
            rubric.value = copyRubric(settings.value.rubric);
            notice.value =
                "Эталон сохранён. Новые оценки используют эту версию; прежние разборы сохраняют старую.";
        }
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
function addCriterion() {
    rubric.value.criteria.push({
        id: "criterion_" + crypto.randomUUID().replaceAll("-", ""),
        title: "Новый критерий",
        description: "",
        jev_question: "",
        weight: 10,
    });
}
onMounted(load);
</script>
<template>
    <section class="call-settings">
        <p v-if="error" role="alert" class="call-error">{{ error }}</p>
        <form
            v-if="settings && connection && rubric"
            @submit.prevent="save(props.section)"
        >
            <template v-if="props.section === 'connection'">
                <h2>Загрузка из Sipuni</h2>
                <p class="muted">
                    Проверка новых записей каждые 30 минут. При первом запуске
                    загрузка идёт с указанной даты, до 7 дней за один проход.
                </p>
                <div class="call-settings-grid">
                    <label class="form-field"
                        >Разговор дольше, секунд<input
                            v-model.number="connection.min_talk_seconds"
                            type="number"
                            min="0"
                            max="86400"
                            required
                    /></label>
                    <label class="form-field"
                        >Загружать начиная с<input
                            v-model="connection.start_date"
                            type="date"
                            required
                    /></label>
                    <label class="form-field"
                        >Часовой пояс кабинета<input
                            v-model="connection.timezone"
                            required
                            placeholder="Europe/Moscow"
                    /></label>
                </div>
                <p class="muted">
                    Время дозвона не учитывается. Ровно
                    {{ connection.min_talk_seconds }} секунд не проходит фильтр.
                    Смена порога повторно проверит выбранный период; уже
                    загруженные звонки сохранятся.
                </p>
                <div class="call-settings-grid">
                    <label class="form-field"
                        >Номер кабинета Sipuni<input
                            v-model="connection.account"
                            autocomplete="off"
                            inputmode="numeric"
                            placeholder="012345"
                    /></label>
                    <label class="form-field"
                        >Ключ API статистики<input
                            v-model="secret"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="
                                settings.configured
                                    ? 'Подключён · оставьте пустым, чтобы сохранить'
                                    : 'Ключ из интеграции по API'
                            "
                    /></label>
                </div>
                <label class="call-check"
                    ><input v-model="connection.enabled" type="checkbox" />
                    Загружать звонки автоматически</label
                >
                <p class="muted">
                    Аудио отправляется выбранному сервису расшифровки. Текст
                    разговора и эталон отправляются в OpenRouter для оценки.
                    Ключ Sipuni хранится зашифрованным.
                </p>
                <hr />
                <h2>ИИ-оценка · JEV и OpenRouter</h2>
                <label class="call-check"
                    ><input
                        v-model="connection.evaluation_enabled"
                        type="checkbox"
                    />
                    Включить оценку новых звонков по эталону</label
                >
                <p class="muted">
                    Сейчас:
                    {{
                        connection.evaluation_enabled
                            ? "включена"
                            : "выключена"
                    }}. Решения: {{ settings.evaluation_model }}. Пояснения:
                    {{ settings.explanation_model }}. Подключение OpenRouter:
                    {{
                        settings.evaluation_configured
                            ? "настроено"
                            : "нужен ключ в настройках MCP и подключения"
                    }}. Включайте оценку после подготовки скрипта. Старые звонки
                    автоматически не пересчитываются.
                </p>

                <div class="call-toolbar">
                    <button class="button primary" :disabled="busy">
                        {{ busy ? "Сохраняем…" : "Сохранить подключения" }}
                    </button>
                </div>
            </template>
            <template v-else>
                <h2>Эталон оценки звонков · версия {{ rubric.version }}</h2>
                <p class="muted">
                    Один набор проверок для всех звонков. Для каждого пункта
                    задайте критерий для человека и отдельный вопрос JEV.
                    Изменение эталона создаёт новую версию; готовые оценки
                    сохраняют прежнюю.
                </p>
                <label class="form-field"
                    >Название<input
                        v-model="rubric.title"
                        required
                        maxlength="180"
                /></label>
                <label class="call-check"
                    ><input v-model="rubric.provisional" type="checkbox" />
                    Условный эталон, требует проверки руководителем</label
                >
                <label class="form-field"
                    >Задача звонка и правила оценки<textarea
                        v-model="rubric.instructions"
                        rows="4"
                        required
                        maxlength="10000"
                    ></textarea>
                </label>
                <label class="form-field"
                    >Общие правила для JEV<textarea
                        v-model="rubric.jev_context"
                        rows="3"
                        required
                        maxlength="400"
                    ></textarea>
                </label>
                <div
                    v-for="(criterion, index) in rubric.criteria"
                    :key="criterion.id"
                    class="call-criterion-editor"
                >
                    <div class="call-settings-grid">
                        <label class="form-field"
                            >Критерий {{ Number(index) + 1
                            }}<input
                                v-model="criterion.title"
                                required
                                maxlength="180"
                        /></label>
                        <label class="form-field"
                            >Вес<input
                                v-model.number="criterion.weight"
                                type="number"
                                required
                                min="1"
                                max="100"
                        /></label>
                    </div>
                    <label class="form-field"
                        >Что проверяем<textarea
                            v-model="criterion.description"
                            required
                            rows="2"
                            maxlength="3000"
                        ></textarea>
                    </label>
                    <label class="form-field"
                        >Вопрос к JEV<textarea
                            v-model="criterion.jev_question"
                            required
                            rows="2"
                            maxlength="1000"
                            placeholder="Насколько сотрудник выполнил этот критерий?"
                        ></textarea>
                    </label>
                    <button
                        type="button"
                        class="button"
                        :disabled="rubric.criteria.length === 1"
                        @click="rubric.criteria.splice(index, 1)"
                    >
                        Убрать критерий
                    </button>
                </div>
                <p class="muted">
                    Выполнено — полный вес, частично — половина, пропущено — 0.
                    Неприменимые пункты исключаются из расчёта. Если данных
                    недостаточно, общий балл не выставляется.
                </p>
                <div class="call-toolbar">
                    <button
                        type="button"
                        class="button"
                        :disabled="rubric.criteria.length >= 30"
                        @click="addCriterion"
                    >
                        Добавить критерий
                    </button>
                    <button class="button primary" :disabled="busy">
                        {{ busy ? "Сохраняем…" : "Сохранить эталон" }}
                    </button>
                </div>
            </template>
        </form>
        <p v-if="notice" role="status">{{ notice }}</p>
        <p v-else-if="!settings && !error" class="muted">
            Загружаем настройки…
        </p>
    </section>
</template>
