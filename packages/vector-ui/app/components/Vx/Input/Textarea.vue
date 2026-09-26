<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string }>()
const model = defineModel<string | null>()
</script>

<template>
    <v-textarea
        v-model="model"
        :label="field.label"
        :rules="rules"
        :error-messages="errorMessages"
        :hint="field.help ?? undefined"
        :persistent-hint="!!field.help"
        :class="{ 'font-monospace': props.field.type === 'json' }"
        auto-grow
        rows="3"
    />
</template>
