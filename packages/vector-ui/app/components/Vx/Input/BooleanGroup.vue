<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// A flag group edited as one group of checkboxes: the value is an object
// of every option key to true or false.
const props = withDefaults(defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string, disabled?: boolean }>(), { record: undefined, resource: undefined, disabled: undefined })
const model = defineModel<Record<string, boolean> | null>()

const flags = computed<Record<string, boolean>>(() => Object.fromEntries((props.field.options ?? []).map(option => [String(option.value), model.value?.[String(option.value)] === true])))

function toggle(key: string, on: boolean | null) {
    model.value = { ...flags.value, [key]: on === true }
}
</script>

<template>
    <v-input :error-messages="errorMessages" :hint="field.help ?? undefined" :persistent-hint="!!field.help" :disabled="props.disabled">
        <fieldset class="vx-fieldset" :data-flag-group="field.name">
            <legend class="text-body-2 text-medium-emphasis">{{ field.label }}</legend>
            <div class="d-flex flex-wrap column-gap-4">
                <v-checkbox
                    v-for="option in field.options ?? []"
                    :key="String(option.value)"
                    :label="option.label"
                    :model-value="flags[String(option.value)]"
                    :disabled="props.disabled"
                    density="compact"
                    hide-details
                    @update:model-value="(on: boolean | null) => toggle(String(option.value), on)"
                />
            </div>
        </fieldset>
    </v-input>
</template>

<style scoped>
/* a fieldset that groups without a fieldset's own border, padding and margin */
.vx-fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
</style>
