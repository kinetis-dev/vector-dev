import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { h } from 'vue'
import VectorLayout from '#vector/app/layouts/vector.vue'
import QueuesPage from '#vector/app/pages/queues/index.vue'
import VxConfirm from '#vector/app/components/Vx/Confirm.vue'
import QueuesOverview from '#vector/app/components/Vx/Queues/Overview.vue'
import type { VectorFailedJob, VectorQueue, VectorQueuePermissions, VectorSchema } from '#vector/app/types/vector'
import { endpoint, standaloneApi, type Received } from '../endpoints'
import { schema } from '../fixtures'

// The queue dashboard is Vector's own page: its menu entry follows the
// descriptor's capabilities.queues, its failed-jobs tab, column and actions
// follow capabilities.failedJobs, its metrics tab follows
// capabilities.queueMetrics, and its actions call the server's
// /vector/queues endpoints.

// queueMetrics null leaves the key out, as a descriptor from a server without it would
const capabilities = (queues: boolean, failedJobs: boolean, queueMetrics: boolean | null = true): VectorSchema['capabilities'] => ({
    impersonation: false, queue: false, queues, failedJobs, ...(queueMetrics === null ? {} : { queueMetrics }),
})

const permissions = (overrides: Partial<VectorQueuePermissions> = {}): VectorQueuePermissions => ({
    view: true, retry: true, forget: true, prune: true, clear: { mail: true, reports: false }, ...overrides,
})

const rows = (failed: number | null): VectorQueue[] => [
    { name: 'mail', size: 3, clearable: true, failed },
    { name: 'reports', size: null, clearable: true, failed },
]

type Reply = Parameters<typeof endpoint>[1]

// markup in server text: rendered as markup, it would make an <img>
const XSS = '<img src=x onerror=alert(1)>'

/** `count` well-formed record ids, from `from` on. */
const ids = (count: number, from = 0) => Array.from({ length: count }, (_, index) => (from + index).toString(16).padStart(32, '0'))

const job = (id: string): VectorFailedJob => ({
    id,
    connection: 'redis',
    queue: 'mail',
    class: 'App\\Jobs\\SendInvoiceJob',
    attempts: 3,
    maxAttempts: 3,
    exception: { class: 'RuntimeException', message: `The mail server said ${XSS}` },
    failedAt: '2026-11-01T10:00:00Z',
})

const failedPage = (jobs: VectorFailedJob[], nextCursor: string | null = null) => ({ body: { data: jobs, nextCursor, hasMore: nextCursor !== null, limit: 25 } })

const query = (request: Received) => Object.fromEntries(new URL(request.path, 'http://x').searchParams)
const toasts = () => useVectorNotifications().queue.value.map(toast => [toast.text, toast.color])

describe('the Queues menu entry', () => {
    const cleanups: (() => void)[] = []

    beforeEach(() => {
        standaloneApi()
        useVectorAuthStore().adopt('jwt', 3600, { id: 1, name: 'Vector Admin' })
        cleanups.push(endpoint('/vector/badges', { body: { data: {} } }).unregister)
    })

    afterEach(() => {
        cleanups.splice(0).forEach(unregister => unregister())
        useVectorSchema().clear()
        useVectorAuthStore().clear()
    })

    async function nav(queues: boolean) {
        cleanups.push(endpoint('/vector/schema', { body: schema([], { capabilities: capabilities(queues, queues) }) }).unregister)
        const wrapper = await mountSuspended(VectorLayout, { route: '/', slots: { default: () => h('p', 'Home') } })
        await flushPromises()
        return wrapper
    }

    it('is left out when the descriptor says the dashboard is not there', async () => {
        const wrapper = await nav(false)

        expect(wrapper.find('[role="navigation"]').exists()).toBe(true)
        expect(wrapper.find('[data-nav="queues"]').exists()).toBe(false)
        expect(wrapper.find('[role="navigation"]').text()).not.toContain('Queues')
        wrapper.unmount()
    })

    it('links to the Queues page when it is there', async () => {
        const wrapper = await nav(true)

        const entry = wrapper.find('[data-nav="queues"]')
        expect(entry.exists()).toBe(true)
        expect(entry.text()).toContain('Queues')
        // Vuetify renders no href here (no client-side router link in this environment), so the item's own target is what is checked
        const item = wrapper.findAllComponents({ name: 'VListItem' }).find(candidate => candidate.attributes('data-nav') === 'queues')
        expect(String(item?.props('to'))).toMatch(/\/queues$/)
        wrapper.unmount()
    })
})

describe('the Queues page', () => {
    const cleanups: (() => void)[] = []

    beforeEach(() => {
        standaloneApi()
        useVectorAuthStore().adopt('jwt', 3600, { id: 1, name: 'Vector Admin' })
        useVectorNotifications().queue.value = []
    })

    afterEach(() => {
        cleanups.splice(0).forEach(unregister => unregister())
        useConfirm().settle(false)
        useVectorSchema().clear()
        useVectorAuthStore().clear()
        vi.useRealTimers()
    })

    async function page(failedJobs: boolean, granted: VectorQueuePermissions = permissions(), queueMetrics: boolean | null = true, failedReply: Reply = failedPage([])) {
        useVectorSchema().schema.value = schema([], { capabilities: capabilities(true, failedJobs, queueMetrics) })
        const overview = endpoint('/vector/queues', { body: { data: rows(failedJobs ? 2 : null) } }, 'GET')
        const failed = endpoint('/vector/queues/failed', failedReply)
        const metrics = endpoint('/vector/queues/metrics', { body: { data: { from: 0, to: 0, bucketSeconds: 60, buckets: [], queues: [], classes: [] } } })
        cleanups.push(endpoint('/vector/queues/permissions', { body: failedJobs ? granted : { ...granted, retry: false, forget: false, prune: false } }).unregister, overview.unregister, failed.unregister, metrics.unregister)
        const wrapper = await mountSuspended(QueuesPage, { route: '/queues' })
        // the permissions load on mount, through the environment's endpoint registry: wait for the tabs they gate
        await vi.waitFor(() => expect(wrapper.find('[data-tab="overview"]').exists()).toBe(true))
        await flushPromises()
        return { wrapper, overview, failed, metrics }
    }

    it('leaves out the failed-jobs tab and column without failed jobs', async () => {
        const { wrapper, failed } = await page(false)

        expect(wrapper.find('[data-tab="overview"]').exists()).toBe(true)
        expect(wrapper.find('[data-tab="metrics"]').exists()).toBe(true)
        expect(wrapper.find('[data-tab="failed"]').exists()).toBe(false)
        expect(wrapper.find('[data-queue="mail"]').exists()).toBe(true)
        expect(wrapper.find('[data-column="failed"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Failed jobs')
        // nothing asked for the failed list, which would be a 404
        expect(failed.requests).toHaveLength(0)
        wrapper.unmount()
    })

    it('shows them with failed jobs: the control', async () => {
        const { wrapper } = await page(true)

        expect(wrapper.find('[data-tab="failed"]').text()).toBe('Failed jobs')
        expect(wrapper.find('[data-column="failed"]').exists()).toBe(true)
        expect(wrapper.find('[data-queue="mail"]').text()).toContain('2')
        wrapper.unmount()
    })

    it.each([
        ['false', false],
        ['absent', null],
    ])('leaves out the metrics tab when capabilities.queueMetrics is %s, and never asks for the metrics', async (_, queueMetrics) => {
        const { wrapper, metrics } = await page(true, permissions(), queueMetrics)

        expect(wrapper.find('[data-tab="overview"]').exists()).toBe(true)
        expect(wrapper.find('[data-tab="failed"]').exists()).toBe(true)
        expect(wrapper.find('[data-tab="metrics"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Metrics')

        // nor does opening the other tabs mount the metrics panel in its place
        await wrapper.find('[data-tab="failed"]').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('No failed jobs match.'))
        await flushPromises()
        expect(metrics.requests).toHaveLength(0)
        wrapper.unmount()
    })

    it('shows the metrics tab with capabilities.queueMetrics, which asks for the metrics: the control', async () => {
        const { wrapper, metrics } = await page(true)

        expect(wrapper.find('[data-tab="metrics"]').text()).toBe('Metrics')
        expect(metrics.requests).toHaveLength(0)
        await wrapper.find('[data-tab="metrics"]').trigger('click')
        await vi.waitFor(() => expect(metrics.requests).toHaveLength(1))
        expect(metrics.requests[0]?.method).toBe('GET')
        // the chart says what it shows, not only what it is
        await vi.waitFor(() => expect(wrapper.find('[data-chart="throughput"]').exists()).toBe(true))
        expect(wrapper.find('[data-chart="throughput"]').attributes('aria-label')).toBe('Attempts per minute, Last hour, All queues: 0 in total')
        wrapper.unmount()
    })

    it('goes back to the overview when the metrics turn off while their tab is open', async () => {
        const { wrapper, metrics } = await page(true)

        await wrapper.find('[data-tab="metrics"]').trigger('click')
        await vi.waitFor(() => expect(metrics.requests).toHaveLength(1))
        useVectorSchema().schema.value = schema([], { capabilities: capabilities(true, true, false) })
        await flushPromises()

        expect(wrapper.find('[data-tab="metrics"]').exists()).toBe(false)
        await vi.waitFor(() => expect(wrapper.find('[data-queue="mail"]').exists()).toBe(true))
        expect(metrics.requests).toHaveLength(1)
        wrapper.unmount()
    })

    /** Opens the failed tab and waits for its first page: the rows, or the empty notice. */
    async function openFailed(wrapper: Awaited<ReturnType<typeof page>>['wrapper']) {
        await wrapper.find('[data-tab="failed"]').trigger('click')
        await vi.waitFor(() => expect(wrapper.find('[data-failed-job]').exists() || wrapper.text().includes('No failed jobs match.')).toBe(true))
        await flushPromises()
    }

    it('offers no retry, forget or prune on the failed tab the permissions refuse, on a row or its detail', async () => {
        const [id] = ids(1) as [string]
        const detail = endpoint(`/vector/queues/failed/${id}`, { body: { data: { ...job(id), args: {} } } }, 'GET')
        cleanups.push(detail.unregister)
        const { wrapper } = await page(true, permissions({ retry: false, forget: false, prune: false }), true, failedPage([job(id)]))

        await openFailed(wrapper)
        const row = wrapper.find(`[data-failed-job="${id}"]`)
        expect(row.exists()).toBe(true)

        expect(wrapper.find('[data-action="retry-selected"]').exists()).toBe(false)
        expect(wrapper.find('[data-action="prune"]').exists()).toBe(false)
        expect(row.find('[data-action="retry"]').exists()).toBe(false)
        expect(row.find('[data-action="forget"]').exists()).toBe(false)
        expect(row.find('input[type="checkbox"]').exists()).toBe(false)

        // viewing is still everyone's: the detail opens, with no action on it either
        await row.find('[data-action="open"]').trigger('click')
        await vi.waitFor(() => expect(wrapper.find('[data-field="message"]').exists()).toBe(true))
        expect(wrapper.find('[data-action="retry"]').exists()).toBe(false)
        expect(wrapper.find('[data-action="forget"]').exists()).toBe(false)
        wrapper.unmount()
    })

    // the confirmation dialog is the layout's; these tests mount it beside the page, and it teleports to the body
    const confirmField = () => document.querySelector<HTMLElement>('.v-overlay__content [data-field="confirm-text"]')
    const confirmButton = (label: string) => [...document.querySelectorAll<HTMLButtonElement>('.v-overlay__content .v-card-actions button')].find(b => b.textContent?.trim() === label)

    async function typeConfirmation(text: string) {
        const input = confirmField()!.querySelector('input')!
        input.value = text
        input.dispatchEvent(new Event('input', { bubbles: true }))
        await flushPromises()
    }

    it('clears a queue only once its name is typed exactly, sends what was typed, and refreshes', async () => {
        const clear = endpoint('/vector/queues/mail/clear', { body: { data: { queue: 'mail', cleared: 3 } } }, 'POST')
        cleanups.push(clear.unregister)
        const { wrapper, overview } = await page(true)
        const dialog = await mountSuspended(VxConfirm)

        // only the queue the policy lets this user clear has the button
        expect(wrapper.find('[data-queue="reports"] [data-action="clear"]').exists()).toBe(false)
        await wrapper.find('[data-queue="mail"] [data-action="clear"]').trigger('click')
        await flushPromises()

        expect(useConfirm().pending.value?.title).toBe('Clear "mail"?')
        expect(confirmField()?.querySelector('label')?.textContent).toContain('Type "mail" to confirm')

        for (const wrong of ['', 'Mail', 'mai', 'mail ', 'reports']) {
            await typeConfirmation(wrong)
            expect(confirmButton('Confirm')?.disabled, JSON.stringify(wrong)).toBe(true)
            confirmButton('Confirm')!.click()
            useConfirm().settle(true)
            await flushPromises()
            expect(useConfirm().pending.value?.title).toBe('Clear "mail"?')
        }
        expect(clear.requests).toHaveLength(0)

        const before = overview.requests.length
        await typeConfirmation('mail')
        expect(confirmButton('Confirm')?.disabled).toBe(false)
        confirmButton('Confirm')!.click()
        await vi.waitFor(() => expect(overview.requests.length).toBeGreaterThan(before))

        expect(clear.requests.map(r => [r.method, r.path, r.body])).toEqual([['POST', '/vector/queues/mail/clear', { confirm: 'mail' }]])
        expect(toasts()).toEqual([['Cleared 3 job(s) from "mail".', 'success']])
        dialog.unmount()
        wrapper.unmount()
    })

    it('says so when the backend cannot clear (409)', async () => {
        const clear = endpoint('/vector/queues/mail/clear', { status: 409, body: { error: 'This queue cannot be cleared.' } }, 'POST')
        cleanups.push(clear.unregister)
        const { wrapper } = await page(true)

        await wrapper.find('[data-queue="mail"] [data-action="clear"]').trigger('click')
        await flushPromises()
        useConfirm().typed.value = 'mail'
        useConfirm().settle(true)
        await vi.waitFor(() => expect(toasts()).toHaveLength(1))

        expect(clear.requests.map(r => r.body)).toEqual([{ confirm: 'mail' }])
        expect(toasts()).toEqual([['This queue backend cannot discard waiting jobs, so "mail" was not cleared.', 'error']])
        wrapper.unmount()
    })

    it('clears nothing when the confirmation is declined', async () => {
        const clear = endpoint('/vector/queues/mail/clear', { body: { data: { queue: 'mail', cleared: 3 } } }, 'POST')
        cleanups.push(clear.unregister)
        const { wrapper } = await page(true)

        await wrapper.find('[data-queue="mail"] [data-action="clear"]').trigger('click')
        await flushPromises()
        useConfirm().settle(false)
        await flushPromises()

        expect(clear.requests).toHaveLength(0)
        wrapper.unmount()
    })

    it('renders a failed job\'s message, trace and arguments as text, never as markup', async () => {
        const [id] = ids(1) as [string]
        const detail = endpoint(`/vector/queues/failed/${id}`, { body: { data: {
            ...job(id),
            exception: { class: 'RuntimeException', message: `The mail server said ${XSS}`, trace: `#0 ${XSS}` },
            args: { note: XSS },
        } } }, 'GET')
        cleanups.push(detail.unregister)
        const { wrapper } = await page(true, permissions(), true, failedPage([job(id)]))

        await openFailed(wrapper)
        const row = wrapper.find(`[data-failed-job="${id}"]`)
        expect(row.text()).toContain(`The mail server said ${XSS}`)
        expect(wrapper.find('img').exists()).toBe(false)

        await row.find('[data-action="open"]').trigger('click')
        await vi.waitFor(() => expect(wrapper.find('[data-field="message"]').exists()).toBe(true))

        expect(wrapper.find('[data-field="message"]').text()).toBe(`The mail server said ${XSS}`)
        expect(wrapper.find('[data-field="trace"]').text()).toBe(`#0 ${XSS}`)
        expect(wrapper.find('[data-field="args"]').text()).toContain(`"note": "${XSS}"`)
        expect(wrapper.find('img').exists()).toBe(false)
        expect(document.querySelector('img[src="x"]')).toBeNull()
        wrapper.unmount()
    })

    /** Ticks the checkbox of the row at `index`, as the user would: VCheckbox's own model update. */
    async function select(wrapper: Awaited<ReturnType<typeof page>>['wrapper'], index: number) {
        await wrapper.findAllComponents({ name: 'VCheckbox' })[index]!.setValue(true)
        await flushPromises()
    }

    it('retries the selected jobs by their ids, says how it went, and reloads the list', async () => {
        const [a, b, c] = ids(3) as [string, string, string]
        const retry = endpoint('/vector/queues/failed/retry', { body: { data: [a, b].map(id => ({ id, status: 'retried', class: 'App\\Jobs\\SendInvoiceJob', queue: 'mail', reason: null })) } }, 'POST')
        cleanups.push(retry.unregister)
        const { wrapper, failed } = await page(true, permissions(), true, failedPage([job(a), job(b), job(c)]))

        await openFailed(wrapper)
        await select(wrapper, 0)
        await select(wrapper, 1)
        expect(wrapper.find('[data-action="retry-selected"]').text()).toContain('Retry selected (2)')

        await wrapper.find('[data-action="retry-selected"]').trigger('click')
        await flushPromises()
        expect(useConfirm().pending.value?.title).toBe('Retry 2 job(s)?')
        expect(retry.requests).toHaveLength(0)
        const loads = failed.requests.length
        useConfirm().settle(true)
        await vi.waitFor(() => expect(failed.requests.length).toBeGreaterThan(loads))

        expect(retry.requests.map(r => [r.method, r.path, r.body])).toEqual([['POST', '/vector/queues/failed/retry', { ids: [a, b] }]])
        expect(toasts()).toEqual([['Retried 2; not found 0; failed 0.', 'success']])
        wrapper.unmount()
    })

    it('shows the server\'s refusal when a retry is refused (422)', async () => {
        const [a] = ids(1) as [string]
        const retry = endpoint('/vector/queues/failed/retry', { status: 422, body: { error: 'Every id must be 32 lowercase hex digits.' } }, 'POST')
        cleanups.push(retry.unregister)
        const { wrapper } = await page(true, permissions(), true, failedPage([job(a)]))

        await openFailed(wrapper)
        await wrapper.find(`[data-failed-job="${a}"] [data-action="retry"]`).trigger('click')
        await flushPromises()
        useConfirm().settle(true)
        await vi.waitFor(() => expect(toasts()).toHaveLength(1))

        expect(retry.requests.map(r => [r.method, r.path, r.body])).toEqual([['POST', '/vector/queues/failed/retry', { ids: [a] }]])
        expect(toasts()).toEqual([['The request was refused: Every id must be 32 lowercase hex digits.', 'error']])
        wrapper.unmount()
    })

    it('forgets one failed job by its id', async () => {
        const [a, b] = ids(2) as [string, string]
        const forget = endpoint(`/vector/queues/failed/${a}`, { body: {} }, 'DELETE')
        cleanups.push(forget.unregister)
        const { wrapper, failed } = await page(true, permissions(), true, failedPage([job(a), job(b)]))

        await openFailed(wrapper)
        await wrapper.find(`[data-failed-job="${a}"] [data-action="forget"]`).trigger('click')
        await flushPromises()
        expect(useConfirm().pending.value?.title).toBe('Forget this failed job?')
        const loads = failed.requests.length
        useConfirm().settle(true)
        await vi.waitFor(() => expect(failed.requests.length).toBeGreaterThan(loads))

        expect(forget.requests.map(r => [r.method, r.path, r.body])).toEqual([['DELETE', `/vector/queues/failed/${a}`, undefined]])
        expect(toasts()).toEqual([['The failed job was forgotten.', 'success']])
        wrapper.unmount()
    })

    it('prunes the records older than the days given', async () => {
        const prune = endpoint('/vector/queues/failed/prune', { body: { data: { deleted: 4, olderThanSeconds: 7 * 86400 } } }, 'POST')
        cleanups.push(prune.unregister)
        const { wrapper } = await page(true, permissions(), true, failedPage([job(ids(1)[0]!)]))

        await openFailed(wrapper)
        await wrapper.findAllComponents({ name: 'VTextField' }).find(field => field.props('label') === 'Older than (days)')!.setValue('7')
        await flushPromises()
        await wrapper.find('[data-action="prune"]').trigger('click')
        await flushPromises()
        expect(useConfirm().pending.value?.title).toBe('Prune failed jobs older than 7 day(s)?')
        useConfirm().settle(true)
        await vi.waitFor(() => expect(toasts()).toHaveLength(1))

        expect(prune.requests.map(r => [r.method, r.path, r.body])).toEqual([['POST', '/vector/queues/failed/prune', { days: 7 }]])
        expect(toasts()).toEqual([['Deleted 4 failure record(s).', 'success']])
        wrapper.unmount()
    })

    it('selects at most 50 jobs for one retry', async () => {
        const all = ids(51)
        const retry = endpoint('/vector/queues/failed/retry', { body: { data: [] } }, 'POST')
        cleanups.push(retry.unregister)
        const { wrapper } = await page(true, permissions(), true, failedPage(all.map(job)))

        await openFailed(wrapper)
        for (let index = 0; index < 50; index++) await select(wrapper, index)

        expect(wrapper.find('[data-action="retry-selected"]').text()).toContain('Retry selected (50)')
        expect(wrapper.text()).toContain('At most 50 jobs are retried at once.')
        const last = wrapper.findAllComponents({ name: 'VCheckbox' })[50]!
        expect(last.props('disabled')).toBe(true)
        // nor does a 51st tick that gets through anyway count
        await select(wrapper, 50)
        expect(wrapper.find('[data-action="retry-selected"]').text()).toContain('Retry selected (50)')

        await wrapper.find('[data-action="retry-selected"]').trigger('click')
        await flushPromises()
        useConfirm().settle(true)
        await vi.waitFor(() => expect(retry.requests).toHaveLength(1))
        expect(retry.requests[0]?.body).toEqual({ ids: all.slice(0, 50) })
        wrapper.unmount()
    })

    it('appends the next page on "Load more", asking with the server\'s cursor', async () => {
        const [a, b, c] = ids(3) as [string, string, string]
        const { wrapper, failed } = await page(true, permissions(), true, request => query(request).cursor === 'page-2'
            ? failedPage([job(c)])
            : failedPage([job(a), job(b)], 'page-2'))

        await openFailed(wrapper)
        expect(wrapper.findAll('[data-failed-job]').map(row => row.attributes('data-failed-job'))).toEqual([a, b])

        await wrapper.find('[data-action="load-more"]').trigger('click')
        await vi.waitFor(() => expect(wrapper.findAll('[data-failed-job]')).toHaveLength(3))

        expect(wrapper.findAll('[data-failed-job]').map(row => row.attributes('data-failed-job'))).toEqual([a, b, c])
        expect(failed.requests.map(query)).toEqual([{}, { cursor: 'page-2' }])
        // the last page has no cursor: nothing more to load
        expect(wrapper.find('[data-action="load-more"]').exists()).toBe(false)
        wrapper.unmount()
    })

    /** The filter field labelled `label`. */
    const textField = (wrapper: Awaited<ReturnType<typeof page>>['wrapper'], label: string) =>
        wrapper.findAllComponents({ name: 'VTextField' }).find(field => field.props('label') === label)!

    it('sends the applied filters, the dates in UTC, and clears the selection', async () => {
        const [a, b] = ids(2) as [string, string]
        const { wrapper, failed } = await page(true, permissions(), true, failedPage([job(a), job(b)]))

        await openFailed(wrapper)
        await select(wrapper, 0)
        expect(wrapper.find('[data-action="retry-selected"]').text()).toContain('Retry selected (1)')

        await wrapper.findComponent({ name: 'VSelect' }).setValue('mail')
        await textField(wrapper, 'Job class').setValue('  SendInvoiceJob ')
        // the tests run in Pacific/Honolulu, UTC-10
        await textField(wrapper, 'Failed from').setValue('2026-11-01T08:30:00')
        await textField(wrapper, 'Failed until').setValue('2026-11-02T00:00:00')
        await wrapper.find('[data-action="apply"]').trigger('click')
        await vi.waitFor(() => expect(failed.requests).toHaveLength(2))
        await flushPromises()

        expect(query(failed.requests[1]!)).toEqual({ queue: 'mail', class: 'SendInvoiceJob', failedFrom: '2026-11-01T18:30:00Z', failedTo: '2026-11-02T10:00:00Z' })
        expect(wrapper.find('[data-action="retry-selected"]').text()).toContain('Retry selected (0)')
        wrapper.unmount()
    })

    it('shows an unreadable date as an error on its field and sends nothing', async () => {
        const { wrapper, failed } = await page(true, permissions(), true, failedPage([job(ids(1)[0]!)]))

        await openFailed(wrapper)
        const loads = failed.requests.length
        await textField(wrapper, 'Failed from').setValue('not a date')
        await flushPromises()

        expect(wrapper.find('[data-filter="failedFrom"]').text()).toContain('Enter a complete, valid date and time.')
        expect(wrapper.find('[data-action="apply"]').attributes('disabled')).toBeDefined()
        await wrapper.find('[data-action="apply"]').trigger('click')
        // Enter in the class field applies too: refused the same way
        await textField(wrapper, 'Job class').find('input').trigger('keydown', { key: 'Enter' })
        await flushPromises()
        expect(failed.requests).toHaveLength(loads)

        // a readable date again: the error goes, and Apply sends it
        await textField(wrapper, 'Failed from').setValue('2026-11-01T08:30:00')
        await flushPromises()
        expect(wrapper.find('[data-filter="failedFrom"]').text()).not.toContain('Enter a complete, valid date and time.')
        await wrapper.find('[data-action="apply"]').trigger('click')
        await vi.waitFor(() => expect(failed.requests).toHaveLength(loads + 1))
        expect(query(failed.requests[loads]!)).toEqual({ failedFrom: '2026-11-01T18:30:00Z' })
        wrapper.unmount()
    })

    it('names every column header, the selection and action columns included', async () => {
        const { wrapper } = await page(true, permissions(), true, failedPage([job(ids(1)[0]!)]))

        const blank = () => wrapper.findAll('th').filter(th => th.text().trim() === '').length
        expect(wrapper.findAll('th').length).toBeGreaterThan(0)
        expect(blank()).toBe(0)
        await openFailed(wrapper)
        expect(wrapper.findAll('th').length).toBeGreaterThan(0)
        expect(blank()).toBe(0)
        wrapper.unmount()
    })

    describe('the overview on its own', () => {
        /** Lets the overview's requests settle without advancing the clock past now (the poll is QUEUE_POLL_MS away). */
        async function settle() {
            for (let round = 0; round < 10; round++) {
                if (vi.isFakeTimers()) vi.advanceTimersByTime(0)
                await flushPromises()
            }
        }

        async function overviewOf(reply: Reply, granted: VectorQueuePermissions = permissions()) {
            const overview = endpoint('/vector/queues', reply, 'GET')
            cleanups.push(overview.unregister)
            const wrapper = await mountSuspended(QueuesOverview, { props: { permissions: granted, failedJobs: true } })
            await settle()
            return { wrapper, overview }
        }

        it('polls every QUEUE_POLL_MS while mounted, and never after it is unmounted', async () => {
            vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
            const { wrapper, overview } = await overviewOf({ body: { data: rows(2) } })
            expect(overview.requests).toHaveLength(1)

            // the control: while mounted, the next request is one interval away
            vi.advanceTimersByTime(QUEUE_POLL_MS)
            await settle()
            expect(overview.requests).toHaveLength(2)

            wrapper.unmount()
            vi.advanceTimersByTime(QUEUE_POLL_MS * 3)
            await settle()
            expect(overview.requests).toHaveLength(2)
        })

        it('stops polling once the server answers 404', async () => {
            vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
            const { wrapper, overview } = await overviewOf({ status: 404, body: { error: 'Not found.' } })

            expect(wrapper.find('[data-state="no-access"]').exists()).toBe(true)
            expect(overview.requests).toHaveLength(1)
            vi.advanceTimersByTime(QUEUE_POLL_MS * 3)
            await settle()
            expect(overview.requests).toHaveLength(1)
            wrapper.unmount()
        })

        it('shows only the queue being cleared as busy', async () => {
            let answer: () => void = () => undefined
            const clear = endpoint('/vector/queues/mail/clear', () => new Promise(resolve => {
                answer = () => resolve({ body: { data: { queue: 'mail', cleared: 1 } } })
            }), 'POST')
            cleanups.push(clear.unregister)
            const { wrapper } = await overviewOf({ body: { data: rows(0) } }, permissions({ clear: { mail: true, reports: true } }))

            await wrapper.find('[data-queue="mail"] [data-action="clear"]').trigger('click')
            await flushPromises()
            useConfirm().typed.value = 'mail'
            useConfirm().settle(true)
            await vi.waitFor(() => expect(clear.requests).toHaveLength(1))
            await flushPromises()

            expect(wrapper.find('[data-queue="mail"] [data-action="clear"]').classes()).toContain('v-btn--loading')
            expect(wrapper.find('[data-queue="reports"] [data-action="clear"]').classes()).not.toContain('v-btn--loading')
            answer()
            await vi.waitFor(() => expect(wrapper.find('[data-queue="mail"] [data-action="clear"]').classes()).not.toContain('v-btn--loading'))
            wrapper.unmount()
        })

        it('tells a keyboard and a screen reader why Clear is unavailable', async () => {
            const { wrapper } = await overviewOf({ body: { data: [{ name: 'mail', size: 3, clearable: false, failed: 0 }] } })

            const holder = wrapper.find('[data-queue="mail"] [data-state="clear-unavailable"]')
            expect(holder.exists()).toBe(true)
            expect(holder.find('button').attributes('disabled')).toBeDefined()
            // the wrapper takes the focus the disabled button cannot, and is described by the reason
            expect(holder.attributes('tabindex')).toBe('0')
            const reason = holder.attributes('aria-describedby')!
            expect(wrapper.find(`[id="${reason}"]`).text()).toBe('This queue backend cannot discard waiting jobs.')
            wrapper.unmount()
        })
    })
})
