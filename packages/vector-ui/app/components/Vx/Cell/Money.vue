<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

const props = defineProps<{ field: VectorField, value: unknown, record: Record<string, unknown> }>()
const text = computed(() => {
    if (typeof props.value !== 'number') return ''
    const currency = currencyOf(props.field, props.record)
    return currency ? formatMoney(props.value, currency) : formatNumber(props.value / 100, 2)
})
const { t } = useVectorT()
</script>

<template>
    <span v-if="text === ''" class="text-disabled">{{ t('common.none') }}</span>
    <span v-else class="text-no-wrap font-weight-medium">{{ text }}</span>
</template>
