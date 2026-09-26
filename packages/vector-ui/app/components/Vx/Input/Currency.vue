<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// A currency chosen from the field's codes, each named in the UI's
// language and searchable by code or name.
const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string }>()
const model = defineModel<string | null>()
const { locale } = useVectorT()

const items = computed(() => (props.field.options ?? []).map(o => ({ value: String(o.value), title: currencyLabel(String(o.value), locale.value) })))
</script>

<template>
    <v-autocomplete
        v-model="model"
        :label="field.label"
        :items="items"
        :rules="rules"
        :error-messages="errorMessages"
        :clearable="field.nullable === true"
        :hint="field.help ?? undefined"
        :persistent-hint="!!field.help"
        auto-select-first
    />
</template>
