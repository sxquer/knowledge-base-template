<script setup lang="ts">
import { Head, useForm } from "@inertiajs/vue3";
import Icon from "../Components/Icon.vue";
defineProps<{ setup: boolean }>();
const form = useForm({ name: "", email: "", password: "" });
</script>
<template>
    <Head title="Вход" />
    <main class="login-page">
        <div class="login-brand">База<span>знаний</span><i /></div>
        <div class="login-card">
            <Icon name="BookOpen" :size="34" class="blue" />
            <h1>{{ setup ? "Ваша база знаний" : "С возвращением" }}</h1>
            <p>
                {{
                    setup
                        ? "Создайте учётную запись владельца."
                        : "Войдите, чтобы продолжить работу с базой."
                }}
            </p>
            <form @submit.prevent="form.post(setup ? '/setup' : '/login')">
                <label v-if="setup" class="form-field"
                    >Имя<input
                        v-model="form.name"
                        autocomplete="name"
                        required /></label
                ><label class="form-field"
                    >Электронная почта<input
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        required /></label
                ><label class="form-field"
                    >Пароль<input
                        v-model="form.password"
                        type="password"
                        :minlength="setup ? 12 : undefined"
                        :autocomplete="
                            setup ? 'new-password' : 'current-password'
                        "
                        required
                /></label>
                <p class="error" v-for="error in form.errors">{{ error }}</p>
                <button class="button primary full" :disabled="form.processing">
                    {{
                        form.processing
                            ? "Подождите…"
                            : setup
                              ? "Создать базу"
                              : "Войти"
                    }}<Icon name="ArrowRight" />
                </button>
            </form>
        </div>
        <p class="login-footer">Знания, на которые можно опереться.</p>
    </main>
</template>
