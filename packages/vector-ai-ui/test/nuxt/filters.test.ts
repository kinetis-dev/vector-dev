import { afterEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import VxAiFilterAsk from '#vector-ai/app/components/VxAi/FilterAsk.vue'
import { DESCRIPTOR_VERSION } from '#vector/app/composables/useVectorSchema'
import type { VectorResource } from '#vector/app/types/vector'
import { endpoint, standaloneApi } from './endpoints'

// The plain-language filter in a list's toolbar: shown only when
// capabilities.ai.filters is on, the resource is not kept from AI and it
// has a field to filter by; the answer reaches the list at once, through
// the list's own events, and Undo puts the list back as it was.

function descriptor(ai?: Record<string, unknown>) {
    return { version: DESCRIPTOR_VERSION, resources: {}, menu: [], settings: [], dashboards: [], capabilities: { impersonation: false, queue: false, ...(ai ? { ai } : {}) }, branding: null, ui: { expected: null } }
}

const ON = { filters: true, assistant: false, assistantWrites: false, writing: false, excluded: {} }

function field(name: string, label: string, type: string, operators: string[], extra: Record<string, unknown> = {}) {
    return { name, label, type, operators, sortable: false, searchable: false, filterable: operators.length > 0, views: ['index'], props: {}, ...extra }
}

function resource(fields = [
    field('status', 'Status', 'enum', ['eq', 'ne', 'in', 'nin'], { options: [{ value: 'paid', label: 'Paid' }, { value: 'shipped', label: 'Shipped' }] }),
    field('totalCents', 'Total', 'money', ['gt', 'lt']),
    field('customer', 'Customer', 'belongsTo', ['eq'], { relation: { kind: 'belongsTo', resource: 'customers' } }),
]): VectorResource {
    return {
        slug: 'orders', label: 'Orders', labelSingular: 'Order', group: null, icon: null, primaryKey: { field: 'id', type: 'int' }, titleField: 'number', version: null,
        fields, table: { columns: [], defaultSort: [], perPage: 25, maxPerPage: 100, search: [], groupable: [], summaries: [] },
    } as unknown as VectorResource
}

const PROPS = { filters: {}, groups: [], advanced: [], search: '' }

describe('the plain-language filter', () => {
    const cleanups: (() => void)[] = []

    afterEach(() => {
        cleanups.splice(0).forEach(cleanup => cleanup())
        useState('vector.schema').value = null
    })

    it('registers in the list toolbar, through the admin\'s extension points', () => {
        expect(useVectorExtensions().listToolbar.get('ai-filters')).toBeDefined()
        // the same points window.Vector v2 hands a runtime extension
        expect(window.Vector?.version).toBe(2)
        expect(typeof window.Vector?.defineListToolbarItem).toBe('function')
    })

    it.each([
        ['without capabilities.ai', undefined, resource()],
        ['with filters off', { ...ON, filters: false }, resource()],
        ['on a resource kept from AI', { ...ON, excluded: { orders: true } }, resource()],
        ['on a resource with nothing to filter by', ON, resource([field('notes', 'Notes', 'textarea', [])])],
    ])('shows nothing %s', async (_, ai, list) => {
        useState('vector.schema').value = descriptor(ai)
        const wrapper = await mountSuspended(VxAiFilterAsk, { props: { ...PROPS, resource: list } })

        expect(wrapper.find('[data-ai-filters]').exists()).toBe(false)
        wrapper.unmount()
    })

    it('filters the list at once, through the list\'s events, and says what it understood', async () => {
        standaloneApi()
        const ask = endpoint('/vector/ai/filters/orders', {
            body: { filter: { status: { eq: 'paid' }, totalCents: { gt: '50000' } }, groups: [[{ status: { eq: 'shipped' } }]], q: 'express', explanation: 'Paid orders above 500.00.', unresolved: [] },
        }, 'POST')
        cleanups.push(ask.unregister)
        useState('vector.schema').value = descriptor(ON)
        const wrapper = await mountSuspended(VxAiFilterAsk, { props: { ...PROPS, filters: { status: { ne: 'shipped' } }, resource: resource() } })

        const input = wrapper.find<HTMLInputElement>('[data-ai-filters-input] input')
        await input.setValue('paid orders over 500')
        await input.trigger('keydown', { key: 'Enter' })
        await vi.waitFor(() => expect(wrapper.find('[data-ai-filters-note]').exists()).toBe(true))

        expect(ask.requests[0]!.body).toMatchObject({ text: 'paid orders over 500', current: { filter: { status: { ne: 'shipped' } } } })
        expect(typeof (ask.requests[0]!.body as { timezone: unknown }).timezone).toBe('string')
        expect(wrapper.emitted('update:filters')).toEqual([[{ status: { eq: 'paid' }, totalCents: { gt: '50000' } }]])
        expect(wrapper.emitted('update:groups')).toEqual([[[[{ status: { eq: 'shipped' } }]]]])
        expect(wrapper.emitted('update:search')).toEqual([['express']])
        expect(wrapper.find('[data-ai-filters-explanation]').text()).toContain('Paid orders above 500.00.')
        // the sentence stays, to be refined and sent again
        expect(input.element.value).toBe('paid orders over 500')

        await wrapper.find('[data-ai-filters-close]').trigger('click')
        expect(wrapper.find('[data-ai-filters-note]').exists()).toBe(false)
        expect(wrapper.emitted('update:filters')).toHaveLength(1)
        wrapper.unmount()
    })

    it('puts the list back as it was on Undo, its search included', async () => {
        standaloneApi()
        cleanups.push(endpoint('/vector/ai/filters/orders', { body: { filter: { status: { eq: 'paid' } }, groups: [], q: 'express', explanation: 'Paid orders.', unresolved: [] } }, 'POST').unregister)
        useState('vector.schema').value = descriptor(ON)
        const groups = [[{ totalCents: { gt: '100' } }]]
        const wrapper = await mountSuspended(VxAiFilterAsk, { props: { ...PROPS, filters: { status: { ne: 'shipped' } }, groups, search: 'acme', resource: resource() } })

        const input = wrapper.find<HTMLInputElement>('[data-ai-filters-input] input')
        await input.setValue('paid orders')
        await input.trigger('keydown', { key: 'Enter' })
        await vi.waitFor(() => expect(wrapper.find('[data-ai-filters-note]').exists()).toBe(true))
        await wrapper.setProps({ filters: { status: { eq: 'paid' } }, groups: [], search: 'express' })

        await wrapper.find('[data-ai-filters-undo]').trigger('click')

        expect(wrapper.emitted('update:filters')!.at(-1)).toEqual([{ status: { ne: 'shipped' } }])
        expect(wrapper.emitted('update:groups')!.at(-1)).toEqual([groups])
        expect(wrapper.emitted('update:search')!.at(-1)).toEqual(['acme'])
        expect(wrapper.find('[data-ai-filters-note]').exists()).toBe(false)
        wrapper.unmount()
    })

    it('offers the candidates of an ambiguous relation, and adds the one picked to the list\'s filter', async () => {
        standaloneApi()
        cleanups.push(endpoint('/vector/ai/filters/orders', {
            body: {
                filter: { status: { eq: 'paid' } }, groups: [], q: null, explanation: 'Paid orders of Acme.',
                unresolved: [{ field: 'customer', op: 'eq', text: 'Acme', candidates: [{ id: 4, label: 'Acme GmbH' }, { id: 9, label: 'Acme Ltd' }] }],
            },
        }, 'POST').unregister)
        useState('vector.schema').value = descriptor(ON)
        const wrapper = await mountSuspended(VxAiFilterAsk, { props: { ...PROPS, resource: resource() } })

        const input = wrapper.find<HTMLInputElement>('[data-ai-filters-input] input')
        await input.setValue('paid orders of acme')
        await input.trigger('keydown', { key: 'Enter' })
        await vi.waitFor(() => expect(wrapper.find('[data-ai-filters-unresolved="customer"]').exists()).toBe(true))
        expect(wrapper.emitted('update:filters')).toEqual([[{ status: { eq: 'paid' } }]])
        // the list page answers update:filters with the new prop
        await wrapper.setProps({ filters: { status: { eq: 'paid' } } })

        const unresolved = wrapper.find('[data-ai-filters-unresolved="customer"]')
        expect(unresolved.text()).toContain('Customer “Acme”: which one?')
        expect(unresolved.text()).toContain('Acme GmbH')
        expect(unresolved.text()).toContain('Acme Ltd')

        await unresolved.find('[data-ai-filters-candidate="9"]').trigger('click')

        expect(wrapper.find('[data-ai-filters-unresolved="customer"]').exists()).toBe(false)
        expect(wrapper.emitted('update:filters')!.at(-1)).toEqual([{ status: { eq: 'paid' }, customer: { eq: '9' } }])
        wrapper.unmount()
    })

    it('leaves the list\'s search as it is when the answer has none, Undo included', async () => {
        standaloneApi()
        const ask = endpoint('/vector/ai/filters/orders', { body: { filter: { status: { eq: 'paid' } }, groups: [], q: null, explanation: 'Paid orders.', unresolved: [] } }, 'POST')
        cleanups.push(ask.unregister)
        useState('vector.schema').value = descriptor(ON)
        const wrapper = await mountSuspended(VxAiFilterAsk, { props: { ...PROPS, search: 'acme', resource: resource() } })

        const input = wrapper.find<HTMLInputElement>('[data-ai-filters-input] input')
        await input.setValue('only the paid ones')
        await input.trigger('keydown', { key: 'Enter' })
        await vi.waitFor(() => expect(wrapper.find('[data-ai-filters-note]').exists()).toBe(true))

        // the request does not carry the search, so an answer without one does not clear it
        expect((ask.requests[0]!.body as Record<string, unknown>).q).toBeUndefined()
        expect(wrapper.emitted('update:filters')).toEqual([[{ status: { eq: 'paid' } }]])
        expect(wrapper.emitted('update:search')).toBeUndefined()

        await wrapper.find('[data-ai-filters-undo]').trigger('click')
        expect(wrapper.emitted('update:filters')!.at(-1)).toEqual([{}])
        expect(wrapper.emitted('update:search')).toBeUndefined()
        wrapper.unmount()
    })

    it('shows a provider failure in its own words and keeps the sentence', async () => {
        standaloneApi()
        cleanups.push(endpoint('/vector/ai/filters/orders', { status: 504, body: { error: 'The AI provider did not answer in time.' } }, 'POST').unregister)
        useState('vector.schema').value = descriptor(ON)
        const wrapper = await mountSuspended(VxAiFilterAsk, { props: { ...PROPS, resource: resource() } })

        const input = wrapper.find<HTMLInputElement>('[data-ai-filters-input] input')
        await input.setValue('paid orders')
        await input.trigger('keydown', { key: 'Enter' })
        await vi.waitFor(() => expect(wrapper.find('[data-ai-filters-failure]').exists()).toBe(true))

        expect(wrapper.find('[data-ai-filters-failure]').text()).toContain('The AI provider did not answer in time.')
        expect(input.element.value).toBe('paid orders')
        expect(wrapper.find('[data-ai-filters-note]').exists()).toBe(false)
        expect(wrapper.emitted('update:filters')).toBeUndefined()
        wrapper.unmount()
    })

    it('says when a spent budget renews', async () => {
        standaloneApi()
        cleanups.push(endpoint('/vector/ai/filters/orders', { status: 429, headers: { 'Retry-After': '3600' }, body: { error: 'Today\'s AI budget is spent; it renews at midnight UTC.' } }, 'POST').unregister)
        useState('vector.schema').value = descriptor(ON)
        const wrapper = await mountSuspended(VxAiFilterAsk, { props: { ...PROPS, resource: resource() } })

        const input = wrapper.find<HTMLInputElement>('[data-ai-filters-input] input')
        await input.setValue('paid orders')
        await input.trigger('keydown', { key: 'Enter' })
        await vi.waitFor(() => expect(wrapper.find('[data-ai-filters-failure]').exists()).toBe(true))
        await flushPromises()

        const renews = new Date(Date.now() + 3600 * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        expect(wrapper.find('[data-ai-filters-failure]').text()).toContain(`Today's AI budget is spent; try again after ${renews}.`)
        wrapper.unmount()
    })

    it('says the budget is spent in the server\'s words when Retry-After cannot be read', async () => {
        // cross-origin, an API whose CORS does not expose Retry-After hands the admin no such header
        standaloneApi()
        cleanups.push(endpoint('/vector/ai/filters/orders', { status: 429, body: { error: 'Today\'s AI budget is spent; it renews at midnight UTC.' } }, 'POST').unregister)
        useState('vector.schema').value = descriptor(ON)
        const wrapper = await mountSuspended(VxAiFilterAsk, { props: { ...PROPS, resource: resource() } })

        const input = wrapper.find<HTMLInputElement>('[data-ai-filters-input] input')
        await input.setValue('paid orders')
        await input.trigger('keydown', { key: 'Enter' })
        await vi.waitFor(() => expect(wrapper.find('[data-ai-filters-failure]').exists()).toBe(true))
        await flushPromises()

        const failure = wrapper.find('[data-ai-filters-failure]').text()
        expect(failure).toContain('Today\'s AI budget is spent; it renews at midnight UTC.')
        expect(failure).not.toContain('try again after')
        wrapper.unmount()
    })
})
