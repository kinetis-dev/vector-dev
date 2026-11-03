<script setup lang="ts">
import type { VectorQueueMetrics } from '#vector/app/types/vector'

// The job metrics: GET /metrics for a watched queue (or all) over a window
// of at most 7 days, the last hour by default — the window's totals,
// attempts per minute as an inline SVG sparkline (no chart library), and
// the totals per queue and per job class.
const props = defineProps<{ queues: string[] }>()

const { t } = useVectorT()
const api = useVectorQueues()

const queue = ref('')
const minutes = ref<number>(QUEUE_DEFAULT_WINDOW)
const metrics = ref<VectorQueueMetrics | null>(null)
const refused = ref(false)
const error = ref<string | null>(null)
let request = 0

async function load() {
    const id = ++request
    try {
        const data = await api.metrics(queue.value, minutes.value)
        if (id !== request) return
        metrics.value = data
        error.value = null
    } catch (caught) {
        if (id !== request) return
        if (queueAccessRefused(caught)) refused.value = true
        else error.value = describeQueueError(caught)
    }
}

onMounted(load)
watch([queue, minutes], () => void load())

const totals = computed(() => queueMetricsTotals(metrics.value))
const spark = computed(() => queueSparkline(metrics.value?.buckets ?? []))
// the chart's own words for a screen reader: which window, which queue, how many attempts in all
const sparkLabel = computed(() => t('queues.metrics.throughputLabel', {
    window: t(`queues.metrics.windows.m${minutes.value}`),
    queue: queue.value === '' ? t('queues.metrics.allQueues') : queue.value,
    total: queueNumber(totals.value.succeeded + totals.value.released + totals.value.failed),
}))
const queueItems = computed(() => [{ title: t('queues.metrics.allQueues'), value: '' }, ...props.queues.map(name => ({ title: name, value: name }))])
const windowItems = computed(() => QUEUE_WINDOWS.map(value => ({ title: t(`queues.metrics.windows.m${value}`), value })))
const stats = computed(() => [
    { label: t('queues.metrics.succeeded'), value: queueNumber(totals.value.succeeded) },
    { label: t('queues.metrics.released'), value: queueNumber(totals.value.released) },
    { label: t('queues.metrics.failed'), value: queueNumber(totals.value.failed) },
    { label: t('queues.metrics.failureRate'), value: queueRate(totals.value.failureRate) },
    { label: t('queues.metrics.averageDuration'), value: queueMs(totals.value.averageDurationMs) },
    { label: t('queues.metrics.maxDuration'), value: queueMs(totals.value.maxDurationMs) },
])
const tables = computed(() => metrics.value === null ? [] : [
    { label: t('queues.metrics.byQueue'), heading: t('queues.metrics.queue'), rows: metrics.value.queues.map(row => ({ ...row, name: row.queue })) },
    { label: t('queues.metrics.byClass'), heading: t('queues.metrics.class'), rows: metrics.value.classes.map(row => ({ ...row, name: row.class })) },
])
</script>

<template>
    <v-alert v-if="refused" type="warning" variant="tonal" :text="t('queues.noAccess')" data-state="no-access" />
    <div v-else>
        <div class="d-flex flex-wrap align-center ga-2 mb-3">
            <v-select v-model="queue" :items="queueItems" :label="t('queues.metrics.queue')" density="compact" hide-details style="max-width: 14rem" />
            <v-select v-model="minutes" :items="windowItems" :label="t('queues.metrics.window')" density="compact" hide-details style="max-width: 14rem" />
            <v-btn variant="text" prepend-icon="mdi-refresh" @click="load">{{ t('queues.actions.refresh') }}</v-btn>
        </div>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3" :text="error" />
        <v-progress-linear v-if="metrics === null && !error" indeterminate color="primary" :aria-label="t('queues.loading')" />
        <div v-else-if="metrics !== null">
            <div class="d-flex flex-wrap ga-3 mb-4">
                <v-card v-for="stat in stats" :key="stat.label" variant="tonal" class="pa-3" style="min-width: 9rem">
                    <div class="text-caption text-medium-emphasis">{{ stat.label }}</div>
                    <div class="text-h6" style="font-variant-numeric: tabular-nums">{{ stat.value }}</div>
                </v-card>
            </div>
            <div class="mb-4 text-primary">
                <div class="text-caption text-medium-emphasis">{{ t('queues.metrics.throughput') }}</div>
                <svg :viewBox="`0 0 ${spark.width} 40`" preserveAspectRatio="none" width="100%" height="48" role="img" :aria-label="sparkLabel" data-chart="throughput">
                    <polyline :points="spark.points" fill="none" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                </svg>
            </div>
            <p v-if="metrics.queues.length === 0" class="text-medium-emphasis">{{ t('queues.metrics.empty') }}</p>
            <template v-else>
                <div v-for="table in tables" :key="table.label" class="mb-4">
                    <h3 class="text-subtitle-1 mb-1">{{ table.label }}</h3>
                    <v-table density="compact" hover>
                        <thead>
                            <tr>
                                <th scope="col" class="text-start">{{ table.heading }}</th>
                                <th scope="col" class="text-end">{{ t('queues.metrics.succeeded') }}</th>
                                <th scope="col" class="text-end">{{ t('queues.metrics.released') }}</th>
                                <th scope="col" class="text-end">{{ t('queues.metrics.failed') }}</th>
                                <th scope="col" class="text-end">{{ t('queues.metrics.failureRate') }}</th>
                                <th scope="col" class="text-end">{{ t('queues.metrics.averageDuration') }}</th>
                                <th scope="col" class="text-end">{{ t('queues.metrics.maxDuration') }}</th>
                                <th scope="col" class="text-start">{{ t('queues.metrics.lastProcessed') }}</th>
                            </tr>
                        </thead>
                        <tbody style="font-variant-numeric: tabular-nums">
                            <tr v-for="row in table.rows" :key="row.name">
                                <td style="word-break: break-all">{{ row.name }}</td>
                                <td class="text-end">{{ queueNumber(row.succeeded) }}</td>
                                <td class="text-end">{{ queueNumber(row.released) }}</td>
                                <td class="text-end">{{ queueNumber(row.failed) }}</td>
                                <td class="text-end">{{ queueRate(row.failureRate) }}</td>
                                <td class="text-end">{{ queueMs(row.averageDurationMs) }}</td>
                                <td class="text-end">{{ queueMs(row.maxDurationMs) }}</td>
                                <td>{{ queueTime(row.lastProcessedAt) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                </div>
            </template>
        </div>
    </div>
</template>
