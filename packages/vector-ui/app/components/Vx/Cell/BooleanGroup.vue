<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// A flag group: the labels of the options that are on, as chips.
const props = defineProps<{ field: VectorField, value: unknown }>()
const { t } = useVectorT()

const on = computed(() => {
    const flags = props.value !== null && typeof props.value === 'object' && !Array.isArray(props.value) ? props.value as Record<string, unknown> : {}

    return (props.field.options ?? []).filter(option => flags[String(option.value)] === true)
})
</script>

<template>
    <span v-if="on.length === 0" class="text-disabled">{{ t('common.none') }}</span>
    <span v-else class="d-inline-flex flex-wrap align-center ga-1">
        <v-chip v-for="option in on" :key="String(option.value)" size="small" variant="tonal" label>{{ option.label }}</v-chip>
    </span>
</template>
