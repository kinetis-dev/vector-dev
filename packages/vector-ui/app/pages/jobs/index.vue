<script setup lang="ts">
import type { VectorJob } from '#vector/app/types/vector'

definePageMeta({ layout: 'vector', middleware: 'vector-auth' })

const appConfig = useAppConfig()
const jobs = useVectorJobs()
const { t } = useVectorT()
const { data, refresh } = await useAsyncData('vector.jobs', () => jobs.list())
const running = computed(() => (data.value ?? []).some(j => j.status === 'queued' || j.status === 'running'))
const { pause, resume } = useIntervalFn(() => refresh(), 3000, { immediate: false })
watch(running, r => (r ? resume() : pause()), { immediate: true })
onUnmounted(pause)

useHead({ title: () => t('jobs.title') })

const color: Record<VectorJob['status'], string> = { queued: 'grey', running: 'info', done: 'success', failed: 'error', stalled: 'warning' }
</script>

<template>
    <div>
        <h1 class="text-h4 mb-4">{{ t('jobs.heading') }}</h1>
        <v-card>
            <v-table hover>
                <thead>
                    <tr><th>{{ t('jobs.columns.job') }}</th><th>{{ t('jobs.columns.resource') }}</th><th>{{ t('jobs.columns.status') }}</th><th class="text-end">{{ t('jobs.columns.progress') }}</th><th>{{ t('jobs.columns.started') }}</th><th /></tr>
                </thead>
                <tbody>
                    <tr v-for="job in data ?? []" :key="job.id" style="cursor: pointer" @click="navigateTo(`${appConfig.vector.basePath}/jobs/${job.id}`)">
                        <td><span class="text-capitalize">{{ t(`jobs.kind.${job.kind}`) }}</span> <span class="text-medium-emphasis">{{ job.name }}</span></td>
                        <td>{{ job.resource }}</td>
                        <td><v-chip size="small" :color="color[job.status]" variant="tonal">{{ t(`jobs.status.${job.status}`) }}</v-chip></td>
                        <td class="text-end">{{ job.processed }}<template v-if="job.total !== null"> / {{ job.total }}</template></td>
                        <td>{{ formatDateTime(job.createdAt) }}</td>
                        <td class="text-end"><v-btn v-if="job.hasFile && job.status === 'done'" size="small" variant="text" icon="mdi-download-outline" :aria-label="t('common.download')" @click.stop="jobs.download(job)" /></td>
                    </tr>
                    <tr v-if="(data ?? []).length === 0"><td colspan="6" class="text-medium-emphasis">{{ t('jobs.empty') }}</td></tr>
                </tbody>
            </v-table>
        </v-card>
    </div>
</template>
