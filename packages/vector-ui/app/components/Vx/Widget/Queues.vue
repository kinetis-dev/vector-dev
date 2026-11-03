<script setup lang="ts">
import type { VectorWidgetData } from '#vector/app/types/vector'

// The queue dashboard's card, the built-in `queues` type: the jobs waiting
// and failed across the watched queues, read from GET /vector/queues itself
// (the widget's own answer carries no data), and a link to the Queues page.
// The failed figure is there only with failed jobs: the overview's `failed`
// is null without them.
defineProps<{ data: VectorWidgetData }>()

const { t } = useVectorT()
const appConfig = useAppConfig()
const queues = useVectorQueues()
const totals = ref<{ pending: number | null, failed: number | null } | null>(null)
const refused = ref(false)
const error = ref<string | null>(null)

onMounted(async () => {
    try {
        const rows = await queues.overview()
        const known = rows.filter(row => row.size !== null)
        const counted = rows.filter(row => row.failed !== null)
        totals.value = {
            pending: known.length === 0 ? null : known.reduce((sum, row) => sum + (row.size ?? 0), 0),
            failed: counted.length === 0 ? null : counted.reduce((sum, row) => sum + (row.failed ?? 0), 0),
        }
    } catch (caught) {
        if (queueAccessRefused(caught)) refused.value = true
        else error.value = describeQueueError(caught)
    }
})
</script>

<template>
    <v-alert v-if="refused" type="warning" variant="tonal" density="compact" :text="t('queues.noAccess')" data-state="no-access" />
    <v-alert v-else-if="error" type="error" variant="tonal" density="compact" :text="error" />
    <v-progress-linear v-else-if="totals === null" indeterminate color="primary" :aria-label="t('queues.loading')" />
    <div v-else>
        <div class="d-flex ga-4 mb-2">
            <div class="flex-grow-1" data-figure="pending">
                <div class="text-h4" style="font-variant-numeric: tabular-nums">{{ queueNumber(totals.pending) }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('queues.widget.pending') }}</div>
            </div>
            <div v-if="totals.failed !== null" class="flex-grow-1" data-figure="failed">
                <div class="text-h4" :class="{ 'text-error': totals.failed > 0 }" style="font-variant-numeric: tabular-nums">{{ queueNumber(totals.failed) }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('queues.widget.failed') }}</div>
            </div>
        </div>
        <v-btn variant="text" size="small" append-icon="mdi-arrow-right" class="px-0" :to="`${appConfig.vector.basePath}/queues`">{{ t('queues.widget.open') }}</v-btn>
    </div>
</template>
