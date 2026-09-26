<script setup lang="ts">
import type { VectorField, VectorResource } from '#vector/app/types/vector'

// Dispatches a value to the component the field registry names. A
// built-in arrives as a component; an app override arrives as the name
// of a globally registered one and is resolved here, in setup.
const props = defineProps<{
    field: VectorField
    value: unknown
    record: Record<string, unknown>
    resource: VectorResource
    context: 'index' | 'show'
}>()

const { cellFor } = useVectorFields()
const component = computed(() => {
    const cell = cellFor(props.field)

    return typeof cell === 'string' ? resolveComponent(cell) : cell
})
</script>

<template>
    <component :is="component" :field="field" :value="value" :record="record" :resource="resource" :context="context" />
</template>
