<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// A native date/datetime control: the wire value is ISO 8601 in UTC, the
// control edits local time, and the conversion lives here.
const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string }>()
const model = defineModel<string | null>()
const dateOnly = computed(() => props.field.type === 'date')

function pad(n: number) { return String(n).padStart(2, '0') }

const local = computed({
    get: () => {
        if (!model.value) return ''
        if (dateOnly.value) return model.value.slice(0, 10)
        const d = new Date(model.value)
        if (Number.isNaN(d.getTime())) return ''
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
    },
    set: (value: string) => {
        if (!value) { model.value = null; return }
        model.value = dateOnly.value ? value : new Date(value).toISOString()
    },
})
</script>

<template>
    <v-text-field
        v-model="local"
        :label="field.label"
        :type="dateOnly ? 'date' : 'datetime-local'"
        :rules="rules"
        :error-messages="errorMessages"
        :clearable="field.nullable === true"
        :hint="field.help ?? undefined"
        :persistent-hint="!!field.help"
    />
</template>
