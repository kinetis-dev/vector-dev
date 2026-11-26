<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// A date range: "from to to", each end as a date or a date-time cell shows it.
const props = defineProps<{ field: VectorField, value: unknown }>()
const { t } = useVectorT()

const text = computed(() => {
    const range = props.value !== null && typeof props.value === 'object' && !Array.isArray(props.value) ? props.value as { from?: unknown, to?: unknown } : null
    if (typeof range?.from !== 'string' || typeof range.to !== 'string') return ''
    const format = props.field.range?.kind === 'datetime' ? formatDateTime : formatDate
    return t('fields.dateRange.between', { from: format(range.from), to: format(range.to) })
})
</script>

<template>
    <span v-if="text === ''" class="text-disabled">{{ t('common.none') }}</span>
    <span v-else class="text-no-wrap">{{ text }}</span>
</template>
