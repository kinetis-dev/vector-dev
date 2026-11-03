import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { h } from 'vue'
import VectorLayout from '#vector/app/layouts/vector.vue'
import QueuesPage from '#vector/app/pages/queues/index.vue'
import type { VectorQueue, VectorQueuePermissions, VectorSchema } from '#vector/app/types/vector'
import { endpoint, standaloneApi } from '../endpoints'
import { schema } from '../fixtures'

// The queue dashboard is Vector's own page: its menu entry follows the
// descriptor's capabilities.queues, its failed-jobs tab, column and actions
// follow capabilities.failedJobs, and its actions call the server's
// /vector/queues endpoints.

const capabilities = (queues: boolean, failedJobs: boolean): VectorSchema['capabilities'] => ({ impersonation: false, queue: false, queues, failedJobs })

const permissions = (overrides: Partial<VectorQueuePermissions> = {}): VectorQueuePermissions => ({
    view: true, retry: true, forget: true, prune: true, clear: { mail: true, reports: false }, ...overrides,
})

const rows = (failed: number | null): VectorQueue[] => [
    { name: 'mail', size: 3, clearable: true, failed },
    { name: 'reports', size: null, clearable: true, failed },
]

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
    })

    async function page(failedJobs: boolean, granted: VectorQueuePermissions = permissions()) {
        useVectorSchema().schema.value = schema([], { capabilities: capabilities(true, failedJobs) })
        const overview = endpoint('/vector/queues', { body: { data: rows(failedJobs ? 2 : null) } }, 'GET')
        const failed = endpoint('/vector/queues/failed', { body: { data: [], nextCursor: null, hasMore: false, limit: 25 } })
        cleanups.push(endpoint('/vector/queues/permissions', { body: failedJobs ? granted : { ...granted, retry: false, forget: false, prune: false } }).unregister, overview.unregister, failed.unregister)
        const wrapper = await mountSuspended(QueuesPage, { route: '/queues' })
        // the permissions load on mount, through the environment's endpoint registry: wait for the tabs they gate
        await vi.waitFor(() => expect(wrapper.find('[data-tab="overview"]').exists()).toBe(true))
        await flushPromises()
        return { wrapper, overview, failed }
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

    it('offers no retry, forget or prune on the failed tab the permissions refuse', async () => {
        const { wrapper } = await page(true, permissions({ retry: false, forget: false, prune: false }))

        await wrapper.find('[data-tab="failed"]').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('No failed jobs match.'))

        expect(wrapper.find('[data-action="retry-selected"]').exists()).toBe(false)
        expect(wrapper.find('[data-action="prune"]').exists()).toBe(false)
        wrapper.unmount()
    })

    it('clears a queue once confirmed, with its name as the confirmation, and refreshes', async () => {
        const clear = endpoint('/vector/queues/mail/clear', { body: { data: { queue: 'mail', cleared: 3 } } }, 'POST')
        cleanups.push(clear.unregister)
        const { wrapper, overview } = await page(true)
        const before = overview.requests.length

        // only the queue the policy lets this user clear has the button
        expect(wrapper.find('[data-queue="reports"] [data-action="clear"]').exists()).toBe(false)
        await wrapper.find('[data-queue="mail"] [data-action="clear"]').trigger('click')
        await flushPromises()

        expect(useConfirm().pending.value?.title).toBe('Clear "mail"?')
        expect(clear.requests).toHaveLength(0)
        useConfirm().settle(true)
        await vi.waitFor(() => expect(overview.requests.length).toBeGreaterThan(before))

        expect(clear.requests.map(r => [r.method, r.body])).toEqual([['POST', { confirm: 'mail' }]])
        expect(useVectorNotifications().queue.value.map(n => [n.text, n.color])).toEqual([['Cleared 3 job(s) from "mail".', 'success']])
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
})
