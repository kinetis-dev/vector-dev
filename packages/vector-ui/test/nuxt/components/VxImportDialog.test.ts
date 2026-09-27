import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import VxImportDialog from '#vector/app/components/Vx/Import/Dialog.vue'
import { endpoint, standaloneApi } from '../endpoints'
import { field, resource } from '../fixtures'
import type { VectorImportMode, VectorResource } from '#vector/app/types/vector'

// The import dialog's modes: create maps only what a create writes; update
// and upsert also map the column rows are matched by, and offer as that key
// only the key or a unique field the file maps.

const products = (permissions: Partial<VectorResource['permissions']> = {}) => resource({
    slug: 'products',
    label: 'Products',
    labelSingular: 'Product',
    fields: [
        field({ name: 'id', type: 'id', label: 'ID', readonly: true, nullable: false, views: ['index', 'show'] }),
        field({ name: 'sku', label: 'SKU', unique: true }),
        field({ name: 'name' }),
        field({ name: 'stock', type: 'number', views: ['index', 'show', 'update'] }),
    ],
    permissions: { viewAny: true, create: true, update: true, delete: true, export: true, ...permissions },
})

interface DialogState {
    file: File | null
    mode: VectorImportMode
    key: string
    mapping: Record<string, string>
    modes: { value: VectorImportMode }[]
    keyChoices: { value: string }[]
    analyse: () => Promise<void>
    start: () => Promise<void>
}

const cleanups: (() => void)[] = []
const mounted: VueWrapper[] = []
const mock = (...args: Parameters<typeof endpoint>) => {
    const registered = endpoint(...args)
    cleanups.push(registered.unregister)
    return registered
}

beforeEach(() => {
    standaloneApi()
    mock('/vector/r/products/import/preview', {
        body: { headers: ['ID', 'SKU', 'Name', 'Stock'], rows: 2, sample: [{ ID: '1', SKU: 'A-1', Name: 'One', Stock: '4' }], mapping: { ID: 'id', SKU: 'sku', Name: 'name', Stock: 'stock' } },
    }, 'POST')
})

afterEach(() => {
    cleanups.splice(0).forEach(unregister => unregister())
    mounted.splice(0).forEach(wrapper => wrapper.unmount())
})

async function analysed(permissions: Partial<VectorResource['permissions']> = {}) {
    const wrapper = await mountSuspended(VxImportDialog, { props: { resource: products(permissions) } })
    mounted.push(wrapper)
    const state = wrapper.vm as unknown as DialogState
    state.file = new File(['ID,SKU,Name,Stock\r\n1,A-1,One,4\r\n'], 'products.csv', { type: 'text/csv' })
    await state.analyse()
    await flushPromises()
    return state
}

describe('VxImportDialog', () => {
    it('creates by default, skipping the columns a create does not write', async () => {
        const state = await analysed()

        expect(state.modes.map(m => m.value)).toEqual(['create', 'update', 'upsert'])
        expect(state.mode).toBe('create')
        expect(state.mapping).toEqual({ ID: '', SKU: 'sku', Name: 'name', Stock: '' })
    })

    it('updating maps the key column and offers only mapped keys and unique fields', async () => {
        const state = await analysed()
        state.mode = 'update'
        await flushPromises()

        expect(state.mapping).toEqual({ ID: 'id', SKU: 'sku', Name: 'name', Stock: 'stock' })
        expect(state.keyChoices.map(c => c.value)).toEqual(['id', 'sku'])

        state.mapping.ID = ''
        await flushPromises()
        expect(state.keyChoices.map(c => c.value)).toEqual(['sku'])
        expect(state.key).toBe('sku')
    })

    it('sends the mode and the key with the mapping', async () => {
        const queued = mock('/vector/r/products/import', { status: 202, body: { type: 'job', jobId: 7, rows: 1 } }, 'POST')
        const state = await analysed()
        state.mode = 'upsert'
        await flushPromises()
        state.key = 'sku'
        await state.start()

        expect(queued.requests[0]?.body).toEqual({
            file: 'products.csv',
            mapping: JSON.stringify({ ID: 'id', SKU: 'sku', Name: 'name', Stock: 'stock' }),
            mode: 'upsert',
            key: 'sku',
        })
    })

    it('an account that may only update is offered only updating', async () => {
        const state = await analysed({ create: false })

        expect(state.modes.map(m => m.value)).toEqual(['update'])
        expect(state.mode).toBe('update')
    })
})
