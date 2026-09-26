<script setup lang="ts">
import type { VectorWidgetData } from '#vector/app/types/vector'

const props = defineProps<{ data: VectorWidgetData }>()
const { t } = useVectorT()
const slices = computed(() => props.data.slices ?? [])
const total = computed(() => slices.value.reduce((sum, s) => sum + s.value, 0))
</script>

<template>
    <div>
        <div class="d-flex rounded overflow-hidden mb-3" style="height: 12px">
            <div v-for="slice in slices" :key="slice.label" :class="`bg-${slice.color ?? 'primary'}`" :style="{ width: `${total ? (slice.value / total) * 100 : 0}%` }" :title="slice.label" />
        </div>
        <div v-for="slice in slices" :key="slice.label" class="d-flex justify-space-between text-body-2 py-1">
            <span class="d-flex align-center ga-2"><v-icon size="x-small" icon="mdi-circle" :color="slice.color ?? 'primary'" />{{ slice.label }}</span>
            <span class="font-weight-medium">{{ formatWidgetValue(slice.value, data.format) }} <span class="text-medium-emphasis">({{ t('dashboard.percent', { value: total ? Math.round((slice.value / total) * 100) : 0 }) }})</span></span>
        </div>
        <div v-if="slices.length === 0" class="text-medium-emphasis text-body-2">{{ t('dashboard.partition.empty') }}</div>
    </div>
</template>
