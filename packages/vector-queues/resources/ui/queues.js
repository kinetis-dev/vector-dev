// The queue dashboard's admin UI: a runtime extension, plain JavaScript
// with no build step. Vector serves each declared module under a
// directory named for its own content hash, so a relative import between
// two modules would not resolve: this is one file on purpose.
//
// It registers the "queues" page (overview, failed jobs, metrics) and a
// "vector-queues" widget renderer, and reads only the package's own
// /vector/queues endpoints through the admin's API client. What the
// policy disallows is left out, from GET /vector/queues/permissions, but
// that is cosmetic: the server asks the policy on every call. Every
// string a user sees goes through t(), and every value from the server —
// exception messages, arguments, queue and class names — is rendered as
// a text node, never as HTML.

/** The server's own limits: QueuesController::MAX_RETRY_BATCH, the prune age, MetricsQuery::MAX_MINUTES. */
const MAX_RETRY_BATCH = 50
const MAX_PRUNE_DAYS = 36500
const POLL_MS = 5000
const WINDOWS = [15, 60, 360, 1440, 10080]
const DEFAULT_WINDOW = 60
const SPARK_POINTS = 240

const messages = {
    title: 'Queues',
    menuGroup: 'Operations',
    loading: 'Loading…',
    none: '—',
    percent: '{value}%',
    noAccess: 'Your account may not view the queue dashboard.',
    tabs: { overview: 'Overview', failed: 'Failed jobs', metrics: 'Metrics' },
    actions: { cancel: 'Cancel', confirm: 'Confirm', refresh: 'Refresh', back: 'Back' },
    errors: {
        forbidden: 'Your account may not do that.',
        notFound: 'It no longer exists.',
        conflict: 'The server refused it: {detail}',
        invalid: 'The request was refused: {detail}',
        failed: 'Something went wrong: {detail}',
    },
    overview: {
        queue: 'Queue',
        size: 'Waiting',
        failed: 'Failed',
        unknownSize: 'unknown',
        empty: 'No queues are watched.',
        clear: 'Clear',
        clearUnavailable: 'This queue backend cannot discard waiting jobs.',
        clearTitle: 'Clear "{queue}"?',
        clearText: 'Every job waiting on "{queue}" is discarded. This cannot be undone.',
        cleared: 'Cleared {count} job(s) from "{queue}".',
        clearUnsupported: 'This queue backend cannot discard waiting jobs, so "{queue}" was not cleared.',
        clearInvalid: '"{queue}" was not cleared: {detail}',
    },
    failed: {
        filters: { queue: 'Queue', allQueues: 'All queues', class: 'Job class', from: 'Failed from', to: 'Failed until', apply: 'Apply', reset: 'Reset' },
        select: 'Select {id}',
        id: 'ID',
        class: 'Job class',
        queue: 'Queue',
        attempts: 'Attempts',
        attemptsOf: '{attempts} of {max}',
        exception: 'Exception',
        failedAt: 'Failed at',
        empty: 'No failed jobs match.',
        loadMore: 'Load more',
        open: 'Details',
        retry: 'Retry',
        retrySelected: 'Retry selected ({count})',
        retryLimit: 'At most {max} jobs are retried at once.',
        retryTitle: 'Retry {count} job(s)?',
        retryText: 'Each job is pushed back onto its queue and its failure record removed.',
        retried: 'Retried {retried}; not found {notFound}; failed {failed}.',
        forget: 'Forget',
        forgetTitle: 'Forget this failed job?',
        forgetText: 'The record is deleted without retrying the job.',
        forgotten: 'The failed job was forgotten.',
        prune: 'Prune',
        pruneDays: 'Older than (days)',
        pruneTitle: 'Prune failed jobs older than {days} day(s)?',
        pruneText: 'Every failure record older than that is deleted, on every queue.',
        pruned: 'Deleted {count} failure record(s).',
        detail: {
            title: 'Failed job {id}',
            connection: 'Connection',
            message: 'Message',
            trace: 'Stack trace',
            noTrace: 'No stack trace is recorded for failed jobs.',
            args: 'Arguments',
        },
    },
    metrics: {
        queue: 'Queue',
        allQueues: 'All queues',
        window: 'Window',
        windows: { m15: 'Last 15 minutes', m60: 'Last hour', m360: 'Last 6 hours', m1440: 'Last 24 hours', m10080: 'Last 7 days' },
        succeeded: 'Succeeded',
        released: 'Released',
        failed: 'Failed',
        failureRate: 'Failure rate',
        averageDuration: 'Average (ms)',
        maxDuration: 'Longest (ms)',
        lastProcessed: 'Last processed',
        class: 'Job class',
        byQueue: 'By queue',
        byClass: 'By job class',
        throughput: 'Attempts per minute',
        empty: 'Nothing ran in this window.',
    },
    widget: { pending: 'Waiting', failed: 'Failed', open: 'Open queues' },
}

/** The HTTP status of an API client error, whichever field the client put it in. */
function statusOf(error) {
    return error?.status ?? error?.statusCode ?? error?.response?.status ?? null
}

/** The server's own explanation of a refusal, for the notices that quote it. */
function detailOf(error) {
    const data = error?.data
    if (data && typeof data === 'object') {
        for (const key of ['error', 'detail', 'title']) {
            if (typeof data[key] === 'string') return data[key]
        }
    }
    return typeof error?.message === 'string' ? error.message : String(error)
}

/** Drops the empty filters, so an unset one is absent rather than an empty string the server refuses. */
function compact(query) {
    return Object.fromEntries(Object.entries(query).filter(([, value]) => value !== '' && value !== null && value !== undefined))
}

/** A `datetime-local` value in the browser's zone as RFC 3339 in whole seconds, UTC; '' when unset or invalid. */
function rfc3339(local) {
    if (!local) return ''
    const date = new Date(local)
    return Number.isNaN(date.getTime()) ? '' : date.toISOString().replace(/\.\d{3}Z$/, 'Z')
}

export default function ({ vue, vuetify, definePage, defineWidget, defineMessages, t, api: vectorApi, navigate: vectorNavigate }) {
    const { h, ref, computed, onMounted, onBeforeUnmount } = vue
    const { VAlert, VBtn, VCard, VCheckbox, VChip, VDialog, VProgressLinear, VSelect, VTab, VTable, VTabs, VTextField, VTooltip } = vuetify

    defineMessages('en', { vectorQueues: messages })

    const tq = (key, params = {}) => t(`vectorQueues.${key}`, params)
    const number = value => (typeof value === 'number' ? value.toLocaleString() : tq('none'))
    const ms = value => (typeof value === 'number' ? value.toLocaleString(undefined, { maximumFractionDigits: 1 }) : tq('none'))
    const rate = value => (typeof value === 'number' ? tq('percent', { value: (value * 100).toFixed(1) }) : tq('none'))
    const isoTime = value => (value ? new Date(value).toLocaleString() : tq('none'))
    const unixTime = value => (typeof value === 'number' ? new Date(value * 1000).toLocaleString() : tq('none'))
    const tabular = { style: 'font-variant-numeric: tabular-nums' }

    /** A notice for a failed call: 403, 404, 409 and 422 in words of their own, anything else with the server's reason. */
    function describe(error) {
        switch (statusOf(error)) {
            case 403: return tq('errors.forbidden')
            case 404: return tq('errors.notFound')
            case 409: return tq('errors.conflict', { detail: detailOf(error) })
            case 422: return tq('errors.invalid', { detail: detailOf(error) })
            default: return tq('errors.failed', { detail: detailOf(error) })
        }
    }

    const noAccess = () => h(VAlert, { type: 'warning', variant: 'tonal', text: tq('noAccess'), 'data-state': 'no-access' })
    const loadingBar = () => h(VProgressLinear, { indeterminate: true, color: 'primary', 'aria-label': tq('loading') })

    /**
     * One confirmation dialog per tab: ask() opens it, and its Confirm
     * runs the action once, staying open (and busy) until it settles.
     */
    function useConfirm() {
        const pending = ref(null)
        const busy = ref(false)

        function ask(title, text, run) {
            pending.value = { title, text, run }
        }

        async function accept() {
            if (!pending.value || busy.value) return
            busy.value = true
            try {
                await pending.value.run()
            } finally {
                busy.value = false
                pending.value = null
            }
        }

        function render() {
            const open = pending.value !== null
            return h(VDialog, {
                modelValue: open,
                'onUpdate:modelValue': value => { if (!value && !busy.value) pending.value = null },
                maxWidth: 480,
            }, {
                default: () => open
                    ? h(VCard, { title: pending.value.title, text: pending.value.text }, {
                            actions: () => [
                                h(VBtn, { variant: 'text', disabled: busy.value, onClick: () => { pending.value = null } }, () => tq('actions.cancel')),
                                h(VBtn, { color: 'error', variant: 'flat', loading: busy.value, 'data-action': 'confirm', onClick: accept }, () => tq('actions.confirm')),
                            ],
                        })
                    : null,
            })
        }

        return { ask, render }
    }

    /**
     * The overview: GET /vector/queues every POLL_MS while the page is
     * visible, one request at a time — the next is scheduled only once
     * the last settled — and never after the tab is unmounted or once
     * the server answered 403.
     */
    const OverviewTab = {
        props: { vector: { type: Object, required: true }, permissions: { type: Object, required: true } },
        setup(props) {
            const rows = ref(null)
            const forbidden = ref(false)
            const error = ref(null)
            const confirm = useConfirm()
            let timer = null
            let inFlight = false
            let again = false
            let stopped = false

            function unschedule() {
                if (timer !== null) clearTimeout(timer)
                timer = null
            }

            function schedule() {
                unschedule()
                if (!stopped && !document.hidden) timer = setTimeout(refresh, POLL_MS)
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
                    const response = await props.vector.api('/vector/queues')
                    rows.value = response.data
                    error.value = null
                } catch (caught) {
                    if (statusOf(caught) === 403) {
                        forbidden.value = true
                        stopped = true
                    } else {
                        error.value = describe(caught)
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

            function clear(queue) {
                confirm.ask(tq('overview.clearTitle', { queue }), tq('overview.clearText', { queue }), async () => {
                    try {
                        const response = await props.vector.api(`/vector/queues/${encodeURIComponent(queue)}/clear`, { method: 'POST', body: { confirm: queue } })
                        props.vector.notify.success(tq('overview.cleared', { queue, count: response.data.cleared }))
                    } catch (caught) {
                        const status = statusOf(caught)
                        props.vector.notify.error(status === 409
                            ? tq('overview.clearUnsupported', { queue })
                            : status === 422 ? tq('overview.clearInvalid', { queue, detail: detailOf(caught) }) : describe(caught))
                    }
                    void refresh()
                })
            }

            function clearButton(row) {
                if (props.permissions.clear?.[row.name] !== true) return null
                const button = activator => h(VBtn, {
                    ...activator,
                    size: 'small',
                    variant: 'tonal',
                    color: 'error',
                    prependIcon: 'mdi-delete-sweep-outline',
                    disabled: !row.clearable,
                    'data-action': 'clear',
                    onClick: () => clear(row.name),
                }, () => tq('overview.clear'))

                if (row.clearable) return button({})
                // a disabled button receives no pointer events: the tooltip hangs on a wrapper
                return h(VTooltip, { text: tq('overview.clearUnavailable'), location: 'top' }, {
                    activator: ({ props: activator }) => h('span', activator, [button({})]),
                })
            }

            return () => {
                if (forbidden.value) return noAccess()
                if (rows.value === null) return error.value ? h(VAlert, { type: 'error', variant: 'tonal', text: error.value }) : loadingBar()

                return h('div', [
                    error.value && h(VAlert, { type: 'error', variant: 'tonal', density: 'compact', class: 'mb-3', text: error.value }),
                    rows.value.length === 0
                        ? h('p', { class: 'text-medium-emphasis' }, tq('overview.empty'))
                        : h(VTable, { hover: true }, () => [
                                h('thead', h('tr', [
                                    h('th', { class: 'text-start' }, tq('overview.queue')),
                                    h('th', { class: 'text-end' }, tq('overview.size')),
                                    h('th', { class: 'text-end' }, tq('overview.failed')),
                                    h('th', { class: 'text-end' }),
                                ])),
                                h('tbody', rows.value.map(row => h('tr', { key: row.name }, [
                                    h('td', row.name),
                                    h('td', { class: 'text-end', ...tabular }, row.size === null ? tq('overview.unknownSize') : number(row.size)),
                                    h('td', { class: 'text-end', ...tabular }, row.failed > 0
                                        ? h(VChip, { size: 'small', color: 'error', variant: 'tonal', label: true }, () => number(row.failed))
                                        : number(row.failed)),
                                    h('td', { class: 'text-end' }, clearButton(row)),
                                ]))),
                            ]),
                    confirm.render(),
                ])
            }
        },
    }

    /** One failed job: GET /failed/{id}, its exception, message and trace (always null today) as text, and Retry and Forget when allowed. */
    const FailedDetail = {
        props: { vector: { type: Object, required: true }, permissions: { type: Object, required: true }, id: { type: String, required: true } },
        emits: ['back', 'changed'],
        setup(props, { emit }) {
            const job = ref(null)
            const forbidden = ref(false)
            const error = ref(null)
            const confirm = useConfirm()

            onMounted(async () => {
                try {
                    job.value = (await props.vector.api(`/vector/queues/failed/${encodeURIComponent(props.id)}`)).data
                } catch (caught) {
                    if (statusOf(caught) === 403) forbidden.value = true
                    else error.value = describe(caught)
                }
            })

            function retry() {
                confirm.ask(tq('failed.retryTitle', { count: 1 }), tq('failed.retryText'), async () => {
                    try {
                        const results = (await props.vector.api('/vector/queues/failed/retry', { method: 'POST', body: { ids: [props.id] } })).data
                        notifyRetried(props.vector, results)
                        emit('changed')
                        if (results.every(result => result.status !== 'failed')) emit('back')
                    } catch (caught) {
                        props.vector.notify.error(describe(caught))
                    }
                })
            }

            function forget() {
                confirm.ask(tq('failed.forgetTitle'), tq('failed.forgetText'), async () => {
                    try {
                        await props.vector.api(`/vector/queues/failed/${encodeURIComponent(props.id)}`, { method: 'DELETE' })
                        props.vector.notify.success(tq('failed.forgotten'))
                        emit('changed')
                        emit('back')
                    } catch (caught) {
                        props.vector.notify.error(describe(caught))
                    }
                })
            }

            const field = (label, value) => h('div', { class: 'mb-2' }, [
                h('div', { class: 'text-caption text-medium-emphasis' }, label),
                h('div', value),
            ])
            // text children only: Vue sets them as text nodes, so a message or a trace is never parsed as HTML
            const block = (label, text) => h('div', { class: 'mb-3' }, [
                h('div', { class: 'text-caption text-medium-emphasis mb-1' }, label),
                h('pre', { class: 'pa-2 rounded bg-surface-variant', style: 'white-space: pre-wrap; word-break: break-word; max-height: 24rem; overflow: auto' }, text),
            ])

            return () => {
                const back = h(VBtn, { variant: 'text', prependIcon: 'mdi-arrow-left', class: 'mb-3', onClick: () => emit('back') }, () => tq('actions.back'))
                if (forbidden.value) return h('div', [back, noAccess()])
                if (error.value) return h('div', [back, h(VAlert, { type: 'error', variant: 'tonal', text: error.value })])
                if (job.value === null) return h('div', [back, loadingBar()])

                const value = job.value
                return h('div', [
                    h('div', { class: 'd-flex align-center ga-2 flex-wrap' }, [
                        back,
                        h('div', { class: 'flex-grow-1' }),
                        props.permissions.retry === true
                            && h(VBtn, { variant: 'tonal', color: 'primary', prependIcon: 'mdi-replay', 'data-action': 'retry', onClick: retry }, () => tq('failed.retry')),
                        props.permissions.forget === true
                            && h(VBtn, { variant: 'tonal', color: 'error', prependIcon: 'mdi-delete-outline', 'data-action': 'forget', onClick: forget }, () => tq('failed.forget')),
                    ]),
                    h('h2', { class: 'text-h6 mb-3' }, tq('failed.detail.title', { id: value.id })),
                    field(tq('failed.class'), value.class),
                    field(tq('failed.queue'), value.queue),
                    field(tq('failed.detail.connection'), value.connection ?? tq('none')),
                    field(tq('failed.attempts'), value.maxAttempts === null ? number(value.attempts) : tq('failed.attemptsOf', { attempts: value.attempts, max: value.maxAttempts })),
                    field(tq('failed.failedAt'), isoTime(value.failedAt)),
                    field(tq('failed.exception'), value.exception.class),
                    block(tq('failed.detail.message'), value.exception.message),
                    block(tq('failed.detail.trace'), value.exception.trace ?? tq('failed.detail.noTrace')),
                    block(tq('failed.detail.args'), JSON.stringify(value.args, null, 2)),
                    confirm.render(),
                ])
            }
        },
    }

    function notifyRetried(vector, results) {
        const count = status => results.filter(result => result.status === status).length
        const message = tq('failed.retried', { retried: count('retried'), notFound: count('not_found'), failed: count('failed') })
        if (count('retried') === results.length) vector.notify.success(message)
        else vector.notify.error(message)
    }

    /**
     * The failed list: GET /failed with its filters, newest first, paged
     * by the server's opaque `nextCursor` ("load more"), with Retry (one,
     * or up to MAX_RETRY_BATCH selected), Forget and Prune where allowed.
     */
    const FailedTab = {
        props: { vector: { type: Object, required: true }, permissions: { type: Object, required: true }, queues: { type: Array, required: true } },
        setup(props) {
            const emptyFilters = () => ({ queue: '', class: '', failedFrom: '', failedTo: '' })
            const filters = ref(emptyFilters())
            let applied = emptyFilters()
            const rows = ref([])
            const nextCursor = ref(null)
            const loaded = ref(false)
            const loading = ref(false)
            const forbidden = ref(false)
            const error = ref(null)
            const selected = ref(new Set())
            const openId = ref(null)
            const pruneDays = ref('30')
            const confirm = useConfirm()
            let request = 0

            /** The first page for the applied filters, or (more) the page after the last one shown. */
            async function load(more = false) {
                const id = ++request
                loading.value = true
                const query = compact({
                    queue: applied.queue,
                    class: applied.class.trim(),
                    failedFrom: rfc3339(applied.failedFrom),
                    failedTo: rfc3339(applied.failedTo),
                    cursor: more ? nextCursor.value : '',
                })
                try {
                    const page = await props.vector.api('/vector/queues/failed', { query })
                    if (id !== request) return
                    rows.value = more ? [...rows.value, ...page.data] : page.data
                    nextCursor.value = page.hasMore ? page.nextCursor : null
                    error.value = null
                    loaded.value = true
                    if (!more) selected.value = new Set()
                } catch (caught) {
                    if (id !== request) return
                    if (statusOf(caught) === 403) forbidden.value = true
                    else error.value = describe(caught)
                } finally {
                    if (id === request) loading.value = false
                }
            }

            onMounted(() => load())

            function apply() {
                applied = { ...filters.value }
                void load()
            }

            function reset() {
                filters.value = emptyFilters()
                apply()
            }

            function toggle(id, on) {
                const next = new Set(selected.value)
                if (on && next.size < MAX_RETRY_BATCH) next.add(id)
                else next.delete(id)
                selected.value = next
            }

            function retry(ids) {
                confirm.ask(tq('failed.retryTitle', { count: ids.length }), tq('failed.retryText'), async () => {
                    try {
                        notifyRetried(props.vector, (await props.vector.api('/vector/queues/failed/retry', { method: 'POST', body: { ids } })).data)
                    } catch (caught) {
                        props.vector.notify.error(describe(caught))
                    }
                    await load()
                })
            }

            function forget(id) {
                confirm.ask(tq('failed.forgetTitle'), tq('failed.forgetText'), async () => {
                    try {
                        await props.vector.api(`/vector/queues/failed/${encodeURIComponent(id)}`, { method: 'DELETE' })
                        props.vector.notify.success(tq('failed.forgotten'))
                    } catch (caught) {
                        props.vector.notify.error(describe(caught))
                    }
                    await load()
                })
            }

            const days = computed(() => (/^[1-9]\d*$/.test(String(pruneDays.value).trim()) ? Number(pruneDays.value) : null))

            function prune() {
                const value = days.value
                if (value === null || value > MAX_PRUNE_DAYS) return
                confirm.ask(tq('failed.pruneTitle', { days: value }), tq('failed.pruneText'), async () => {
                    try {
                        const response = await props.vector.api('/vector/queues/failed/prune', { method: 'POST', body: { days: value } })
                        props.vector.notify.success(tq('failed.pruned', { count: response.data.deleted }))
                    } catch (caught) {
                        props.vector.notify.error(describe(caught))
                    }
                    await load()
                })
            }

            const model = key => ({
                modelValue: filters.value[key],
                'onUpdate:modelValue': value => { filters.value = { ...filters.value, [key]: value ?? '' } },
            })

            function toolbar() {
                const queueItems = [{ title: tq('failed.filters.allQueues'), value: '' }, ...props.queues.map(name => ({ title: name, value: name }))]
                return h('div', { class: 'd-flex flex-wrap align-center ga-2 mb-3' }, [
                    h(VSelect, { ...model('queue'), items: queueItems, label: tq('failed.filters.queue'), density: 'compact', hideDetails: true, style: 'max-width: 14rem' }),
                    h(VTextField, { ...model('class'), label: tq('failed.filters.class'), density: 'compact', hideDetails: true, style: 'max-width: 22rem', onKeydown: event => { if (event.key === 'Enter') apply() } }),
                    h(VTextField, { ...model('failedFrom'), type: 'datetime-local', step: 1, label: tq('failed.filters.from'), density: 'compact', hideDetails: true, style: 'max-width: 14rem' }),
                    h(VTextField, { ...model('failedTo'), type: 'datetime-local', step: 1, label: tq('failed.filters.to'), density: 'compact', hideDetails: true, style: 'max-width: 14rem' }),
                    h(VBtn, { variant: 'tonal', color: 'primary', onClick: apply }, () => tq('failed.filters.apply')),
                    h(VBtn, { variant: 'text', onClick: reset }, () => tq('failed.filters.reset')),
                ])
            }

            function mutations() {
                const buttons = []
                if (props.permissions.retry === true) {
                    buttons.push(h(VBtn, {
                        variant: 'tonal',
                        color: 'primary',
                        prependIcon: 'mdi-replay',
                        disabled: selected.value.size === 0,
                        'data-action': 'retry-selected',
                        onClick: () => retry([...selected.value]),
                    }, () => tq('failed.retrySelected', { count: selected.value.size })))
                    if (selected.value.size >= MAX_RETRY_BATCH) buttons.push(h('span', { class: 'text-caption text-medium-emphasis' }, tq('failed.retryLimit', { max: MAX_RETRY_BATCH })))
                }
                if (props.permissions.prune === true) {
                    buttons.push(h('div', { class: 'flex-grow-1' }))
                    buttons.push(h(VTextField, {
                        modelValue: pruneDays.value,
                        'onUpdate:modelValue': value => { pruneDays.value = value },
                        type: 'number',
                        min: 1,
                        max: MAX_PRUNE_DAYS,
                        label: tq('failed.pruneDays'),
                        density: 'compact',
                        hideDetails: true,
                        style: 'max-width: 11rem',
                    }))
                    buttons.push(h(VBtn, {
                        variant: 'tonal',
                        color: 'error',
                        prependIcon: 'mdi-broom',
                        disabled: days.value === null || days.value > MAX_PRUNE_DAYS,
                        'data-action': 'prune',
                        onClick: prune,
                    }, () => tq('failed.prune')))
                }
                return buttons.length === 0 ? null : h('div', { class: 'd-flex flex-wrap align-center ga-2 mb-3' }, buttons)
            }

            function table() {
                const canRetry = props.permissions.retry === true
                const canForget = props.permissions.forget === true
                return h(VTable, { hover: true, density: 'compact' }, () => [
                    h('thead', h('tr', [
                        canRetry && h('th'),
                        h('th', { class: 'text-start' }, tq('failed.class')),
                        h('th', { class: 'text-start' }, tq('failed.queue')),
                        h('th', { class: 'text-start' }, tq('failed.exception')),
                        h('th', { class: 'text-end' }, tq('failed.attempts')),
                        h('th', { class: 'text-start' }, tq('failed.failedAt')),
                        h('th', { class: 'text-end' }),
                    ])),
                    h('tbody', rows.value.map(row => h('tr', { key: row.id }, [
                        canRetry && h('td', h(VCheckbox, {
                            modelValue: selected.value.has(row.id),
                            'onUpdate:modelValue': on => toggle(row.id, on),
                            disabled: !selected.value.has(row.id) && selected.value.size >= MAX_RETRY_BATCH,
                            density: 'compact',
                            hideDetails: true,
                            'aria-label': tq('failed.select', { id: row.id }),
                        })),
                        h('td', { style: 'word-break: break-all' }, row.class),
                        h('td', row.queue),
                        h('td', [
                            h('div', { class: 'text-caption' }, row.exception.class),
                            h('div', { class: 'text-medium-emphasis', style: 'max-width: 28rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap' }, row.exception.message),
                        ]),
                        h('td', { class: 'text-end', ...tabular }, row.maxAttempts === null ? number(row.attempts) : tq('failed.attemptsOf', { attempts: row.attempts, max: row.maxAttempts })),
                        h('td', isoTime(row.failedAt)),
                        h('td', { class: 'text-end text-no-wrap' }, [
                            h(VBtn, { size: 'small', variant: 'text', onClick: () => { openId.value = row.id } }, () => tq('failed.open')),
                            canRetry && h(VBtn, { size: 'small', variant: 'text', color: 'primary', 'data-action': 'retry', onClick: () => retry([row.id]) }, () => tq('failed.retry')),
                            canForget && h(VBtn, { size: 'small', variant: 'text', color: 'error', 'data-action': 'forget', onClick: () => forget(row.id) }, () => tq('failed.forget')),
                        ]),
                    ]))),
                ])
            }

            return () => {
                if (forbidden.value) return noAccess()
                if (openId.value !== null) {
                    return h(FailedDetail, {
                        key: openId.value,
                        vector: props.vector,
                        permissions: props.permissions,
                        id: openId.value,
                        onBack: () => { openId.value = null },
                        onChanged: () => load(),
                    })
                }

                return h('div', [
                    toolbar(),
                    mutations(),
                    error.value && h(VAlert, { type: 'error', variant: 'tonal', density: 'compact', class: 'mb-3', text: error.value }),
                    !loaded.value
                        ? loadingBar()
                        : rows.value.length === 0
                            ? h('p', { class: 'text-medium-emphasis' }, tq('failed.empty'))
                            : table(),
                    nextCursor.value !== null && h('div', { class: 'd-flex justify-center mt-3' }, [
                        h(VBtn, { variant: 'tonal', loading: loading.value, onClick: () => load(true) }, () => tq('failed.loadMore')),
                    ]),
                    confirm.render(),
                ])
            }
        },
    }

    /** Attempts per minute as an inline SVG polyline, summed into at most SPARK_POINTS points; no chart library. */
    function sparkline(buckets) {
        const totals = buckets.map(bucket => bucket.succeeded + bucket.released + bucket.failed)
        const size = Math.max(1, Math.ceil(totals.length / SPARK_POINTS))
        const points = []
        for (let index = 0; index < totals.length; index += size) {
            points.push(totals.slice(index, index + size).reduce((sum, value) => sum + value, 0))
        }
        const peak = Math.max(1, ...points)
        const width = Math.max(1, points.length - 1)
        const line = points.map((value, index) => `${index},${(40 - (value / peak) * 38 - 1).toFixed(2)}`).join(' ')

        return h('svg', { viewBox: `0 0 ${width} 40`, preserveAspectRatio: 'none', width: '100%', height: 48, role: 'img', 'aria-label': tq('metrics.throughput') }, [
            h('polyline', { points: line, fill: 'none', stroke: 'currentColor', 'stroke-width': 1.5, 'vector-effect': 'non-scaling-stroke' }),
        ])
    }

    /** The job metrics: GET /metrics for a watched queue (or all) over a window of at most 7 days, the last hour by default. */
    const MetricsTab = {
        props: { vector: { type: Object, required: true }, queues: { type: Array, required: true } },
        setup(props) {
            const queue = ref('')
            const minutes = ref(DEFAULT_WINDOW)
            const metrics = ref(null)
            const forbidden = ref(false)
            const error = ref(null)
            let request = 0

            async function load() {
                const id = ++request
                // both ends from this clock, so the window is exactly the one chosen whatever the server's clock says
                const to = Math.floor(Date.now() / 60000) * 60
                const from = to - (minutes.value - 1) * 60
                try {
                    const response = await props.vector.api('/vector/queues/metrics', { query: compact({ queue: queue.value, from, to }) })
                    if (id !== request) return
                    metrics.value = response.data
                    error.value = null
                } catch (caught) {
                    if (id !== request) return
                    if (statusOf(caught) === 403) forbidden.value = true
                    else error.value = describe(caught)
                }
            }

            onMounted(load)

            const totals = computed(() => {
                const sum = { succeeded: 0, released: 0, failed: 0, max: null, duration: 0 }
                for (const row of metrics.value?.queues ?? []) {
                    sum.succeeded += row.succeeded
                    sum.released += row.released
                    sum.failed += row.failed
                    sum.duration += row.averageDurationMs * (row.succeeded + row.released + row.failed)
                    sum.max = Math.max(sum.max ?? 0, row.maxDurationMs)
                }
                const attempts = sum.succeeded + sum.released + sum.failed
                const finished = sum.succeeded + sum.failed
                return {
                    ...sum,
                    failureRate: finished === 0 ? null : sum.failed / finished,
                    averageDurationMs: attempts === 0 ? null : sum.duration / attempts,
                }
            })

            function totalsTable(label, key, rows) {
                return h('div', { class: 'mb-4' }, [
                    h('h3', { class: 'text-subtitle-1 mb-1' }, label),
                    h(VTable, { density: 'compact', hover: true }, () => [
                        h('thead', h('tr', [
                            h('th', { class: 'text-start' }, key === 'queue' ? tq('metrics.queue') : tq('metrics.class')),
                            h('th', { class: 'text-end' }, tq('metrics.succeeded')),
                            h('th', { class: 'text-end' }, tq('metrics.released')),
                            h('th', { class: 'text-end' }, tq('metrics.failed')),
                            h('th', { class: 'text-end' }, tq('metrics.failureRate')),
                            h('th', { class: 'text-end' }, tq('metrics.averageDuration')),
                            h('th', { class: 'text-end' }, tq('metrics.maxDuration')),
                            h('th', { class: 'text-start' }, tq('metrics.lastProcessed')),
                        ])),
                        h('tbody', rows.map(row => h('tr', { key: row[key] }, [
                            h('td', { style: 'word-break: break-all' }, row[key]),
                            h('td', { class: 'text-end', ...tabular }, number(row.succeeded)),
                            h('td', { class: 'text-end', ...tabular }, number(row.released)),
                            h('td', { class: 'text-end', ...tabular }, number(row.failed)),
                            h('td', { class: 'text-end', ...tabular }, rate(row.failureRate)),
                            h('td', { class: 'text-end', ...tabular }, ms(row.averageDurationMs)),
                            h('td', { class: 'text-end', ...tabular }, ms(row.maxDurationMs)),
                            h('td', unixTime(row.lastProcessedAt)),
                        ]))),
                    ]),
                ])
            }

            const stat = (label, value) => h(VCard, { variant: 'tonal', class: 'pa-3', style: 'min-width: 9rem' }, () => [
                h('div', { class: 'text-caption text-medium-emphasis' }, label),
                h('div', { class: 'text-h6', ...tabular }, value),
            ])

            return () => {
                if (forbidden.value) return noAccess()
                const queueItems = [{ title: tq('metrics.allQueues'), value: '' }, ...props.queues.map(name => ({ title: name, value: name }))]
                const windowItems = WINDOWS.map(value => ({ title: tq(`metrics.windows.m${value}`), value }))
                const data = metrics.value
                const sum = totals.value

                return h('div', [
                    h('div', { class: 'd-flex flex-wrap align-center ga-2 mb-3' }, [
                        h(VSelect, { modelValue: queue.value, 'onUpdate:modelValue': value => { queue.value = value ?? ''; void load() }, items: queueItems, label: tq('metrics.queue'), density: 'compact', hideDetails: true, style: 'max-width: 14rem' }),
                        h(VSelect, { modelValue: minutes.value, 'onUpdate:modelValue': value => { minutes.value = value; void load() }, items: windowItems, label: tq('metrics.window'), density: 'compact', hideDetails: true, style: 'max-width: 14rem' }),
                        h(VBtn, { variant: 'text', prependIcon: 'mdi-refresh', onClick: load }, () => tq('actions.refresh')),
                    ]),
                    error.value && h(VAlert, { type: 'error', variant: 'tonal', density: 'compact', class: 'mb-3', text: error.value }),
                    data === null
                        ? (error.value ? null : loadingBar())
                        : h('div', [
                                h('div', { class: 'd-flex flex-wrap ga-3 mb-4' }, [
                                    stat(tq('metrics.succeeded'), number(sum.succeeded)),
                                    stat(tq('metrics.released'), number(sum.released)),
                                    stat(tq('metrics.failed'), number(sum.failed)),
                                    stat(tq('metrics.failureRate'), rate(sum.failureRate)),
                                    stat(tq('metrics.averageDuration'), ms(sum.averageDurationMs)),
                                    stat(tq('metrics.maxDuration'), ms(sum.max)),
                                ]),
                                h('div', { class: 'mb-4 text-primary' }, [
                                    h('div', { class: 'text-caption text-medium-emphasis' }, tq('metrics.throughput')),
                                    sparkline(data.buckets),
                                ]),
                                data.queues.length === 0
                                    ? h('p', { class: 'text-medium-emphasis' }, tq('metrics.empty'))
                                    : [totalsTable(tq('metrics.byQueue'), 'queue', data.queues), totalsTable(tq('metrics.byClass'), 'class', data.classes)],
                            ]),
                ])
            }
        },
    }

    definePage('queues', {
        title: tq('title'),
        icon: 'mdi-tray-full',
        group: tq('menuGroup'),
        component: {
            props: { vector: { type: Object, required: true } },
            setup(props) {
                const tab = ref('overview')
                const permissions = ref(null)
                const forbidden = ref(false)
                const error = ref(null)

                onMounted(async () => {
                    try {
                        permissions.value = await props.vector.api('/vector/queues/permissions')
                    } catch (caught) {
                        if (statusOf(caught) === 403) forbidden.value = true
                        else error.value = describe(caught)
                    }
                })

                return () => {
                    if (forbidden.value) return noAccess()
                    if (error.value) return h(VAlert, { type: 'error', variant: 'tonal', text: error.value })
                    if (permissions.value === null) return loadingBar()

                    const queues = Object.keys(permissions.value.clear ?? {})
                    const shared = { vector: props.vector, permissions: permissions.value, queues }

                    return h('div', [
                        h(VTabs, { modelValue: tab.value, 'onUpdate:modelValue': value => { tab.value = value }, class: 'mb-4' }, () => [
                            h(VTab, { value: 'overview' }, () => tq('tabs.overview')),
                            h(VTab, { value: 'failed' }, () => tq('tabs.failed')),
                            h(VTab, { value: 'metrics' }, () => tq('tabs.metrics')),
                        ]),
                        // only the open tab is mounted, so the overview polls only while it is shown
                        tab.value === 'overview' ? h(OverviewTab, shared) : tab.value === 'failed' ? h(FailedTab, shared) : h(MetricsTab, shared),
                    ])
                }
            },
        },
    })

    /**
     * A dashboard card: the jobs waiting and failed across the watched
     * queues, from GET /vector/queues, and a link to the page. It renders
     * a dashboard widget whose type is "vector-queues"; the `data` the
     * dashboard loads for it is not used.
     */
    defineWidget('vector-queues', {
        props: { data: {}, widget: {} },
        setup() {
            const totals = ref(null)
            const forbidden = ref(false)
            const error = ref(null)

            onMounted(async () => {
                try {
                    const rows = (await vectorApi('/vector/queues')).data
                    const known = rows.filter(row => row.size !== null)
                    totals.value = {
                        pending: known.length === 0 ? null : known.reduce((sum, row) => sum + row.size, 0),
                        failed: rows.reduce((sum, row) => sum + row.failed, 0),
                    }
                } catch (caught) {
                    if (statusOf(caught) === 403) forbidden.value = true
                    else error.value = describe(caught)
                }
            })

            const figure = (label, value, color) => h('div', { class: 'flex-grow-1' }, [
                h('div', { class: `text-h4 ${color}`, ...tabular }, number(value)),
                h('div', { class: 'text-caption text-medium-emphasis' }, label),
            ])

            return () => {
                if (forbidden.value) return noAccess()
                if (error.value) return h(VAlert, { type: 'error', variant: 'tonal', density: 'compact', text: error.value })
                if (totals.value === null) return loadingBar()

                return h('div', [
                    h('div', { class: 'd-flex ga-4 mb-2' }, [
                        figure(tq('widget.pending'), totals.value.pending, ''),
                        figure(tq('widget.failed'), totals.value.failed, totals.value.failed > 0 ? 'text-error' : ''),
                    ]),
                    h(VBtn, { variant: 'text', size: 'small', appendIcon: 'mdi-arrow-right', class: 'px-0', onClick: () => vectorNavigate('/p/queues') }, () => tq('widget.open')),
                ])
            }
        },
    })
}
