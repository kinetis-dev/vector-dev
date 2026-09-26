<script setup lang="ts">
import type { VectorWidgetData } from '#vector/app/types/vector'

// A sparkline drawn as inline SVG: no chart library, CSP-clean.
const props = defineProps<{ data: VectorWidgetData }>()
const { t } = useVectorT()
const width = 600
const height = 140
const points = computed(() => props.data.points ?? [])
const max = computed(() => Math.max(1, ...points.value.map(p => p.y)))
const path = computed(() => {
    const n = points.value.length
    if (n === 0) return ''
    const step = n > 1 ? width / (n - 1) : width
    return points.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${(i * step).toFixed(1)},${(height - (p.y / max.value) * (height - 10) - 5).toFixed(1)}`).join(' ')
})
const area = computed(() => path.value ? `${path.value} L${width},${height} L0,${height} Z` : '')
const hover = ref<number | null>(null)
</script>

<template>
    <div>
        <div class="d-flex justify-space-between text-caption text-medium-emphasis mb-1">
            <span>{{ points[0]?.x ?? '' }}</span>
            <span v-if="data.total !== null && data.total !== undefined" class="font-weight-medium">{{ t('dashboard.trend.total', { value: formatWidgetValue(data.total, data.format) }) }}</span>
            <span>{{ points[points.length - 1]?.x ?? '' }}</span>
        </div>
        <svg :viewBox="`0 0 ${width} ${height}`" preserveAspectRatio="none" class="w-100" style="height: 140px" @mouseleave="hover = null">
            <path :d="area" fill="rgb(var(--v-theme-primary))" fill-opacity="0.12" />
            <path :d="path" fill="none" stroke="rgb(var(--v-theme-primary))" stroke-width="2" vector-effect="non-scaling-stroke" />
            <rect v-for="(p, i) in points" :key="p.x" :x="(i * (width / Math.max(1, points.length - 1))) - width / Math.max(1, points.length - 1) / 2" y="0" :width="width / Math.max(1, points.length - 1)" :height="height" fill="transparent" @mouseenter="hover = i" />
        </svg>
        <div class="text-caption text-center" style="min-height: 1.2em">
            <template v-if="hover !== null && points[hover]">{{ points[hover]!.x }}: <strong>{{ formatWidgetValue(points[hover]!.y, data.format) }}</strong></template>
        </div>
    </div>
</template>
