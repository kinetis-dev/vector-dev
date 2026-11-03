<script setup lang="ts">
import type { VectorFailedJob, VectorFailedJobFilters, VectorQueuePermissions } from '#vector/app/types/vector'

// The failed list: GET /failed with its filters, newest first, paged by the
// server's opaque `nextCursor` ("load more"), with Retry (one, or up to
// QUEUE_MAX_RETRY_BATCH selected), Forget and Prune where the permissions
// allow them. A job opens in place, and the list reloads after it changed.
const props = defineProps<{ permissions: VectorQueuePermissions, queues: string[] }>()

const { t } = useVectorT()
const api = useVectorQueues()
const notifications = useVectorNotifications()
const { confirm } = useConfirm()

const emptyFilters = (): VectorFailedJobFilters => ({ queue: '', class: '', failedFrom: '', failedTo: '' })
const filters = ref<VectorFailedJobFilters>(emptyFilters())
let applied = emptyFilters()
const rows = ref<VectorFailedJob[]>([])
const nextCursor = ref<string | null>(null)
const loaded = ref(false)
const loading = ref(false)
const refused = ref(false)
const error = ref<string | null>(null)
const selected = ref(new Set<string>())
const openId = ref<string | null>(null)
const pruneDays = ref('30')
// the action running: only its own button shows as loading, and no second one starts meanwhile
const acting = ref<'retry' | 'forget' | 'prune' | null>(null)
// a date field the browser could not read: a partly typed date keeps its value '' and fires no input event,
// so each input's own validity is read whenever it matters — on input, change and blur, and at Apply
const badInput = ref({ failedFrom: false, failedTo: false })
const filterRow = ref<HTMLElement | null>(null)
let request = 0

/** The first page for the applied filters, or (more) the page after the last one shown. */
async function load(more = false) {
    const id = ++request
    loading.value = true
    try {
        const page = await api.failed(applied, more ? nextCursor.value : null)
        if (id !== request) return
        rows.value = more ? [...rows.value, ...page.data] : page.data
        nextCursor.value = page.hasMore ? page.nextCursor : null
        error.value = null
        loaded.value = true
        if (!more) selected.value = new Set()
    } catch (caught) {
        if (id !== request) return
        if (queueAccessRefused(caught)) refused.value = true
        else error.value = describeQueueError(caught)
    } finally {
        if (id === request) loading.value = false
    }
}

onMounted(() => load())

// an unreadable date is an error on its field, not a filter silently dropped
const dateError = (field: 'failedFrom' | 'failedTo') => badInput.value[field] || !queueLocalDateValid(filters.value[field])
    ? t('queues.failed.filters.invalidDate')
    : ''
const fromError = computed(() => dateError('failedFrom'))
const toError = computed(() => dateError('failedTo'))
const filtersValid = computed(() => fromError.value === '' && toError.value === '')

const dateInput = (field: 'failedFrom' | 'failedTo') => filterRow.value?.querySelector<HTMLInputElement>(`[data-filter="${field}"] input`) ?? null

/** Reads both date inputs' validity, as the browser holds it rather than as their values say. */
function recheckDates() {
    badInput.value = {
        failedFrom: dateInput('failedFrom')?.validity?.badInput === true,
        failedTo: dateInput('failedTo')?.validity?.badInput === true,
    }
}

function apply() {
    recheckDates()
    if (!filtersValid.value) return
    applied = { ...filters.value }
    void load()
}

function reset() {
    filters.value = emptyFilters()
    // a partly typed date's value is '' already, so the model change would not clear its text: clear the input itself
    for (const field of ['failedFrom', 'failedTo'] as const) {
        const input = dateInput(field)
        if (input) input.value = ''
    }
    apply()
}

function toggle(id: string, on: boolean | null) {
    const next = new Set(selected.value)
    if (on && next.size < QUEUE_MAX_RETRY_BATCH) next.add(id)
    else next.delete(id)
    selected.value = next
}

/** Asks, then runs `action` once — a second click while one runs does nothing — and reloads the list either way. */
async function guarded(kind: 'retry' | 'forget' | 'prune', title: string, text: string, action: () => Promise<void>) {
    if (acting.value !== null) return
    if (!await confirm({ title, text, confirmText: t('queues.actions.confirm'), cancelText: t('queues.actions.cancel'), destructive: true })) return
    if (acting.value !== null) return
    acting.value = kind
    try {
        await action()
    } catch (caught) {
        notifications.error(describeQueueError(caught))
    } finally {
        acting.value = null
    }
    await load()
}

function retry(ids: string[]) {
    void guarded('retry', t('queues.failed.retryTitle', { count: ids.length }), t('queues.failed.retryText'), async () => {
        const summary = queueRetrySummary(await api.retry(ids))
        if (summary.ok) notifications.success(summary.text)
        else notifications.error(summary.text)
    })
}

function forget(id: string) {
    void guarded('forget', t('queues.failed.forgetTitle'), t('queues.failed.forgetText'), async () => {
        await api.forget(id)
        notifications.success(t('queues.failed.forgotten'))
    })
}

const days = computed(() => queuePruneDays(pruneDays.value))

function prune() {
    const value = days.value
    if (value === null) return
    void guarded('prune', t('queues.failed.pruneTitle', { days: value }), t('queues.failed.pruneText'), async () => {
        notifications.success(t('queues.failed.pruned', { count: await api.prune(value) }))
    })
}

const queueItems = computed(() => [{ title: t('queues.failed.filters.allQueues'), value: '' }, ...props.queues.map(name => ({ title: name, value: name }))])
const atLimit = computed(() => selected.value.size >= QUEUE_MAX_RETRY_BATCH)
</script>

<template>
    <v-alert v-if="refused" type="warning" variant="tonal" :text="t('queues.noAccess')" data-state="no-access" />
    <VxQueuesFailedDetail v-else-if="openId !== null" :id="openId" :key="openId" :permissions="permissions" @back="openId = null" @changed="load()" />
    <div v-else>
        <div ref="filterRow" class="d-flex flex-wrap align-center ga-2 mb-3">
            <v-select v-model="filters.queue" :items="queueItems" :label="t('queues.failed.filters.queue')" density="compact" hide-details style="max-width: 14rem" />
            <v-text-field v-model="filters.class" :label="t('queues.failed.filters.class')" density="compact" hide-details style="max-width: 22rem" @keydown.enter="apply" />
            <v-text-field
                v-model="filters.failedFrom"
                type="datetime-local"
                step="1"
                :label="t('queues.failed.filters.from')"
                :error-messages="fromError"
                density="compact"
                hide-details="auto"
                style="max-width: 14rem"
                data-filter="failedFrom"
                @input="recheckDates"
                @change="recheckDates"
                @blur="recheckDates"
            />
            <v-text-field
                v-model="filters.failedTo"
                type="datetime-local"
                step="1"
                :label="t('queues.failed.filters.to')"
                :error-messages="toError"
                density="compact"
                hide-details="auto"
                style="max-width: 14rem"
                data-filter="failedTo"
                @input="recheckDates"
                @change="recheckDates"
                @blur="recheckDates"
            />
            <v-btn variant="tonal" color="primary" :disabled="!filtersValid" data-action="apply" @click="apply">{{ t('queues.failed.filters.apply') }}</v-btn>
            <v-btn variant="text" @click="reset">{{ t('queues.failed.filters.reset') }}</v-btn>
        </div>
        <div v-if="permissions.retry || permissions.prune" class="d-flex flex-wrap align-center ga-2 mb-3">
            <template v-if="permissions.retry">
                <v-btn variant="tonal" color="primary" prepend-icon="mdi-replay" :disabled="selected.size === 0" :loading="acting === 'retry'" data-action="retry-selected" @click="retry([...selected])">
                    {{ t('queues.failed.retrySelected', { count: selected.size }) }}
                </v-btn>
                <span v-if="atLimit" class="text-caption text-medium-emphasis">{{ t('queues.failed.retryLimit', { max: QUEUE_MAX_RETRY_BATCH }) }}</span>
            </template>
            <template v-if="permissions.prune">
                <v-spacer />
                <v-text-field v-model="pruneDays" type="number" :min="1" :max="QUEUE_MAX_PRUNE_DAYS" :label="t('queues.failed.pruneDays')" density="compact" hide-details style="max-width: 11rem" />
                <v-btn variant="tonal" color="error" prepend-icon="mdi-broom" :disabled="days === null" :loading="acting === 'prune'" data-action="prune" @click="prune">{{ t('queues.failed.prune') }}</v-btn>
            </template>
        </div>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3" :text="error" />
        <v-progress-linear v-if="!loaded" indeterminate color="primary" :aria-label="t('queues.loading')" />
        <p v-else-if="rows.length === 0" class="text-medium-emphasis">{{ t('queues.failed.empty') }}</p>
        <v-table v-else hover density="compact">
            <thead>
                <tr>
                    <th v-if="permissions.retry" scope="col"><span class="d-sr-only">{{ t('queues.failed.selection') }}</span></th>
                    <th scope="col" class="text-start">{{ t('queues.failed.class') }}</th>
                    <th scope="col" class="text-start">{{ t('queues.failed.queue') }}</th>
                    <th scope="col" class="text-start">{{ t('queues.failed.exception') }}</th>
                    <th scope="col" class="text-end">{{ t('queues.failed.attempts') }}</th>
                    <th scope="col" class="text-start">{{ t('queues.failed.failedAt') }}</th>
                    <th scope="col" class="text-end"><span class="d-sr-only">{{ t('common.actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id" :data-failed-job="row.id">
                    <td v-if="permissions.retry">
                        <v-checkbox
                            :model-value="selected.has(row.id)"
                            :disabled="!selected.has(row.id) && atLimit"
                            density="compact"
                            hide-details
                            :aria-label="t('queues.failed.select', { id: row.id })"
                            @update:model-value="on => toggle(row.id, on)"
                        />
                    </td>
                    <td style="word-break: break-all">{{ row.class }}</td>
                    <td>{{ row.queue }}</td>
                    <td>
                        <div class="text-caption">{{ row.exception.class }}</div>
                        <div class="text-medium-emphasis" style="max-width: 28rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ row.exception.message }}</div>
                    </td>
                    <td class="text-end" style="font-variant-numeric: tabular-nums">{{ queueAttempts(row) }}</td>
                    <td>{{ queueTime(row.failedAt) }}</td>
                    <td class="text-end text-no-wrap">
                        <v-btn size="small" variant="text" data-action="open" @click="openId = row.id">{{ t('queues.failed.open') }}</v-btn>
                        <v-btn v-if="permissions.retry" size="small" variant="text" color="primary" data-action="retry" @click="retry([row.id])">{{ t('queues.failed.retry') }}</v-btn>
                        <v-btn v-if="permissions.forget" size="small" variant="text" color="error" data-action="forget" @click="forget(row.id)">{{ t('queues.failed.forget') }}</v-btn>
                    </td>
                </tr>
            </tbody>
        </v-table>
        <div v-if="nextCursor !== null" class="d-flex justify-center mt-3">
            <v-btn variant="tonal" :loading="loading" data-action="load-more" @click="load(true)">{{ t('queues.failed.loadMore') }}</v-btn>
        </div>
    </div>
</template>
