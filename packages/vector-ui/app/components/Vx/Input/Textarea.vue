<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// `disabled` stays undefined when not given, so a disabled v-form still disables the field
const props = withDefaults(defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string, disabled?: boolean }>(), { record: undefined, resource: undefined, disabled: undefined })
const model = defineModel<string | null>()
// the input addons registered inside the field (defineInputAddon placement `inner`), at its top inline end
const innerAddons = useVectorInputAddons(() => props.field.type, () => 'inner')
</script>

<template>
    <v-textarea
        v-model="model"
        :label="field.label"
        :rules="rules"
        :error-messages="errorMessages"
        :hint="field.help ?? undefined"
        :persistent-hint="!!field.help"
        :disabled="props.disabled"
        :class="{ 'font-monospace': props.field.type === 'json' }"
        auto-grow
        rows="3"
    >
        <template v-if="innerAddons.length" #append-inner>
            <VxInputAddons
                placement="inner"
                class="align-self-start"
                :field="field"
                :resource="resource ?? ''"
                :record="record ?? null"
                :model-value="model"
                :disabled="props.disabled === true"
                @update:model-value="(v: unknown) => { model = typeof v === 'string' ? v : null }"
            />
        </template>
    </v-textarea>
</template>
