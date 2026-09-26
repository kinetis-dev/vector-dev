<script setup lang="ts">
import type { VectorJob } from '#vector/app/types/vector'

definePageMeta({ layout: 'vector', middleware: 'vector-auth', key: route => route.fullPath })

const route = useRoute()
const appConfig = useAppConfig()
const jobs = useVectorJobs()
const { t } = useVectorT()
const id = Number(route.params.id)
const job = ref<VectorJob | null>(null)
const error = ref<string | null>(null)

onMounted(async () => {
    try {
        await jobs.watch(id, j => (job.value = j))
    } catch (caught) {
        error.value = vectorErrorMessage(caught)
    }
})

useHead({ title: () => (job.value ? t('jobs.detail.pageTitle', { id: job.value.id }) : t('jobs.detail.fallbackTitle')) })
const percent = computed(() => (job.value && job.value.total ? Math.min(100, (job.value.processed / job.value.total) * 100) : null))
</script>

<template>
    <div>
        <v-breadcrumbs class="pa-0 mb-2" :items="[{ title: t('jobs.title'), to: `${appConfig.vector.basePath}/jobs` }, { title: `#${id}` }]" />
        <v-alert v-if="error" type="error" variant="tonal" :text="error" />
        <v-card v-else-if="job">
            <v-card-item>
                <v-card-title class="text-capitalize">{{ t(`jobs.kind.${job.kind}`) }} · {{ job.name }} <v-chip size="small" class="ml-2" :color="{ queued: 'grey', running: 'info', done: 'success', failed: 'error' }[job.status]" variant="tonal">{{ t(`jobs.status.${job.status}`) }}</v-chip></v-card-title>
                <v-card-subtitle>{{ job.resource }} · {{ t('jobs.detail.started', { date: formatDateTime(job.createdAt) }) }}<template v-if="job.finishedAt"> · {{ t('jobs.detail.finished', { date: formatDateTime(job.finishedAt) }) }}</template></v-card-subtitle>
            </v-card-item>
            <v-card-text>
                <v-progress-linear :model-value="percent ?? 0" :indeterminate="percent === null && job.status !== 'done' && job.status !== 'failed'" color="primary" height="10" rounded class="mb-2" :aria-label="t('jobs.detail.progress')" />
                <div class="text-body-2 mb-4">{{ job.total !== null ? t('jobs.detail.processedOfTotal', { processed: job.processed, total: job.total }) : t('jobs.detail.processed', { processed: job.processed }) }}</div>
                <v-alert v-if="job.failure" type="error" variant="tonal" :text="job.failure" class="mb-4" />
                <template v-if="job.result">
                    <div v-for="(value, key) in job.result" :key="key" class="text-body-2"><span class="text-medium-emphasis">{{ key }}:</span> {{ value }}</div>
                </template>
            </v-card-text>
            <v-card-actions v-if="job.hasFile && job.status === 'done'">
                <v-btn color="primary" prepend-icon="mdi-download-outline" @click="jobs.download(job)">{{ t('jobs.detail.download', { file: job.fileName ?? t('jobs.detail.file') }) }}</v-btn>
            </v-card-actions>
        </v-card>
        <v-skeleton-loader v-else type="article" />
    </div>
</template>
