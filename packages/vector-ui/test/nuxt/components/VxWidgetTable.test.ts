import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import VxWidgetTable from '#vector/app/components/Vx/Widget/Table.vue'
import type { VectorWidgetData } from '#vector/app/types/vector'

const { navigate } = vi.hoisted(() => ({ navigate: vi.fn() }))
mockNuxtImport('navigateTo', () => navigate)

const data: VectorWidgetData = {
    type: 'table',
    range: '30d',
    cached: false,
    columns: [{ key: 'name', label: 'Name' }],
    rows: [{ name: 'Linked', _link: '/r/orders/1' }, { name: 'Plain' }],
}

describe('VxWidgetTable', () => {
    beforeEach(() => navigate.mockClear())

    it('opens a linked row by click, Enter or Space, and offers only that row to the keyboard', async () => {
        const wrapper = await mountSuspended(VxWidgetTable, { props: { data } })
        const [linked, plain] = wrapper.findAll('tbody tr')

        expect(linked!.attributes()).toMatchObject({ tabindex: '0', role: 'link' })
        expect(plain!.attributes('tabindex')).toBeUndefined()
        expect(plain!.attributes('role')).toBeUndefined()

        await linked!.trigger('click')
        await linked!.trigger('keydown', { key: 'Enter' })
        await linked!.trigger('keydown', { key: ' ' })
        expect(navigate.mock.calls).toEqual([['/r/orders/1'], ['/r/orders/1'], ['/r/orders/1']])

        await plain!.trigger('click')
        await plain!.trigger('keydown', { key: 'Enter' })
        expect(navigate).toHaveBeenCalledTimes(3)
    })

    it('names each column header as a column header', async () => {
        const wrapper = await mountSuspended(VxWidgetTable, { props: { data } })

        expect(wrapper.get('thead th').attributes('scope')).toBe('col')
    })
})
