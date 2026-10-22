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

    it('opens no link outside the admin', async () => {
        const links = ['//evil.example', '/\\evil.example', 'https://evil.example', 'javascript:alert(1)', '/\t/evil.example']
        const wrapper = await mountSuspended(VxWidgetTable, { props: { data: { ...data, rows: links.map(_link => ({ name: 'Out', _link })) } } })

        for (const row of wrapper.findAll('tbody tr')) {
            expect(row.attributes('role')).toBeUndefined()
            await row.trigger('click')
            await row.trigger('keydown', { key: 'Enter' })
        }

        expect(navigate).not.toHaveBeenCalled()
    })

    it('says so when opening a row fails', async () => {
        const unhandled: unknown[] = []
        const onNode = (reason: unknown) => unhandled.push(reason)
        process.on('unhandledRejection', onNode)
        useVectorNotifications().queue.value = []
        navigate.mockRejectedValueOnce(new Error('Navigation aborted.'))
        const wrapper = await mountSuspended(VxWidgetTable, { props: { data } })

        await wrapper.findAll('tbody tr')[0]!.trigger('click')
        await new Promise(resolve => setTimeout(resolve, 20))
        process.off('unhandledRejection', onNode)

        expect(unhandled).toEqual([])
        expect(useVectorNotifications().queue.value.map(n => n.text)).toContain('Navigation aborted.')
    })

    it('names each column header as a column header', async () => {
        const wrapper = await mountSuspended(VxWidgetTable, { props: { data } })

        expect(wrapper.get('thead th').attributes('scope')).toBe('col')
    })
})
