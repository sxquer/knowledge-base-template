<script setup lang="ts">
import { onBeforeUnmount, ref } from "vue";
import { api } from "../api";
import Icon from "./Icon.vue";
interface RevealedPassword {
    id: string;
    title: string;
    username?: string;
    password: string;
    url?: string;
    notes?: string;
}
const props = defineProps<{ passwordEntryId: string }>();
const revealed = ref<RevealedPassword | null>(null);
const loading = ref(false);
const error = ref("");
const notice = ref("");
let concealTimer: ReturnType<typeof setTimeout> | undefined;
function conceal() {
    clearTimeout(concealTimer);
    revealed.value = null;
    notice.value = "";
}
async function reveal() {
    loading.value = true;
    error.value = "";
    try {
        revealed.value = await api<RevealedPassword>(
            `/api/passwords/${props.passwordEntryId}/reveal`,
            "POST",
        );
        concealTimer = setTimeout(conceal, 60_000);
    } catch (e: any) {
        error.value =
            e.status === 404 ? "У вас нет доступа к этим данным." : e.message;
    } finally {
        loading.value = false;
    }
}
async function copy(value: string) {
    try {
        await navigator.clipboard.writeText(value);
        notice.value = "Скопировано";
    } catch {
        notice.value = "Выделите значение и скопируйте вручную.";
    }
}
onBeforeUnmount(conceal);
</script>
<template>
    <section
        class="password-widget"
        aria-label="Данные доступа"
        @mousedown.stop
    >
        <div class="password-heading">
            <span class="password-icon"><Icon name="Vault" :size="18" /></span>
            <div>
                <strong>{{ revealed?.title || "Данные доступа" }}</strong>
                <small>Защищённое хранилище</small>
            </div>
            <button v-if="revealed" class="button small" @click="conceal">
                <Icon name="EyeOff" :size="14" />Скрыть
            </button>
        </div>
        <button
            v-if="!revealed"
            class="button dark"
            :disabled="loading"
            @click="reveal"
        >
            <Icon name="Eye" :size="15" />{{
                loading ? "Проверяем доступ…" : "Показать доступы"
            }}
        </button>
        <div v-else class="password-fields">
            <label v-if="revealed.username"
                ><span>Логин</span>
                <div>
                    <code>{{ revealed.username }}</code
                    ><button
                        class="icon-button"
                        aria-label="Скопировать логин"
                        @click="copy(revealed.username)"
                    >
                        <Icon name="Copy" :size="15" />
                    </button></div
            ></label>
            <label
                ><span>Пароль</span>
                <div>
                    <code>{{ revealed.password }}</code
                    ><button
                        class="icon-button"
                        aria-label="Скопировать пароль"
                        @click="copy(revealed.password)"
                    >
                        <Icon name="Copy" :size="15" />
                    </button></div
            ></label>
            <label v-if="revealed.url"
                ><span>Сайт</span
                ><a
                    :href="revealed.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    >{{ revealed.url
                    }}<Icon name="ArrowUpRight" :size="14" /></a
            ></label>
            <label v-if="revealed.notes"
                ><span>Примечание</span>
                <p>{{ revealed.notes }}</p></label
            >
        </div>
        <p v-if="error" class="password-error" role="alert">{{ error }}</p>
        <p v-if="notice" class="muted password-notice" role="status">
            {{ notice }}
        </p>
    </section>
</template>
