<script setup lang="ts">
import type { VectorQueuePermissions } from '#vector/app/types/vector'

// The queue dashboard: the overview, the failed jobs (where the descriptor's
// capabilities.failedJobs says they are there) and the job metrics (where
// capabilities.queueMetrics does: VECTOR_QUEUE_METRICS off, there is no tab
// and GET /vector/queues/metrics is never asked), each a tab, only the open
// one mounted, so the overview polls only while it is shown. The permissions the policy grants decide which actions each offers;
// a user the dashboard is not there for (its routes answer 404) sees why.
definePageMeta({ layout: 'vector', middleware: 'vector-auth' })

const { t } = useVectorT()
const { schema } = useVectorSchema()
const queues = useVectorQueues()

const tab = ref<'overview' | 'failed' | 'metrics'>('overview')
const permissions = ref<VectorQueuePermissions | null>(null)
const refused = ref(false)
const error = ref<string | null>(null)
const failedJobs = computed(() => schema.value?.capabilities.failedJobs === true)
const queueMetrics = computed(() => schema.value?.capabilities.queueMetrics === true)
const names = computed(() => Object.keys(permissions.value?.clear ?? {}))

// failed jobs gone since the tab was opened (the descriptor reloaded): back to the overview
watch(failedJobs, on => { if (!on && tab.value === 'failed') tab.value = 'overview' })
// and the same for the metrics, turned off since
watch(queueMetrics, on => { if (!on && tab.value === 'metrics') tab.value = 'overview' })

onMounted(async () => {
    try {
        permissions.value = await queues.permissions()
    } catch (caught) {
        if (queueAccessRefused(caught)) refused.value = true
        else error.value = describeQueueError(caught)
    }
})

useHead({ title: () => t('queues.title') })
</script>

<template>
    <div>
        <h1 class="text-h4 mb-4">{{ t('queues.title') }}</h1>
        <v-alert v-if="refused" type="warning" variant="tonal" :text="t('queues.noAccess')" data-state="no-access" />
        <v-alert v-else-if="error" type="error" variant="tonal" :text="error" />
        <v-progress-linear v-else-if="permissions === null" indeterminate color="primary" :aria-label="t('queues.loading')" />
        <template v-else>
            <v-tabs v-model="tab" class="mb-4">
                <v-tab value="overview" data-tab="overview">{{ t('queues.tabs.overview') }}</v-tab>
                <v-tab v-if="failedJobs" value="failed" data-tab="failed">{{ t('queues.tabs.failed') }}</v-tab>
                <v-tab v-if="queueMetrics" value="metrics" data-tab="metrics">{{ t('queues.tabs.metrics') }}</v-tab>
            </v-tabs>
            <VxQueuesOverview v-if="tab === 'overview'" :permissions="permissions" :failed-jobs="failedJobs" />
            <VxQueuesFailed v-else-if="tab === 'failed' && failedJobs" :permissions="permissions" :queues="names" />
            <VxQueuesMetrics v-else-if="tab === 'metrics' && queueMetrics" :queues="names" />
        </template>
    </div>
</template>
