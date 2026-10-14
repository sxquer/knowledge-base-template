<script setup lang="ts">
import { computed } from "vue";
import type { Widget } from "../types";
import Icon from "./Icon.vue";
const props = defineProps<{
    definition?: Widget;
    data: Record<string, any>;
    editable?: boolean;
}>();
const emit = defineEmits(["change"]);
const checked = computed(
    () =>
        props.definition?.fields.filter(
            (f) => f.type === "boolean" && props.data[f.key],
        ).length ?? 0,
);
const cost = computed(() =>
    Number(props.data.leads) > 0
        ? new Intl.NumberFormat("ru-RU", { maximumFractionDigits: 0 }).format(
              Number(props.data.budget) / Number(props.data.leads),
          ) + " ₽"
        : "—",
);
function change(key: string, value: any) {
    emit("change", { ...props.data, [key]: value });
}
</script>
<template>
    <div class="widget-block" v-if="definition">
        <div class="widget-heading">
            <Icon
                :name="
                    definition.view === 'checklist'
                        ? 'ListChecks'
                        : definition.view === 'calculator'
                          ? 'Calculator'
                          : 'Blocks'
                "
            /><strong>{{ definition.name }}</strong
            ><span v-if="definition.view === 'checklist'" class="muted mono"
                >{{ checked }} / {{ definition.fields.length }}</span
            ><span v-else class="widget-label">Виджет</span>
        </div>
        <div :class="['widget-fields', definition.view]">
            <label
                v-for="field in definition.fields"
                :key="field.key"
                :class="{ 'check-row': field.type === 'boolean' }"
                ><template v-if="field.type === 'boolean'"
                    ><input
                        type="checkbox"
                        :checked="!!data[field.key]"
                        :disabled="!editable"
                        @change="
                            change(
                                field.key,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    /><span>{{ field.label }}</span></template
                ><template v-else
                    ><span class="field-label">{{ field.label }}</span
                    ><textarea
                        v-if="editable && field.type === 'textarea'"
                        :value="data[field.key]"
                        @input="
                            change(
                                field.key,
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                        rows="2"
                    /><input
                        v-else-if="editable"
                        :type="field.type === 'number' ? 'number' : 'text'"
                        :value="data[field.key]"
                        @input="
                            change(
                                field.key,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    /><span v-else class="field-value">{{
                        data[field.key] || "—"
                    }}</span></template
                ></label
            >
        </div>
        <div v-if="definition.view === 'calculator'" class="calculated">
            <span>Стоимость обращения</span><strong>{{ cost }}</strong>
        </div>
        <footer>{{ definition.description }}</footer>
    </div>
    <div v-else class="empty-inline">Определение виджета недоступно</div>
</template>
