<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'
import type { VectorInputAddonPlacement } from '#vector/app/composables/useVectorExtensions'

// The addons extensions and layers registered for this field's type
// (defineInputAddon) at one placement: `below`, in a row under its input
// in the record form and in a loose field list; `inner`, inline inside a
// text or textarea input at its end (Vuetify's append-inner slot). Nothing
// at all when there are none.
const props = withDefaults(defineProps<{
    field: VectorField
    /** The resource's slug; empty in a settings group. */
    resource: string
    record: Record<string, unknown> | null | undefined
    modelValue: unknown
    disabled?: boolean
    placement?: VectorInputAddonPlacement
}>(), { disabled: false, placement: 'below' })

const emit = defineEmits<{ 'update:modelValue': [value: unknown] }>()
const addons = useVectorInputAddons(() => props.field.type, () => props.placement)
</script>

<template>
    <div
        v-if="addons.length"
        :class="placement === 'inner'
            ? 'vx-input-addons vx-input-addons--inner d-inline-flex align-center ga-1'
            : 'vx-input-addons d-flex flex-wrap align-center ga-2 mt-n2 mb-2'"
        :data-input-addons="placement === 'inner' ? 'inner' : undefined"
    >
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
