import { describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import VxCell from '#vector/app/components/Vx/Cell.vue'
import { formatMoney } from '#vector/app/utils/format'
import type { VectorField } from '#vector/app/types/vector'
import { field, resource } from '../fixtures'

/** Renders one value through the field registry, as a list cell. */
async function cell(f: VectorField, value: unknown, record: Record<string, unknown> = {}) {
    const wrapper = await mountSuspended(VxCell, { props: { field: f, value, record, resource: resource(), context: 'index' } })
    await flushPromises() // the registry's cells are async components
    await vi.waitFor(() => expect(wrapper.html()).not.toBe('<!---->'))
    return wrapper
}

describe('VxCell', () => {
    it('renders text as it is', async () => {
        expect((await cell(field({ name: 'name' }), 'Espresso cup')).text()).toBe('Espresso cup')
    })

    it('renders a boolean as an icon, and a missing one as the none mark', async () => {
        expect((await cell(field({ name: 'f', type: 'boolean' }), true)).find('.mdi-check-circle').exists()).toBe(true)
        expect((await cell(field({ name: 'f', type: 'boolean' }), false)).find('.mdi-close-circle-outline').exists()).toBe(true)
        expect((await cell(field({ name: 'f', type: 'boolean' }), null)).text()).toBe('—')
    })

    it('renders money in the currency the record names', async () => {
        const price = field({ name: 'priceCents', type: 'money', props: { currencyField: 'currency' } })

        expect((await cell(price, 1999, { currency: 'EUR' })).text()).toBe(formatMoney(1999, 'EUR'))
        expect((await cell(price, 1999, { currency: 'USD' })).text()).toBe(formatMoney(1999, 'USD'))
        expect((await cell(price, null, { currency: 'USD' })).text()).toBe('—')
    })

    it('renders an enum by its option label', async () => {
        const status = field({
            name: 'status',
            type: 'enum',
            options: [{ value: 'draft', label: 'Draft', color: 'warning' }, { value: 'active', label: 'Active', color: 'success' }],
        })

        const active = await cell(status, 'active')
        expect(active.text()).toBe('Active')
        expect(active.find('.v-chip').classes()).toContain('text-success')
        expect((await cell(status, 'retired')).text()).toBe('retired')
        expect((await cell(status, null)).text()).toBe('—')
    })

    it('renders a computed value with the cell of what it is shown as', async () => {
        const total = field({ name: 'lineTotal', type: 'computed', readonly: true, props: { currencyField: 'order.currency' }, computed: { as: 'money', from: ['quantity', 'unitPriceCents'] } })
        expect((await cell(total, 8396, { order: { id: 1, label: 'ORD-1', currency: 'EUR' } })).text()).toBe(formatMoney(8396, 'EUR'))

        const paid = field({ name: 'paid', type: 'computed', computed: { as: 'boolean', from: [] } })
        expect((await cell(paid, true)).find('.mdi-check-circle').exists()).toBe(true)

        const size = field({ name: 'size', type: 'computed', options: [{ value: 'l', label: 'Large', color: null }], computed: { as: 'enum', from: [] } })
        expect((await cell(size, 'l')).text()).toBe('Large')

        const note = field({ name: 'note', type: 'computed', computed: { as: 'text', from: [] } })
        expect((await cell(note, 'two lines')).text()).toBe('two lines')
    })

    it('renders a relation by its label', async () => {
        const category = field({ name: 'category', type: 'belongsTo', relation: { kind: 'belongsTo', resource: 'categories', titleField: 'name' } })

        expect((await cell(category, { id: 3, label: 'Kitchen' })).text()).toContain('Kitchen')
    })
})
