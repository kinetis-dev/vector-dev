<script setup lang="ts">
import type { VectorWidgetData } from '#vector/app/types/vector'

const props = defineProps<{ data: VectorWidgetData }>()
const { t } = useVectorT()
const delta = computed(() => {
    const { value, previous } = props.data
    if (value === null || value === undefined || previous === null || previous === undefined || previous === 0) return null
    return ((value - previous) / Math.abs(previous)) * 100
})
</script>

<template>
    <div>
        <div class="text-h4 font-weight-medium">{{ formatWidgetValue(data.value, data.format) }}</div>
        <div class="d-flex align-center ga-2 mt-1">
            <v-chip v-if="delta !== null" size="x-small" :color="delta >= 0 ? 'success' : 'error'" variant="tonal" :prepend-icon="delta >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down'">{{ t('dashboard.percent', { value: Math.abs(delta).toFixed(1) }) }}</v-chip>
            <span v-if="data.previous !== null && data.previous !== undefined" class="text-caption text-medium-emphasis">{{ t('dashboard.stat.previous', { value: formatWidgetValue(data.previous, data.format) }) }}</span>
        </div>
        <div v-if="data.caption" class="text-caption text-medium-emphasis mt-1">{{ data.caption }}</div>
    </div>
</template>
