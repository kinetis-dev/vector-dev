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
const { confirmTyped } = useConfirm()
const uid = useId()

const rows = ref<VectorQueue[] | null>(null)
const refused = ref(false)
const error = ref<string | null>(null)
// the queue being cleared: only its row's Clear shows as loading, and no second clear starts meanwhile
const acting = ref<string | null>(null)
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

/** Asks the user to type the queue's name, then sends what they typed as the server's `confirm`. */
async function clear(queue: string) {
    if (acting.value !== null) return
    const typed = await confirmTyped({
        title: t('queues.overview.clearTitle', { queue }),
        text: t('queues.overview.clearText', { queue }),
        confirmText: t('queues.actions.confirm'),
        cancelText: t('queues.actions.cancel'),
        destructive: true,
        requireText: queue,
        requireLabel: t('queues.overview.clearType', { queue }),
    })
    if (typed === null || acting.value !== null) return

    acting.value = queue
    try {
        const cleared = await queues.clear(queue, typed)
        notifications.success(t('queues.overview.cleared', { queue, count: cleared }))
    } catch (caught) {
        const status = queueErrorStatus(caught)
        notifications.error(status === 409
            ? t('queues.overview.clearUnsupported', { queue })
            : status === 422 ? t('queues.overview.clearInvalid', { queue, detail: vectorErrorMessage(caught) }) : describeQueueError(caught))
    } finally {
        acting.value = null
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
                    <th scope="col" class="text-end"><span class="d-sr-only">{{ t('common.actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in rows" :key="row.name" :data-queue="row.name">
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
                                :loading="acting === row.name"
                                data-action="clear"
                                @click="clear(row.name)"
                            >{{ t('queues.overview.clear') }}</v-btn>
                            <!-- a disabled button receives neither pointer events nor focus: the tooltip hangs on a
                                 wrapper the keyboard reaches, which also names the reason for a screen reader -->
                            <v-tooltip v-else :text="t('queues.overview.clearUnavailable')" location="top">
                                <template #activator="{ props: activator }">
                                    <span v-bind="activator" tabindex="0" :aria-describedby="`${uid}-unavailable-${index}`" class="d-inline-block" data-state="clear-unavailable">
                                        <v-btn size="small" variant="tonal" color="error" prepend-icon="mdi-delete-sweep-outline" disabled data-action="clear">{{ t('queues.overview.clear') }}</v-btn>
                                        <span :id="`${uid}-unavailable-${index}`" class="d-sr-only">{{ t('queues.overview.clearUnavailable') }}</span>
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
