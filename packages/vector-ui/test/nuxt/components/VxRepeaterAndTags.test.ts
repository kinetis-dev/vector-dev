import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import VxResourceForm from '#vector/app/components/Vx/Resource/Form.vue'
import VxCell from '#vector/app/components/Vx/Cell.vue'
import { normalizeTags } from '#vector/app/utils/internal/repeater'
import type { VectorField } from '#vector/app/types/vector'
import { endpoint, standaloneApi } from '../endpoints'
import { field, resource, rule, schema } from '../fixtures'

const cleanups: (() => void)[] = []
const mock = (...args: Parameters<typeof endpoint>) => {
    const registered = endpoint(...args)
    cleanups.push(registered.unregister)
    return registered
}
const mounted: VueWrapper[] = []

beforeEach(() => {
    standaloneApi()
    useVectorNotifications().queue.value = []
})

afterEach(() => {
    cleanups.splice(0).forEach(unregister => unregister())
    mounted.splice(0).forEach(wrapper => wrapper.unmount())
    useVectorSchema().clear()
})

async function mountForm(props: InstanceType<typeof VxResourceForm>['$props']) {
    const wrapper = await mountSuspended(VxResourceForm, { props })
    mounted.push(wrapper)
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.findAll('input').length).toBeGreaterThan(0))
    return wrapper
}

async function submit(wrapper: VueWrapper) {
    await wrapper.find('form').trigger('submit')
    await flushPromises()
}

/** The value of each input of each row, as the rows are shown. */
const rowValues = (wrapper: VueWrapper) => wrapper.findAll('fieldset.vx-repeater-row')
    .map(row => row.findAll('input').map(i => (i.element as HTMLInputElement).value))

const specs = field({
    name: 'specs',
    type: 'repeater',
    label: 'Specifications',
    nullable: true,
    repeater: {
        mode: 'json',
        fields: ['label', 'value'],
        min: 1,
        max: 3,
        sortable: true,
        orderBy: null,
        rowFields: [
            field({ name: 'label', nullable: false, rules: [rule('NotBlank'), rule('MaxLength', 60)] }),
            field({ name: 'value', nullable: false, rules: [rule('NotBlank')] }),
        ],
    },
})
const keywords = field({ name: 'keywords', type: 'tag', nullable: true, operators: ['any', 'all', 'null', 'nnull'], filterable: true, tags: { suggestions: ['eco', 'gift'], maxLength: 10, maxTags: 3 } })
const products = resource({
    slug: 'products',
    label: 'Products',
    labelSingular: 'Product',
    fields: [field({ name: 'id', type: 'id', readonly: true, nullable: false }), field({ name: 'name', nullable: false }), specs, keywords],
})
const record = {
    id: 5,
    name: 'Mug',
    specs: [{ label: 'Warranty', value: '2 years' }, { label: 'Made in', value: 'Portugal' }, { label: 'Battery', value: '12 h' }],
    keywords: ['eco'],
}

describe('a JSON repeater in a form', () => {
    it('shows a row of inputs per row, and keeps add and remove within min and max', async () => {
        const form = await mountForm({ resource: products, operation: 'update', record })

        expect(rowValues(form)).toEqual([['Warranty', '2 years'], ['Made in', 'Portugal'], ['Battery', '12 h']])
        const add = form.findAll('button').find(b => b.text() === 'Add row')!
        expect(add.attributes('disabled')).toBeDefined() // three of at most three

        await form.find('button[aria-label="Remove row 2"]').trigger('click')
        expect(rowValues(form)).toEqual([['Warranty', '2 years'], ['Battery', '12 h']])
        expect(add.attributes('disabled')).toBeUndefined()
        await form.find('button[aria-label="Remove row 2"]').trigger('click')
        expect(form.find('button[aria-label="Remove row 1"]').attributes('disabled')).toBeDefined() // at least one
    })

    it('starts a new record with the rows it must hold', async () => {
        const form = await mountForm({ resource: products, operation: 'create' })

        expect(rowValues(form)).toEqual([['', '']])
    })

    it('reorders rows by dragging a handle, and with the arrow keys on it', async () => {
        const update = mock('/vector/r/products/5', { body: { data: record, permissions: {} } }, 'PATCH')
        const form = await mountForm({ resource: products, operation: 'update', record })
        const rows = () => form.findAll('fieldset.vx-repeater-row')

        // drag the third row onto the first
        await rows()[2]!.find('.vx-repeater-handle').trigger('dragstart', { dataTransfer: { setData: () => undefined, effectAllowed: '' } })
        await rows()[0]!.trigger('dragover')
        await rows()[0]!.trigger('drop')
        expect(rowValues(form).map(r => r[0])).toEqual(['Battery', 'Warranty', 'Made in'])
        expect(form.text()).toContain('Row 3 moved to position 1')

        // the second row down one
        await rows()[1]!.find('.vx-repeater-handle').trigger('keydown', { key: 'ArrowDown' })
        expect(rowValues(form).map(r => r[0])).toEqual(['Battery', 'Made in', 'Warranty'])

        await submit(form)

        await vi.waitFor(() => expect(update.requests).toHaveLength(1))
        expect(update.requests[0]!.body).toEqual({ specs: [{ label: 'Battery', value: '12 h' }, { label: 'Made in', value: 'Portugal' }, { label: 'Warranty', value: '2 years' }] })
    })

    it('puts a row\'s server errors on that row\'s inputs', async () => {
        mock('/vector/r/products/5', {
            status: 422,
            body: { errors: [
                { path: ['specs', 1, 'value'], code: 'max_length', message: 'is too long.' },
                { path: ['specs', 2, 'colour'], code: 'unexpected_field', message: 'is not a field of this row.' },
            ] },
        }, 'PATCH')
        const form = await mountForm({ resource: products, operation: 'update', record })
        await form.findAll('fieldset.vx-repeater-row')[0]!.findAll('input')[1]!.setValue('3 years')

        await submit(form)

        await vi.waitFor(() => expect(form.findAll('fieldset.vx-repeater-row')[1]!.text()).toContain('Is too long.'))
        expect(form.findAll('fieldset.vx-repeater-row')[0]!.text()).not.toContain('Is too long.')
        expect(form.findAll('fieldset.vx-repeater-row')[2]!.text()).toContain('Is not a field of this row.')
    })
})

describe('a relationship repeater in a form', () => {
    const lines = resource({
        slug: 'order-lines',
        label: 'Order lines',
        labelSingular: 'Order line',
        fields: [
            field({ name: 'id', type: 'id', readonly: true, nullable: false }),
            field({ name: 'sku', label: 'SKU', nullable: false }),
            field({ name: 'quantity', type: 'number', valueType: 'int', nullable: false }),
        ],
    })
    const orders = resource({
        slug: 'orders',
        label: 'Orders',
        labelSingular: 'Order',
        fields: [
            field({ name: 'id', type: 'id', readonly: true, nullable: false }),
            field({ name: 'number', nullable: false }),
            field({
                name: 'lines',
                type: 'repeater',
                nullable: false,
                relation: { kind: 'hasMany', resource: 'order-lines', titleField: null, inverse: 'order' },
                repeater: { mode: 'relationship', fields: ['sku', 'quantity'], min: 0, max: 10, sortable: true, orderBy: 'position' },
            }),
        ],
    })

    beforeEach(() => {
        useVectorSchema().schema.value = schema([orders, lines])
    })

    it('loads the rows in their order, and sends them reordered with their ids', async () => {
        mock('/vector/r/orders/9/lines/rows', { body: { data: [{ id: 21, sku: 'A', quantity: 1 }, { id: 22, sku: 'B', quantity: 2 }] } })
        const update = mock('/vector/r/orders/9', { body: { data: { id: 9 }, permissions: {} } }, 'PATCH')
        const form = await mountForm({ resource: orders, operation: 'update', record: { id: 9, number: 'ORD-9', lines: { count: 2 } } })
        await vi.waitFor(() => expect(rowValues(form)).toEqual([['A', '1'], ['B', '2']]))

        expect(form.find('fieldset[aria-label="Order line 1"]').exists()).toBe(true)
        await form.findAll('fieldset.vx-repeater-row')[1]!.find('.vx-repeater-handle').trigger('keydown', { key: 'ArrowUp' })

        await submit(form)

        await vi.waitFor(() => expect(update.requests).toHaveLength(1))
        expect(update.requests[0]!.body).toEqual({ lines: [{ id: 22, sku: 'B', quantity: 2 }, { id: 21, sku: 'A', quantity: 1 }] })
    })

    it('offers no handle when the rows keep no order', async () => {
        mock('/vector/r/orders/9/lines/rows', { body: { data: [{ id: 21, sku: 'A', quantity: 1 }] } })
        const unordered = resource({ ...orders, fields: orders.fields.map(f => f.name === 'lines' ? { ...f, repeater: { ...f.repeater!, sortable: false, orderBy: null } } : f) })
        const form = await mountForm({ resource: unordered, operation: 'update', record: { id: 9, number: 'ORD-9', lines: { count: 1 } } })
        await vi.waitFor(() => expect(rowValues(form)).toEqual([['A', '1']]))

        expect(form.find('.vx-repeater-handle').exists()).toBe(false)
    })
})

describe('tags in a form', () => {
    it('shows the tags as chips and sends them normalized', async () => {
        const update = mock('/vector/r/products/5', { body: { data: record, permissions: {} } }, 'PATCH')
        const form = await mountForm({ resource: products, operation: 'update', record })
        const input = form.findAll('.v-input').find(i => i.find('label').text() === 'Keywords')!

        expect(input.findAll('.v-chip').map(c => c.text())).toEqual(['eco'])
        expect(input.classes()).toContain('v-combobox')
        const combobox = form.findComponent({ name: 'VCombobox' })
        combobox.vm.$emit('update:modelValue', ['eco', ' Gift ', 'ECO', '', 'gift'])
        await flushPromises()

        await submit(form)

        await vi.waitFor(() => expect(update.requests).toHaveLength(1))
        expect(update.requests[0]!.body).toEqual({ keywords: ['eco', 'Gift'] })
    })

    it('normalizes as the server does: trimmed, no empty tag, each once ignoring case', () => {
        expect(normalizeTags(['  Eco ', 'eco', '', 'gift', 'ECO', 7, 'Été', 'été'])).toEqual(['Eco', 'gift', 'Été'])
    })
})

describe('repeater and tag cells', () => {
    async function cell(f: VectorField, value: unknown, context: 'index' | 'show', recordOf: Record<string, unknown> = {}) {
        const wrapper = await mountSuspended(VxCell, { props: { field: f, value, record: recordOf, resource: products, context } })
        await flushPromises()
        await vi.waitFor(() => expect(wrapper.html()).not.toBe('<!---->'))
        return wrapper
    }

    it('counts the rows in a list and shows them as a table on a record', async () => {
        expect((await cell(specs, record.specs, 'index')).text()).toBe('3')

        const shown = await cell(specs, record.specs, 'show')
        await vi.waitFor(() => expect(shown.findAll('th').map(th => th.text())).toEqual(['Label', 'Value']))
        expect(shown.findAll('tbody tr').map(tr => tr.findAll('td').map(td => td.text()))).toEqual([['Warranty', '2 years'], ['Made in', 'Portugal'], ['Battery', '12 h']])
        expect((await cell(specs, [], 'show')).text()).toBe('No rows')
    })

    it('shows tags as chips', async () => {
        expect((await cell(keywords, ['eco', 'gift'], 'index')).findAll('.v-chip').map(c => c.text())).toEqual(['eco', 'gift'])
        expect((await cell(keywords, null, 'index')).text()).toBe('—')
    })
})

describe('a tag filter', () => {
    it('takes several tags from a chips input and filters by any of them', async () => {
        const VxListFilterBar = (await import('#vector/app/components/Vx/List/FilterBar.vue')).default
        const wrapper = await mountSuspended(VxListFilterBar, { props: { resource: products, modelValue: {} }, attachTo: document.body })
        mounted.push(wrapper)

        await wrapper.findAll('button').find(b => b.text() === 'Add filter')!.trigger('click')
        await flushPromises()
        wrapper.findAllComponents({ name: 'VSelect' })[0]!.vm.$emit('update:modelValue', 'keywords')
        await flushPromises()

        const combobox = wrapper.findComponent({ name: 'VCombobox' })
        expect(combobox.props('items')).toEqual(['eco', 'gift'])
        combobox.vm.$emit('update:modelValue', ['eco', ' gift '])
        await flushPromises()
        await wrapper.findAllComponents({ name: 'VBtn' }).find(b => b.text() === 'Apply')!.trigger('click')

        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([{ keywords: { any: 'eco,gift' } }])
    })
})
