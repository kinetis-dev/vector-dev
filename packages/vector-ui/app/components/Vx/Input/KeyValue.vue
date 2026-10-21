<script setup lang="ts">
import { fieldProps, type VectorField } from '#vector/app/types/vector'

// A key-value field's entries as rows of two text inputs. The rows are the
// editing state — a row may be half typed — and the model is the object
// they make: a row without a key is left out, a repeated key's last row wins.
const props = defineProps<{ field: VectorField, rules?: unknown[], errorMessages: string[], record?: Record<string, unknown>, resource?: string, disabled?: boolean }>()
const model = defineModel<Record<string, string> | null>()

const { t } = useVectorT()
const rows = ref<{ key: string, value: string }[]>([])
const max = computed(() => Number(fieldProps(props.field).maxEntries ?? 50))
const keyLabel = computed(() => String(fieldProps(props.field).keyLabel ?? t('fields.keyValue.key')))
const valueLabel = computed(() => String(fieldProps(props.field).valueLabel ?? t('fields.keyValue.value')))

// Adopt a value set from outside (a record loaded), not the one these rows
// just made — which comes back as the form's reactive copy, so by content.
let emitted = ''
watch(model, (value) => {
    if (JSON.stringify(value ?? {}) === emitted) return
    rows.value = value && typeof value === 'object' ? Object.entries(value).map(([key, v]) => ({ key, value: String(v ?? '') })) : []
}, { immediate: true })

function sync() {
    const made = Object.fromEntries(rows.value.filter(r => r.key.trim() !== '').map(r => [r.key.trim(), r.value]))
    emitted = JSON.stringify(made)
    model.value = made
}

function add() {
    rows.value = [...rows.value, { key: '', value: '' }]
}

function remove(index: number) {
    rows.value = rows.value.filter((_, i) => i !== index)
    sync()
}

const repeated = computed(() => {
    const seen = new Set<string>()
    const twice = new Set<string>()
    for (const row of rows.value) {
        const key = row.key.trim()
        if (key && seen.has(key)) twice.add(key)
        seen.add(key)
    }
    return [...twice]
})
const messages = computed(() => [...(repeated.value.length ? [t('fields.keyValue.repeated', { keys: repeated.value.join(', ') })] : []), ...props.errorMessages])
</script>

<template>
    <fieldset class="vx-fieldset" :aria-label="field.label">
        <div class="text-body-2 text-medium-emphasis mb-1">{{ field.label }}</div>
        <fieldset v-for="(row, index) in rows" :key="index" class="vx-fieldset d-flex ga-2 align-start mb-1" :aria-label="t('fields.keyValue.entry', { n: index + 1 })">
            <v-text-field
                v-model="row.key"
                :label="keyLabel"
                density="compact"
                hide-details
                :disabled="disabled"
                @update:model-value="sync"
            />
            <v-text-field
                v-model="row.value"
                :label="valueLabel"
                density="compact"
                hide-details
                :disabled="disabled"
                @update:model-value="sync"
            />
            <v-btn icon="mdi-close" variant="text" size="small" class="mt-1" :disabled="disabled" :aria-label="t('fields.keyValue.remove', { n: index + 1 })" @click="remove(index)" />
        </fieldset>
        <v-btn variant="text" size="small" prepend-icon="mdi-plus" :disabled="disabled || rows.length >= max" @click="add">{{ t('fields.keyValue.add') }}</v-btn>
        <div v-for="message in messages" :key="message" class="text-error text-caption" role="alert">{{ message }}</div>
        <div v-if="field.help" class="text-caption text-medium-emphasis">{{ field.help }}</div>
    </fieldset>
</template>

<style scoped>
/* a fieldset that groups without a fieldset's own border, padding and margin */
.vx-fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
</style>
