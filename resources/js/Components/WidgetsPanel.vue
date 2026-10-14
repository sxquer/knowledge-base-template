<script setup lang="ts">
import { ref } from "vue";
import type { Widget, WidgetField } from "../types";
import Icon from "./Icon.vue";
import Modal from "./Modal.vue";
import WidgetView from "./WidgetView.vue";
import { api } from "../api";
defineProps<{ widgets: Widget[]; canEdit: boolean }>();
const emit = defineEmits(["refresh", "error"]);
const create = ref(false);
const name = ref("");
const key = ref("");
const description = ref("");
const view = ref("fields");
const fields = ref<WidgetField[]>([
    { key: "title", label: "Название", type: "text" },
]);
const saving = ref(false);
const preview = ref<Widget | null>(null);
async function save() {
    saving.value = true;
    try {
        await api("/api/widgets", "POST", {
            name: name.value,
            key: key.value,
            description: description.value,
            view: view.value,
            fields: fields.value,
        });
        create.value = false;
        emit("refresh");
        name.value = "";
        key.value = "";
    } catch (e: any) {
        emit("error", e.message);
    } finally {
        saving.value = false;
    }
}
</script>
<template>
    <section class="feature-page">
        <div class="feature-icon"><Icon name="Blocks" :size="30" /></div>
        <div class="heading-actions">
            <h1>Свои виджеты</h1>
            <button
                class="button primary"
                v-if="canEdit"
                @click="create = true"
            >
                <Icon name="Plus" />Создать виджет
            </button>
        </div>
        <p class="subtitle">Ваши поля и логика — прямо внутри документов.</p>
        <div class="widget-catalog">
            <button
                v-for="w in widgets"
                :key="w.id"
                class="catalog-row"
                @click="preview = w"
            >
                <span class="catalog-icon"
                    ><Icon
                        :name="
                            w.view === 'checklist'
                                ? 'ListChecks'
                                : w.view === 'calculator'
                                  ? 'Calculator'
                                  : 'Blocks'
                        "
                        :size="24" /></span
                ><span
                    ><strong>{{ w.name }}</strong
                    ><small>{{ w.description }}</small></span
                ><span class="muted mono"
                    >{{ w.fields.length }} поля · v{{ w.schema_version }}</span
                ><Icon name="ArrowUpRight" :size="17" />
            </button>
        </div>
        <div class="note">
            <Icon name="Code2" />
            <p>
                Каждый виджет имеет схему данных и доступен агентам через MCP.
                Для собственной логики можно добавить Vue-компонент и серверную
                проверку.
            </p>
        </div>
    </section>
    <Modal v-if="create" title="Новый тип виджета" wide @close="create = false"
        ><form class="modal-body" @submit.prevent="save">
            <div class="form-grid">
                <label class="form-field"
                    >Название<input
                        v-model="name"
                        required
                        placeholder="Карточка эксперимента" /></label
                ><label class="form-field"
                    >Системное имя<input
                        v-model="key"
                        required
                        pattern="[a-z][a-z0-9_]{2,50}"
                        placeholder="experiment"
                /></label>
            </div>
            <label class="form-field"
                >Описание<input v-model="description" /></label
            ><label class="form-field"
                >Представление<select v-model="view">
                    <option value="fields">Карточка полей</option>
                    <option value="checklist">Чеклист</option>
                </select></label
            >
            <div class="field-builder" v-for="(field, i) in fields" :key="i">
                <input
                    v-model="field.label"
                    placeholder="Название поля"
                    required
                    aria-label="Название поля"
                /><input
                    v-model="field.key"
                    placeholder="field_key"
                    pattern="[a-z][a-z0-9_]{0,40}"
                    required
                    aria-label="Ключ поля"
                /><select v-model="field.type" aria-label="Тип поля">
                    <option value="text">Текст</option>
                    <option value="textarea">Длинный текст</option>
                    <option value="number">Число</option>
                    <option value="boolean">Галочка</option></select
                ><button
                    type="button"
                    class="icon-button"
                    :disabled="fields.length === 1"
                    @click="fields.splice(i, 1)"
                    aria-label="Удалить поле"
                >
                    <Icon name="X" />
                </button>
            </div>
            <button
                type="button"
                class="button"
                :disabled="fields.length >= 20"
                @click="
                    fields.push({
                        key: 'field_' + fields.length,
                        label: '',
                        type: view === 'checklist' ? 'boolean' : 'text',
                    })
                "
            >
                <Icon name="Plus" />Добавить поле
            </button>
            <div class="modal-actions">
                <button class="button primary" :disabled="saving">
                    Создать
                </button>
            </div>
        </form></Modal
    >
    <Modal v-if="preview" :title="preview.name" @close="preview = null"
        ><div class="modal-body">
            <WidgetView :definition="preview" :data="{}" />
            <p class="muted">
                Чтобы использовать этот блок, откройте статью и нажмите «Виджет»
                в редакторе.
            </p>
        </div></Modal
    >
</template>
