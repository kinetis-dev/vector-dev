import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import VxResourceRelationPanel from '#vector/app/components/Vx/Resource/RelationPanel.vue'
import { endpoint, standaloneApi, type Received } from '../endpoints'
import { field, resource, schema } from '../fixtures'

// A customer's orders, whose resource is not counted: the panel pages
// them by previous and next, and moves its page number only once the
// page asked for has arrived.

const query = (request: Received) => Object.fromEntries(new URL(request.path, 'http://x').searchParams)
const orders = (ids: number[]) => ids.map(id => ({ id, name: `Order ${id}` }))

describe('VxResourceRelationPanel, an uncounted target', () => {
    const cleanups: (() => void)[] = []
    const customers = resource({ slug: 'customers', label: 'Customers', labelSingular: 'Customer' })
    const target = resource({ slug: 'orders', label: 'Orders', labelSingular: 'Order', fields: resource().fields.slice(0, 2) })

    beforeEach(() => {
        standaloneApi()
        useVectorSchema().schema.value = schema([customers, { ...target, table: { ...target.table, columns: ['id', 'name'], withoutCount: true } }])
    })
    afterEach(() => {
        cleanups.splice(0).forEach(unregister => unregister())
        useVectorSchema().clear()
    })

    it('stays on its page when the next one fails, and asks for that page again', async () => {
        let failures = 1
        const list = endpoint('/vector/r/customers/5/orders', (request) => {
            const page = Number(query(request).page)
            // refused, which the client does not retry
            if (page === 2 && failures-- > 0) return { status: 403, body: { error: 'Not now.' } }

            return { body: { data: page === 1 ? orders([1, 2]) : orders([3]), currentPage: page, perPage: 10, total: null, lastPage: null, hasMore: page === 1 } }
        })
        cleanups.push(list.unregister)
        const orderField = field({ name: 'orders', type: 'hasMany', views: ['show'], relation: { kind: 'hasMany', resource: 'orders', titleField: 'name', inverse: 'customer' } })
        const wrapper = await mountSuspended(VxResourceRelationPanel, { props: { owner: customers, record: { id: 5 }, field: orderField, canUpdate: false } })
        await vi.waitFor(() => expect(wrapper.text()).toContain('Order 1'))
        const next = () => wrapper.get('button[aria-label="Next page"]')
        expect(wrapper.text()).toContain('Page 1')

        await next().trigger('click')
        await vi.waitFor(() => expect(list.requests).toHaveLength(2))
        await flushPromises()
        await vi.waitFor(() => expect(next().attributes('disabled')).toBeUndefined())
        expect(query(list.requests[1]!).page).toBe('2')
        expect(wrapper.text()).toContain('Page 1')
        expect(wrapper.text()).toContain('Order 1')

        await next().trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Page 2'))
        expect(query(list.requests.at(-1)!).page).toBe('2')
        expect(wrapper.text()).toContain('Order 3')
    })
})
