import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import VxInputRelation from '#vector/app/components/Vx/Input/Relation.vue'
import { endpoint, standaloneApi } from '../endpoints'
import { field } from '../fixtures'

// A relation's choices tell records apart: the option's subtitle shows
// under or beside its label, and its thumbnail comes through the target's
// authenticated file endpoint.

// the environment's fetch answers a Node Blob, which happy-dom's FileReader refuses
mockNuxtImport('dataUrl', () => async () => 'data:image/png;base64,iVBORw0KGgo=')

const product = field({ name: 'product', type: 'belongsTo', label: 'Product', relation: { kind: 'belongsTo', resource: 'products', titleField: 'name' } })

const cleanups: (() => void)[] = []
const mounted: VueWrapper[] = []
const mock = (...args: Parameters<typeof endpoint>) => {
    const registered = endpoint(...args)
    cleanups.push(registered.unregister)
    return registered
}

beforeEach(() => standaloneApi())
afterEach(() => {
    cleanups.splice(0).forEach(unregister => unregister())
    mounted.splice(0).forEach(wrapper => wrapper.unmount())
})

async function mountRelation(modelValue: number | null) {
    const wrapper = await mountSuspended(VxInputRelation, { props: { field: product, rules: [], errorMessages: [], resource: 'order-lines', modelValue } })
    mounted.push(wrapper)
    await flushPromises()
    return wrapper
}

describe('VxInputRelation', () => {
    it('shows the chosen record with its subtitle and thumbnail', async () => {
        mock('/vector/r/order-lines/fields/product/options', { body: { data: [{ id: 7, label: 'Desk lamp', subtitle: 'SKU-00007', image: 'imageKey' }] } })
        const file = mock('/vector/r/products/7/files/imageKey', { body: 'png-bytes' })

        const wrapper = await mountRelation(7)

        await vi.waitFor(() => expect(wrapper.find('.v-autocomplete__selection .v-avatar').exists()).toBe(true))
        expect(wrapper.text()).toContain('Desk lamp')
        expect(wrapper.find('.vx-relation-subtitle').text()).toBe('SKU-00007')
        expect(file.requests).toHaveLength(1)
        expect(wrapper.findComponent({ name: 'VImg' }).props('src')).toBe('data:image/png;base64,iVBORw0KGgo=')
    })

    it('fetches no thumbnail for an option without an image, and shows no subtitle it was not given', async () => {
        mock('/vector/r/order-lines/fields/product/options', { body: { data: [{ id: 8, label: 'Plain chair' }] } })
        const file = mock('/vector/r/products/8/files/imageKey', { body: 'png-bytes' })

        const wrapper = await mountRelation(8)

        await vi.waitFor(() => expect(wrapper.text()).toContain('Plain chair'))
        expect(wrapper.find('.vx-relation-subtitle').exists()).toBe(false)
        expect(wrapper.find('.v-avatar').exists()).toBe(false)
        expect(file.requests).toHaveLength(0)
    })
})
