<script setup lang="ts">
import { ref, watch } from "vue";
import { api } from "../api";
const props = defineProps<{ recordingId: string }>();
const call = ref<any>(null);
const error = ref("");
watch(
    () => props.recordingId,
    async (id, _, onCleanup) => {
        let current = true;
        onCleanup(() => {
            current = false;
        });
        call.value = null;
        error.value = "";
        try {
            const result = await api(`/api/calls/${id}`);
            if (current) call.value = result;
        } catch (e: any) {
            if (current) error.value = e.message;
        }
    },
    { immediate: true },
);
</script>
<template>
    <section class="call-reference">
        <template v-if="call"
            ><strong>{{ call.title }}</strong
            ><span
                >Оценка ИИ:
                {{
                    call.score == null ? "не выставлена" : call.score + "/100"
                }}</span
            ><a :href="`/app/calls?call=${encodeURIComponent(recordingId)}`"
                >Открыть разбор звонка →</a
            ></template
        >
        <p v-else>{{ error || "Загружаем звонок…" }}</p>
    </section>
</template>
