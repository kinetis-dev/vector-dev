<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string }>()
const model = defineModel<string | null>()
const type = computed(() => ({ email: 'email', url: 'url', password: 'password' } as Record<string, string>)[props.field.type] ?? 'text')
</script>

<template>
    <v-text-field
        v-model="model"
        :label="field.label"
        :type="type"
        :rules="rules"
        :error-messages="errorMessages"
        :hint="field.help ?? undefined"
        :persistent-hint="!!field.help"
        :clearable="field.nullable === true"
        :prepend-inner-icon="field.type === 'color' ? 'mdi-palette' : undefined"
    />
</template>
