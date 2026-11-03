<script setup lang="ts">
import type { VectorFailedJob, VectorQueuePermissions } from '#vector/app/types/vector'

// One failed job: GET /failed/{id}, its exception, message, trace (always
// null today) and redacted arguments, every one as text — interpolated,
// never v-html — and Retry and Forget where the permissions allow them.
const props = defineProps<{ id: string, permissions: VectorQueuePermissions }>()
const emit = defineEmits<{ back: [], changed: [] }>()

const { t } = useVectorT()
const queues = useVectorQueues()
const notifications = useVectorNotifications()
const { confirm } = useConfirm()

const job = ref<VectorFailedJob | null>(null)
const refused = ref(false)
const error = ref<string | null>(null)
const busy = ref(false)

onMounted(async () => {
    try {
        job.value = await queues.failedJob(props.id)
    } catch (caught) {
        if (queueErrorStatus(caught) === 403) refused.value = true
        else error.value = describeQueueError(caught)
    }
})

async function ask(title: string, text: string): Promise<boolean> {
    if (busy.value) return false
    return await confirm({ title, text, confirmText: t('queues.actions.confirm'), cancelText: t('queues.actions.cancel'), destructive: true })
}

async function retry() {
    if (!await ask(t('queues.failed.retryTitle', { count: 1 }), t('queues.failed.retryText'))) return
    busy.value = true
    try {
        const results = await queues.retry([props.id])
        const summary = queueRetrySummary(results)
        if (summary.ok) notifications.success(summary.text)
        else notifications.error(summary.text)
        emit('changed')
        if (results.every(result => result.status !== 'failed')) emit('back')
    } catch (caught) {
        notifications.error(describeQueueError(caught))
    } finally {
        busy.value = false
    }
}

async function forget() {
    if (!await ask(t('queues.failed.forgetTitle'), t('queues.failed.forgetText'))) return
    busy.value = true
    try {
        await queues.forget(props.id)
        notifications.success(t('queues.failed.forgotten'))
        emit('changed')
        emit('back')
    } catch (caught) {
        notifications.error(describeQueueError(caught))
    } finally {
        busy.value = false
    }
}

const preStyle = 'white-space: pre-wrap; word-break: break-word; max-height: 24rem; overflow: auto'
</script>

<template>
    <div>
        <div class="d-flex align-center ga-2 flex-wrap mb-3">
            <v-btn variant="text" prepend-icon="mdi-arrow-left" @click="emit('back')">{{ t('queues.actions.back') }}</v-btn>
            <v-spacer />
            <template v-if="job">
                <v-btn v-if="permissions.retry" variant="tonal" color="primary" prepend-icon="mdi-replay" :loading="busy" data-action="retry" @click="retry">{{ t('queues.failed.retry') }}</v-btn>
                <v-btn v-if="permissions.forget" variant="tonal" color="error" prepend-icon="mdi-delete-outline" :loading="busy" data-action="forget" @click="forget">{{ t('queues.failed.forget') }}</v-btn>
            </template>
        </div>
        <v-alert v-if="refused" type="warning" variant="tonal" :text="t('queues.noAccess')" data-state="no-access" />
        <v-alert v-else-if="error" type="error" variant="tonal" :text="error" />
        <v-progress-linear v-else-if="job === null" indeterminate color="primary" :aria-label="t('queues.loading')" />
        <div v-else>
            <h2 class="text-h6 mb-3">{{ t('queues.failed.detail.title', { id: job.id }) }}</h2>
            <div class="mb-2"><div class="text-caption text-medium-emphasis">{{ t('queues.failed.class') }}</div><div>{{ job.class }}</div></div>
            <div class="mb-2"><div class="text-caption text-medium-emphasis">{{ t('queues.failed.queue') }}</div><div>{{ job.queue }}</div></div>
            <div class="mb-2"><div class="text-caption text-medium-emphasis">{{ t('queues.failed.detail.connection') }}</div><div>{{ job.connection ?? t('queues.none') }}</div></div>
            <div class="mb-2"><div class="text-caption text-medium-emphasis">{{ t('queues.failed.attempts') }}</div><div>{{ queueAttempts(job) }}</div></div>
            <div class="mb-2"><div class="text-caption text-medium-emphasis">{{ t('queues.failed.failedAt') }}</div><div>{{ queueTime(job.failedAt) }}</div></div>
            <div class="mb-2"><div class="text-caption text-medium-emphasis">{{ t('queues.failed.exception') }}</div><div>{{ job.exception.class }}</div></div>
            <div class="mb-3">
                <div class="text-caption text-medium-emphasis mb-1">{{ t('queues.failed.detail.message') }}</div>
                <pre class="pa-2 rounded bg-surface-variant" :style="preStyle" data-field="message">{{ job.exception.message }}</pre>
            </div>
            <div class="mb-3">
                <div class="text-caption text-medium-emphasis mb-1">{{ t('queues.failed.detail.trace') }}</div>
                <pre class="pa-2 rounded bg-surface-variant" :style="preStyle">{{ job.exception.trace ?? t('queues.failed.detail.noTrace') }}</pre>
            </div>
            <div class="mb-3">
                <div class="text-caption text-medium-emphasis mb-1">{{ t('queues.failed.detail.args') }}</div>
                <pre class="pa-2 rounded bg-surface-variant" :style="preStyle" data-field="args">{{ JSON.stringify(job.args, null, 2) }}</pre>
            </div>
        </div>
    </div>
</template>
