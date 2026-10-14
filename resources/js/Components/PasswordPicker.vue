<script setup lang="ts">
import { onMounted, ref } from "vue";
import { api } from "../api";
import Icon from "./Icon.vue";
import Modal from "./Modal.vue";
interface Entry {
    id: string;
    title: string;
}
const emit = defineEmits(["close", "select"]);
const entries = ref<Entry[]>([]);
const loading = ref(true);
const error = ref("");
onMounted(async () => {
    try {
        entries.value = (
            await api<{ entries: Entry[] }>("/api/passwords")
        ).entries;
    } catch (e: any) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
});
</script>
<template>
    <Modal title="Добавить данные доступа" @close="emit('close')">
        <div class="modal-body password-picker">
            <p class="muted">
                В статье сохранится только защищённая ссылка. Значения не
                попадут в историю, поиск, публичную страницу или MCP.
            </p>
            <p v-if="loading" class="muted">Загружаем доступные записи…</p>
            <p v-else-if="error" class="password-error" role="alert">
                {{ error }}
            </p>
            <template v-else>
                <button
                    v-for="entry in entries"
                    :key="entry.id"
                    class="password-choice"
                    @click="emit('select', entry.id)"
                >
                    <Icon name="Vault" /><span
                        ><strong>{{ entry.title }}</strong
                        ><small
                            >Откроется только пользователям с правом</small
                        ></span
                    ><Icon name="ArrowRight" :size="16" />
                </button>
                <p v-if="!entries.length" class="empty-inline">
                    У вас пока нет доступа к записям. Администратор выдаёт его в
                    разделе «Пароли».
                </p>
            </template>
        </div>
    </Modal>
</template>
