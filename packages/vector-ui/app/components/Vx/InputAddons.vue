<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// The addons extensions and layers registered for this field's type
// (defineInputAddon), rendered under its input in the record form and in
// a loose field list. Nothing at all when there are none.
const props = defineProps<{
    field: VectorField
    /** The resource's slug; empty in a settings group. */
    resource: string
    record: Record<string, unknown> | null | undefined
    modelValue: unknown
    disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: unknown] }>()
const addons = useVectorInputAddons(() => props.field.type)
</script>

<template>
    <div v-if="addons.length" class="vx-input-addons d-flex flex-wrap align-center ga-2 mt-n2 mb-2">
        <component
            :is="addon.component"
            v-for="addon in addons"
            :key="addon.name"
            :data-input-addon="addon.name"
            :field="field"
            :resource="resource"
            :record="record ?? null"
            :model-value="modelValue"
            :disabled="disabled === true"
            @update:model-value="(v: unknown) => emit('update:modelValue', v)"
        />
    </div>
</template>
