<script setup lang="ts">
import type { VectorQueue, VectorQueuePermissions } from '#vector/app/types/vector'

// The overview: GET /vector/queues every QUEUE_POLL_MS while the page is
// visible, one request at a time — the next is scheduled only once the last
// settled — and never after the tab is unmounted or once the server refused
// the dashboard. The Failed column is there only with failed jobs.
const props = defineProps<{ permissions: VectorQueuePermissions, failedJobs: boolean }>()

const { t } = useVectorT()
const queues = useVectorQueues()
const notifications = useVectorNotifications()
const { confirm } = useConfirm()

const rows = ref<VectorQueue[] | null>(null)
const refused = ref(false)
const error = ref<string | null>(null)
const busy = ref(false)
let timer: ReturnType<typeof setTimeout> | null = null
let inFlight = false
let again = false
let stopped = false

function unschedule() {
    if (timer !== null) clearTimeout(timer)
    timer = null
}

function schedule() {
    unschedule()
    if (!stopped && !document.hidden) timer = setTimeout(refresh, QUEUE_POLL_MS)
}

async function refresh() {
    if (inFlight) {
        again = true
        return
    }
    unschedule()
    if (stopped) return
    inFlight = true
    try {
        rows.value = await queues.overview()
        error.value = null
    } catch (caught) {
        if (queueAccessRefused(caught)) {
            refused.value = true
            stopped = true
        } else {
            error.value = describeQueueError(caught)
        }
    } finally {
        inFlight = false
        if (again) {
            again = false
            void refresh()
        } else {
            schedule()
        }
    }
}

function onVisibility() {
    if (document.hidden) unschedule()
    else if (!inFlight) void refresh()
}

onMounted(() => {
    document.addEventListener('visibilitychange', onVisibility)
    void refresh()
})

onBeforeUnmount(() => {
    stopped = true
    unschedule()
    document.removeEventListener('visibilitychange', onVisibility)
})

const canClear = (row: VectorQueue) => props.permissions.clear[row.name] === true

async function clear(queue: string) {
    if (busy.value) return
    const confirmed = await confirm({
        title: t('queues.overview.clearTitle', { queue }),
        text: t('queues.overview.clearText', { queue }),
        confirmText: t('queues.actions.confirm'),
        cancelText: t('queues.actions.cancel'),
        destructive: true,
    })
    if (!confirmed) return

    busy.value = true
    try {
        const cleared = await queues.clear(queue)
        notifications.success(t('queues.overview.cleared', { queue, count: cleared }))
    } catch (caught) {
        const status = queueErrorStatus(caught)
        notifications.error(status === 409
            ? t('queues.overview.clearUnsupported', { queue })
            : status === 422 ? t('queues.overview.clearInvalid', { queue, detail: vectorErrorMessage(caught) }) : describeQueueError(caught))
    } finally {
        busy.value = false
    }
    void refresh()
}
</script>

<template>
    <v-alert v-if="refused" type="warning" variant="tonal" :text="t('queues.noAccess')" data-state="no-access" />
    <template v-else-if="rows === null">
        <v-alert v-if="error" type="error" variant="tonal" :text="error" />
        <v-progress-linear v-else indeterminate color="primary" :aria-label="t('queues.loading')" />
    </template>
    <div v-else>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3" :text="error" />
        <p v-if="rows.length === 0" class="text-medium-emphasis">{{ t('queues.overview.empty') }}</p>
        <v-table v-else hover>
            <thead>
                <tr>
                    <th scope="col" class="text-start">{{ t('queues.overview.queue') }}</th>
                    <th scope="col" class="text-end">{{ t('queues.overview.size') }}</th>
                    <th v-if="failedJobs" scope="col" class="text-end" data-column="failed">{{ t('queues.overview.failed') }}</th>
                    <th scope="col" class="text-end" />
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.name" :data-queue="row.name">
                    <td>{{ row.name }}</td>
                    <td class="text-end" style="font-variant-numeric: tabular-nums">{{ row.size === null ? t('queues.overview.unknownSize') : queueNumber(row.size) }}</td>
                    <td v-if="failedJobs" class="text-end" style="font-variant-numeric: tabular-nums">
                        <v-chip v-if="(row.failed ?? 0) > 0" size="small" color="error" variant="tonal" label>{{ queueNumber(row.failed) }}</v-chip>
                        <template v-else>{{ queueNumber(row.failed) }}</template>
                    </td>
                    <td class="text-end">
                        <template v-if="canClear(row)">
                            <v-btn
                                v-if="row.clearable"
                                size="small"
                                variant="tonal"
                                color="error"
                                prepend-icon="mdi-delete-sweep-outline"
                                :loading="busy"
                                data-action="clear"
                                @click="clear(row.name)"
                            >{{ t('queues.overview.clear') }}</v-btn>
                            <!-- a disabled button receives no pointer events: the tooltip hangs on a wrapper -->
                            <v-tooltip v-else :text="t('queues.overview.clearUnavailable')" location="top">
                                <template #activator="{ props: activator }">
                                    <span v-bind="activator">
                                        <v-btn size="small" variant="tonal" color="error" prepend-icon="mdi-delete-sweep-outline" disabled data-action="clear">{{ t('queues.overview.clear') }}</v-btn>
                                    </span>
                                </template>
                            </v-tooltip>
                        </template>
                    </td>
                </tr>
            </tbody>
        </v-table>
    </div>
</template>
