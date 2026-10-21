import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import VxLayout from '#vector/app/components/Vx/Layout.vue'
import VxResourceForm from '#vector/app/components/Vx/Resource/Form.vue'
import type { VectorLayoutNode } from '#vector/app/types/vector'
import { endpoint, standaloneApi } from '../endpoints'
import { field, resource } from '../fixtures'

const node = (kind: VectorLayoutNode['kind'], extra: Partial<VectorLayoutNode> = {}): VectorLayoutNode => ({
    kind, label: null, description: null, collapsible: false, collapsed: false, columns: kind === 'field' ? null : 2, span: null, name: null, children: kind === 'field' ? null : [], ...extra,
})
const f = (name: string, span: VectorLayoutNode['span'] = null) => node('field', { name, span })

// id, a section, a collapsed section, and two tabs
const tree: VectorLayoutNode[] = [
    f('id'),
    node('section', { label: 'Details', description: 'The basics.', children: [f('sku'), f('name')] }),
    node('section', { label: 'Pricing', collapsible: true, collapsed: true, children: [f('price'), f('currency')] }),
    node('tabs', { children: [
        node('tab', { label: 'Content', children: [f('description', 'full')] }),
        node('tab', { label: 'Publishing', children: [f('releasedAt')] }),
    ] }),
]

async function layout(props: { shown?: (name: string) => boolean, errors?: string[] } = {}) {
    const wrapper = await mountSuspended(VxLayout, {
        props: { nodes: tree, shown: props.shown ?? (() => true), errors: props.errors ?? [], defaultSpan: () => 1 },
        slots: { field: ({ field }: { field: string }) => h('span', { class: 'probe' }, field) },
    })
    await flushPromises()
    return wrapper
}

const panelOpen = (w: Awaited<ReturnType<typeof layout>>, label: string) =>
    w.find(`[data-layout-section="${label}"] .v-expansion-panel`).classes().includes('v-expansion-panel--active')
const activeTab = (w: Awaited<ReturnType<typeof layout>>) => w.find('.v-tab--selected').text().trim()

describe('VxLayout', () => {
    it('renders fields, sections with their description, and tabs', async () => {
        const w = await layout()

        // every field is rendered, the inactive tab's too (eager), so a form keeps and validates it
        expect(w.findAll('.probe').map(p => p.text())).toEqual(['id', 'sku', 'name', 'price', 'currency', 'description', 'releasedAt'])
        expect(w.find('[data-layout-section="Details"]').text()).toContain('The basics.')
        expect(w.findAll('.v-tab').map(t => t.text().trim())).toEqual(['Content', 'Publishing'])
        expect(activeTab(w)).toBe('Content')
    })

    it('sizes a field by its span on the section grid', async () => {
        const w = await layout()

        expect(w.find('[data-layout-field="sku"]').classes()).toContain('v-col--cols-md-6')
        expect(w.find('[data-layout-field="description"]').classes()).toContain('v-col--cols-md-12')
    })

    it('starts a collapsed section closed', async () => {
        const w = await layout()

        expect(panelOpen(w, 'Pricing')).toBe(false)
    })

    it('does not draw a section or tab none of whose fields is shown', async () => {
        const hidden = new Set(['price', 'currency', 'releasedAt'])
        const w = await layout({ shown: name => !hidden.has(name) })

        expect(w.find('[data-layout-section="Pricing"]').exists()).toBe(false)
        expect(w.findAll('.v-tab').map(t => t.text().trim())).toEqual(['Content'])
    })

    it('opens the section and activates the tab holding an error, with a badge', async () => {
        const w = await layout({ errors: ['currency', 'releasedAt'] })

        expect(panelOpen(w, 'Pricing')).toBe(true)
        expect(activeTab(w)).toContain('Publishing')
        expect(w.find('[data-layout-tab="Publishing"] .v-badge').exists()).toBe(true)
        expect(w.find('[data-layout-tab="Content"] .v-badge').exists()).toBe(false)
    })

    it('reacts to errors that arrive later', async () => {
        const w = await layout()
        expect(panelOpen(w, 'Pricing')).toBe(false)

        await w.setProps({ errors: ['price'] })
        await flushPromises()

        expect(panelOpen(w, 'Pricing')).toBe(true)
    })
})

describe('VxResourceForm with a layout', () => {
    let unregister = () => {}
    beforeEach(() => standaloneApi())
    afterEach(() => unregister())

    it('opens the collapsed section holding the field the server refused', async () => {
        const products = resource({
            slug: 'products',
            label: 'Products',
            labelSingular: 'Product',
            fields: [field({ name: 'name', nullable: false }), field({ name: 'sku', nullable: true })],
            layout: {
                form: [f('name'), node('section', { label: 'Codes', collapsible: true, collapsed: true, children: [f('sku')] })],
                show: [],
            },
        })
        unregister = endpoint('/vector/r/products', { status: 422, body: { errors: [{ path: ['sku'], message: 'this SKU is taken.' }] } }, 'POST').unregister
        const form = await mountSuspended(VxResourceForm, { props: { resource: products, operation: 'create' } })
        await flushPromises()
        await vi.waitFor(() => expect(form.findAll('input')).toHaveLength(2))
        const section = () => form.find('[data-layout-section="Codes"] .v-expansion-panel')
        expect(section().classes()).not.toContain('v-expansion-panel--active')

        await form.find('[data-layout-field="name"] input').setValue('Mug')
        await form.find('form').trigger('submit')
        await flushPromises()

        await vi.waitFor(() => expect(section().classes()).toContain('v-expansion-panel--active'))
        expect(form.find('[data-layout-field="sku"]').text()).toContain('This SKU is taken.')
    })
})
