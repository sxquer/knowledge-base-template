<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import Icon from "./Icon.vue";
defineProps<{ title: string; wide?: boolean }>();
const emit = defineEmits(["close"]);
const dialog = ref<HTMLDialogElement>();
const prior = document.activeElement as HTMLElement | null;
onMounted(() => dialog.value?.showModal());
onUnmounted(() => prior?.focus());
</script>
<template>
    <dialog
        ref="dialog"
        :class="['modal', { 'modal-wide': wide }]"
        @cancel.prevent="emit('close')"
        @click="$event.target === $event.currentTarget && emit('close')"
    >
        <header>
            <h2>{{ title }}</h2>
            <button
                class="icon-button"
                aria-label="Закрыть"
                @click="emit('close')"
            >
                <Icon name="X" />
            </button>
        </header>
        <slot />
    </dialog>
</template>
