<script setup lang="ts">
import { fieldProps, type VectorField } from '#vector/app/types/vector'

const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string }>()
const model = defineModel<number | null>()
const decimals = computed(() => {
    const d = fieldProps(props.field).decimals
    if (typeof d === 'number') return d
    return props.field.valueType === 'float' ? 2 : 0
})
</script>

<template>
    <v-number-input
        v-model="model"
        :label="field.label"
        :rules="rules"
        :error-messages="errorMessages"
        :precision="decimals"
        :step="decimals > 0 ? 1 / 10 ** decimals : 1"
        :hint="field.help ?? undefined"
        :persistent-hint="!!field.help"
        control-variant="stacked"
    />
</template>
