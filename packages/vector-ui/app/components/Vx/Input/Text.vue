<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// `disabled` stays undefined when not given, so a disabled v-form still disables the field
const props = withDefaults(defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string, disabled?: boolean }>(), { record: undefined, resource: undefined, disabled: undefined })
const model = defineModel<string | null>()
const type = computed(() => ({ email: 'email', url: 'url', password: 'password' } as Record<string, string>)[props.field.type] ?? 'text')
// the input addons registered inside the field (defineInputAddon placement `inner`), at its inline end
const innerAddons = useVectorInputAddons(() => props.field.type, () => 'inner')
</script>

<template>
    <v-text-field
        v-model="model"
        :label="field.label"
        :type="type"
        :rules="rules"
        :error-messages="errorMessages"
        :hint="field.help ?? undefined"
        :placeholder="field.placeholder ?? undefined"
        :persistent-hint="!!field.help"
        :clearable="field.nullable === true"
        :disabled="props.disabled"
        :prepend-inner-icon="field.type === 'color' ? 'mdi-palette' : undefined"
    >
        <template v-if="innerAddons.length" #append-inner>
            <VxInputAddons
                placement="inner"
                :field="field"
                :resource="resource ?? ''"
                :record="record ?? null"
                :model-value="model"
                :disabled="props.disabled === true"
                @update:model-value="(v: unknown) => { model = typeof v === 'string' ? v : null }"
            />
        </template>
    </v-text-field>
</template>
