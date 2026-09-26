<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string }>()
const model = defineModel<string | number | null>()
</script>

<template>
    <v-select
        v-model="model"
        :label="field.label"
        :items="field.options ?? []"
        item-title="label"
        item-value="value"
        :rules="rules"
        :error-messages="errorMessages"
        :clearable="field.nullable === true"
        :hint="field.help ?? undefined"
        :persistent-hint="!!field.help"
    />
</template>
