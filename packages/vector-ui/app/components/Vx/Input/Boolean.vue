<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string }>()
const model = defineModel<boolean | null>()
</script>

<template>
    <v-switch v-model="model" :label="field.label" :error-messages="errorMessages" :hint="field.help ?? undefined" :persistent-hint="!!field.help" color="primary" inset />
</template>
