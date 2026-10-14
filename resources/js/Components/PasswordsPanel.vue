<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { api } from "../api";
import Icon from "./Icon.vue";
import Modal from "./Modal.vue";
interface User {
    id: number;
    name: string;
    email: string;
    role: string;
}
interface Entry {
    id: string;
    title: string;
    updated_at: string;
    user_ids?: number[];
}
const props = defineProps<{ admin: boolean }>();
const emit = defineEmits(["error"]);
const entries = ref<Entry[]>([]);
const users = ref<User[]>([]);
const open = ref(false);
const editing = ref<Entry | null>(null);
const saving = ref(false);
const form = ref({
    title: "",
    username: "",
    password: "",
    url: "",
    notes: "",
    user_ids: [] as number[],
});
const selectedCount = computed(() => form.value.user_ids.length);
async function load() {
    try {
        const data = await api<{ entries: Entry[]; users: User[] }>(
            "/api/passwords",
        );
        entries.value = data.entries;
        users.value = data.users;
    } catch (e: any) {
        emit("error", e.message);
    }
}
function openCreate() {
    editing.value = null;
    form.value = {
        title: "",
        username: "",
        password: "",
        url: "",
        notes: "",
        user_ids: [],
    };
    open.value = true;
}
function openEdit(entry: Entry) {
    editing.value = entry;
    form.value = {
        title: entry.title,
        username: "",
        password: "",
        url: "",
        notes: "",
        user_ids: [...(entry.user_ids ?? [])],
    };
    open.value = true;
}
async function save() {
    saving.value = true;
    try {
        const path = editing.value
            ? `/api/passwords/${editing.value.id}`
            : "/api/passwords";
        await api(path, editing.value ? "PUT" : "POST", form.value);
        open.value = false;
        await load();
    } catch (e: any) {
        emit("error", e.message);
    } finally {
        saving.value = false;
    }
}
onMounted(load);
</script>
<template>
    <section class="feature-page">
        <div class="feature-icon"><Icon name="Vault" :size="30" /></div>
        <div class="heading-actions">
            <h1>Пароли</h1>
            <button v-if="admin" class="button primary" @click="openCreate">
                <Icon name="Plus" />Добавить доступ
            </button>
        </div>
        <p class="subtitle">
            Защищённые доступы для команды. Значения открываются только по клику
            и только тем, кому вы их выдали.
        </p>
        <div class="password-catalog">
            <button
                v-for="entry in entries"
                :key="entry.id"
                class="catalog-row"
                :disabled="!admin"
                @click="admin && openEdit(entry)"
            >
                <span class="catalog-icon"
                    ><Icon name="Vault" :size="22" /></span
                ><span
                    ><strong>{{ entry.title }}</strong
                    ><small>{{
                        admin
                            ? `${entry.user_ids?.length ?? 0} пользователей с доступом`
                            : "Доступ разрешён"
                    }}</small></span
                ><span class="muted mono"
                    >Изм.
                    {{
                        new Date(entry.updated_at).toLocaleDateString("ru-RU")
                    }}</span
                ><Icon v-if="admin" name="ArrowUpRight" :size="17" />
            </button>
            <div v-if="!entries.length" class="empty-state">
                <Icon name="LockKeyhole" :size="30" />
                <h3>
                    {{
                        admin
                            ? "Добавьте первый доступ"
                            : "Нет выданных доступов"
                    }}
                </h3>
                <p>
                    {{
                        admin
                            ? "Создайте запись и выберите пользователей, которые смогут её открыть."
                            : "Администратор может выдать вам доступ к нужной записи."
                    }}
                </p>
            </div>
        </div>
        <div class="note">
            <Icon name="EyeOff" />
            <p>
                Пароли шифруются на сервере. Они не сохраняются в статьях,
                истории изменений, поиске, публичных страницах и MCP.
            </p>
        </div>
    </section>
    <Modal
        v-if="open"
        :title="editing ? 'Настроить доступ' : 'Новый доступ'"
        wide
        @close="!saving && (open = false)"
        ><form class="modal-body" @submit.prevent="save">
            <label class="form-field"
                >Название<input
                    v-model="form.title"
                    required
                    maxlength="180"
                    placeholder="amoCRM · основной аккаунт"
            /></label>
            <div class="form-grid">
                <label class="form-field"
                    >Логин<input
                        v-model="form.username"
                        autocomplete="off"
                        maxlength="2000"
                        :placeholder="
                            editing ? 'Оставьте пустым, чтобы не менять' : ''
                        " /></label
                ><label class="form-field"
                    >Пароль<input
                        v-model="form.password"
                        type="password"
                        :required="!editing"
                        autocomplete="new-password"
                        maxlength="4000"
                        :placeholder="
                            editing ? 'Оставьте пустым, чтобы не менять' : ''
                        "
                /></label>
            </div>
            <label class="form-field"
                >Ссылка на сервис<input
                    v-model="form.url"
                    type="url"
                    maxlength="2000"
                    placeholder="https://…" /></label
            ><label class="form-field"
                >Примечание<textarea
                    v-model="form.notes"
                    rows="3"
                    maxlength="10000"
                    :placeholder="
                        editing ? 'Оставьте пустым, чтобы не менять' : ''
                    "
                />
            </label>
            <section class="password-grants">
                <div>
                    <strong>Кому открыть</strong
                    ><span
                        >{{ selectedCount }} выбрано · администраторы имеют
                        доступ всегда</span
                    >
                </div>
                <label v-for="user in users" :key="user.id" class="check-row"
                    ><input
                        type="checkbox"
                        :value="user.id"
                        v-model="form.user_ids"
                    /><span
                        ><strong>{{ user.name }}</strong
                        ><small
                            >{{ user.email }} ·
                            {{
                                user.role === "admin"
                                    ? "администратор"
                                    : user.role === "editor"
                                      ? "редактор"
                                      : "читатель"
                            }}</small
                        ></span
                    ></label
                >
            </section>
            <p class="muted">
                Значения не показываются при редактировании. Пустые поля не
                меняют сохранённые данные.
            </p>
            <div class="modal-actions">
                <button
                    type="button"
                    class="button"
                    :disabled="saving"
                    @click="open = false"
                >
                    Отмена</button
                ><button class="button primary" :disabled="saving">
                    {{ saving ? "Сохраняем…" : "Сохранить" }}
                </button>
            </div>
        </form></Modal
    >
</template>
