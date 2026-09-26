<script setup lang="ts">
import type { VectorWidgetData } from '#vector/app/types/vector'

const props = defineProps<{ data: VectorWidgetData }>()
const { t } = useVectorT()
const percent = computed(() => {
    const value = props.data.value ?? 0
    const target = props.data.target ?? 0
    return target > 0 ? Math.min(100, (value / target) * 100) : 0
})
</script>

<template>
    <div>
        <div class="d-flex justify-space-between align-baseline mb-2">
            <span class="text-h5 font-weight-medium">{{ formatWidgetValue(data.value, data.format) }}</span>
            <span class="text-caption text-medium-emphasis">{{ t('dashboard.progress.of', { target: formatWidgetValue(data.target, data.format) }) }}</span>
        </div>
        <v-progress-linear :model-value="percent" color="primary" height="10" rounded :aria-label="data.caption ?? t('dashboard.progress.label')" />
        <div v-if="data.caption" class="text-caption text-medium-emphasis mt-2">{{ data.caption }}</div>
    </div>
</template>
