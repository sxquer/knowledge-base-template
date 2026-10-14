<script setup lang="ts">
import { ref, onMounted } from "vue";
import { api } from "../api";
import Icon from "./Icon.vue";
import { date } from "../types";
const props = defineProps<{ admin: boolean }>();
const emit = defineEmits(["error"]);
const settings = ref<any>(null);
const tokens = ref<any[]>([]);
const key = ref("");
const chat = ref("openai/gpt-4.1-mini");
const embedding = ref("openai/text-embedding-3-small");
const notice = ref("");
const tokenName = ref("Мой агент");
const writable = ref(false);
const token = ref("");
const busy = ref(false);
const transcriptionBusy = ref(false);
const transcriptionProvider = ref("openrouter");
const xaiKey = ref("");
const xaiModel = ref("grok-voice-transcribe-2.0");
const name = ref("");
const email = ref("");
const password = ref("");
const role = ref("reader");
const newCanViewCalls = ref(false);
const newCanListenCalls = ref(false);
const endpoint = location.origin + "/mcp";
async function load() {
    try {
        tokens.value = await api("/api/tokens");
        if (props.admin) {
            settings.value = await api("/api/settings");
            chat.value = settings.value.openrouter.chat_model;
            embedding.value = settings.value.openrouter.embedding_model;
            transcriptionProvider.value = settings.value.transcription.provider;
            xaiModel.value = settings.value.transcription.xai_model;
        }
    } catch (e: any) {
        emit("error", e.message);
    }
}
async function save() {
    busy.value = true;
    try {
        await api("/api/settings", "PUT", {
            key: key.value || null,
            chat_model: chat.value,
            embedding_model: embedding.value,
        });
        key.value = "";
        notice.value =
            "Настройки сохранены. Запустите индексацию для поиска по смыслу.";
        await load();
    } catch (e: any) {
        emit("error", e.message);
    } finally {
        busy.value = false;
    }
}
async function index() {
    try {
        await api("/api/index", "POST");
        notice.value =
            "Индексация добавлена в очередь. Она выполняется в фоне.";
    } catch (e: any) {
        emit("error", e.message);
    }
}
async function saveTranscription() {
    transcriptionBusy.value = true;
    try {
        await api("/api/transcription-settings", "PUT", {
            provider: transcriptionProvider.value,
            xai_key: xaiKey.value || null,
            xai_model: xaiModel.value,
        });
        xaiKey.value = "";
        notice.value =
            "Настройки распознавания сохранены. Новые звонки будут обрабатываться выбранным сервисом.";
        await load();
    } catch (e: any) {
        emit("error", e.message);
    } finally {
        transcriptionBusy.value = false;
    }
}
async function createToken() {
    try {
        token.value = (
            await api("/api/tokens", "POST", {
                name: tokenName.value,
                write: writable.value,
            })
        ).token;
        await load();
    } catch (e: any) {
        emit("error", e.message);
    }
}
async function revoke(id: number) {
    try {
        await api("/api/tokens/" + id, "DELETE");
        await load();
    } catch (e: any) {
        emit("error", e.message);
    }
}
async function user() {
    try {
        await api("/api/users", "POST", {
            name: name.value,
            email: email.value,
            password: password.value,
            role: role.value,
            can_view_calls: newCanViewCalls.value,
            can_listen_calls: newCanListenCalls.value,
        });
        password.value = "";
        email.value = "";
        name.value = "";
        newCanViewCalls.value = false;
        newCanListenCalls.value = false;
        notice.value = "Пользователь создан.";
        await load();
    } catch (e: any) {
        emit("error", e.message);
    }
}
async function changeRole(id: number, value: string) {
    try {
        await api("/api/users/" + id + "/role", "PUT", { role: value });
        await load();
    } catch (e: any) {
        emit("error", e.message);
    }
}
async function changeCallPermissions(
    user: any,
    permission: "view" | "listen",
    enabled: boolean,
) {
    const canView = permission === "view" ? enabled : user.can_view_calls;
    const canListen =
        permission === "listen" ? enabled : canView && user.can_listen_calls;
    try {
        await api(`/api/users/${user.id}/call-permissions`, "PUT", {
            can_view_calls: canView,
            can_listen_calls: canListen,
        });
        notice.value = "Права на звонки сохранены.";
        await load();
    } catch (e: any) {
        emit("error", e.message);
        await load();
    }
}
async function copy(value: string) {
    try {
        await navigator.clipboard.writeText(value);
        notice.value = "Скопировано";
    } catch {
        notice.value = "Выделите текст и скопируйте вручную.";
    }
}
onMounted(load);
</script>
<template>
    <section class="feature-page">
        <div class="feature-icon"><Icon name="Cable" :size="30" /></div>
        <h1>MCP и подключения</h1>
        <p class="subtitle">Одна база для команды и ваших агентов.</p>
        <p v-if="notice" class="success-box" role="status">{{ notice }}</p>
        <section class="settings-section">
            <h2>Подключение агента</h2>
            <p>Streamable HTTP · персональный токен · срок действия 90 дней</p>
            <div class="copy-field">
                <code>{{ endpoint }}</code
                ><button
                    class="icon-button"
                    @click="copy(endpoint)"
                    aria-label="Скопировать адрес MCP"
                >
                    <Icon name="Copy" />
                </button>
            </div>
            <p class="muted">
                Укажите заголовок Authorization: Bearer и токен. При первом
                обращении агент получит правила работы с базой.
            </p>
            <form class="inline-form" @submit.prevent="createToken">
                <input
                    v-model="tokenName"
                    placeholder="Название подключения"
                    required
                    aria-label="Название подключения"
                /><label
                    ><input type="checkbox" v-model="writable" />Разрешить
                    запись</label
                ><button class="button dark">Создать токен</button>
            </form>
            <div class="token-result" v-if="token">
                <strong>Сохраните токен: он показывается только сейчас.</strong>
                <div class="copy-field">
                    <code>{{ token }}</code
                    ><button
                        class="icon-button"
                        @click="copy(token)"
                        aria-label="Скопировать токен"
                    >
                        <Icon name="Copy" />
                    </button>
                </div>
                <button class="text-button" @click="token = ''">
                    Я сохранил, скрыть
                </button>
            </div>
            <div class="settings-row" v-for="t in tokens">
                <div>
                    <strong>{{ t.name }}</strong
                    ><small
                        >{{
                            t.abilities.includes("kb:write")
                                ? "Чтение и запись"
                                : "Только чтение"
                        }}
                        · {{ date(t.created_at) }}</small
                    >
                </div>
                <button class="button small" @click="revoke(t.id)">
                    Отозвать
                </button>
            </div>
        </section>
        <section class="settings-section" v-if="admin">
            <div class="heading-actions">
                <h2>OpenRouter</h2>
                <span
                    :class="[
                        'connection-status',
                        { connected: settings?.openrouter.configured },
                    ]"
                    ><i />{{
                        settings?.openrouter.configured
                            ? "Ключ сохранён"
                            : "Не подключён"
                    }}</span
                >
            </div>
            <form @submit.prevent="save">
                <label class="form-field"
                    >API-ключ<input
                        v-model="key"
                        type="password"
                        autocomplete="off"
                        :placeholder="
                            settings?.openrouter.configured
                                ? 'Оставьте пустым, чтобы сохранить текущий'
                                : 'sk-or-…'
                        "
                /></label>
                <div class="form-grid">
                    <label class="form-field"
                        >Модель МЕТА-ИИ<input v-model="chat" required /></label
                    ><label class="form-field"
                        >Модель эмбеддингов<input v-model="embedding" required
                    /></label>
                </div>
                <p class="muted">
                    Ключ хранится зашифрованным и не доступен через поиск или
                    MCP. При смене модели эмбеддингов запустите индексацию
                    заново.
                </p>
                <button class="button dark" :disabled="busy">
                    Сохранить настройки
                </button>
            </form>
            <div class="settings-row">
                <div>
                    <strong>Индекс поиска</strong
                    ><small
                        >{{ settings?.index.indexed ?? 0 }} /
                        {{ settings?.index.total ?? 0 }} фрагментов с
                        эмбеддингами</small
                    >
                </div>
                <button
                    class="button"
                    :disabled="!settings?.openrouter.configured"
                    @click="index"
                >
                    Индексировать
                </button>
            </div>
        </section>
        <section class="settings-section" v-if="admin">
            <div class="heading-actions">
                <h2>Распознавание звонков</h2>
                <span
                    class="connection-status"
                    :class="{ connected: settings?.transcription.configured }"
                    ><i />{{
                        settings?.transcription.configured
                            ? "Подключено"
                            : "Не подключено"
                    }}</span
                >
            </div>
            <form @submit.prevent="saveTranscription">
                <label class="form-field"
                    >Сервис распознавания<select
                        v-model="transcriptionProvider"
                    >
                        <option value="xai">
                            xAI · Grok — с разделением по спикерам
                        </option>
                        <option value="openrouter">OpenRouter · Whisper</option>
                    </select></label
                >
                <template v-if="transcriptionProvider === 'xai'">
                    <label class="form-field"
                        >API-ключ xAI<input
                            v-model="xaiKey"
                            type="password"
                            autocomplete="off"
                            :placeholder="
                                settings?.transcription.xai_configured
                                    ? 'Оставьте пустым, чтобы сохранить текущий'
                                    : 'xai-…'
                            "
                    /></label>
                    <label class="form-field"
                        >Модель распознавания<select v-model="xaiModel">
                            <option value="grok-voice-transcribe-2.0">
                                Grok Voice Transcribe 2.0
                            </option>
                            <option value="grok-voice-transcribe-1.0">
                                Grok Voice Transcribe 1.0
                            </option>
                        </select></label
                    >
                    <p class="muted">
                        Аудио отправляется напрямую в xAI. Диаризация включена:
                        реплики разных участников помечаются как «Спикер 1»,
                        «Спикер 2». Ключ хранится зашифрованным.
                    </p>
                </template>
                <p v-else class="muted">
                    Аудио отправляется в OpenRouter. Используется ключ из
                    раздела выше.
                </p>
                <p class="muted">
                    Чтобы обработать старую запись новым сервисом, нажмите
                    «Распознать заново» в виджете звонка. Предыдущая версия
                    останется в истории статьи.
                </p>
                <button class="button dark" :disabled="transcriptionBusy">
                    {{
                        transcriptionBusy
                            ? "Сохраняем…"
                            : "Сохранить распознавание"
                    }}
                </button>
            </form>
        </section>
        <section class="settings-section" v-if="admin">
            <h2>Команда и роли</h2>
            <div class="settings-row" v-for="u in settings?.users">
                <div>
                    <strong>{{ u.name }}</strong
                    ><small>{{ u.email }}</small>
                    <div class="call-permissions">
                        <label
                            ><input
                                type="checkbox"
                                :checked="
                                    u.role === 'admin' || u.can_view_calls
                                "
                                :disabled="u.role === 'admin'"
                                @change="
                                    changeCallPermissions(
                                        u,
                                        'view',
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />Просмотр звонков</label
                        >
                        <label
                            ><input
                                type="checkbox"
                                :checked="
                                    u.role === 'admin' || u.can_listen_calls
                                "
                                :disabled="
                                    u.role === 'admin' || !u.can_view_calls
                                "
                                @change="
                                    changeCallPermissions(
                                        u,
                                        'listen',
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />Прослушивание записей</label
                        >
                    </div>
                </div>
                <select
                    :value="u.role"
                    @change="
                        changeRole(
                            u.id,
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="admin">Администратор</option>
                    <option value="editor">Редактор</option>
                    <option value="reader">Читатель</option>
                </select>
            </div>
            <form class="add-user" @submit.prevent="user">
                <h3>Добавить участника</h3>
                <div class="form-grid">
                    <label class="form-field"
                        >Имя<input v-model="name" required /></label
                    ><label class="form-field"
                        >Почта<input
                            v-model="email"
                            type="email"
                            required /></label
                    ><label class="form-field"
                        >Начальный пароль<input
                            v-model="password"
                            type="password"
                            required
                            minlength="12"
                            autocomplete="new-password" /></label
                    ><label class="form-field"
                        >Роль<select v-model="role">
                            <option value="reader">Читатель</option>
                            <option value="editor">Редактор</option>
                            <option value="admin">Администратор</option>
                        </select></label
                    >
                </div>
                <div class="call-permissions">
                    <label
                        ><input
                            type="checkbox"
                            v-model="newCanViewCalls"
                            @change="
                                !newCanViewCalls && (newCanListenCalls = false)
                            "
                        />Просмотр звонков</label
                    >
                    <label
                        ><input
                            type="checkbox"
                            v-model="newCanListenCalls"
                            :disabled="!newCanViewCalls"
                        />Прослушивание записей</label
                    >
                </div>
                <button class="button dark">Добавить участника</button>
            </form>
        </section>
    </section>
</template>
