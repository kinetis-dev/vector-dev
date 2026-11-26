import { afterEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import DateRangeInput from '#vector/app/components/Vx/Input/DateRange.vue'
import DateRangeCell from '#vector/app/components/Vx/Cell/DateRange.vue'
import type { VectorField } from '#vector/app/types/vector'
import { field } from '../fixtures'

// A date range: two native controls, the filter bar's, editing {from, to}.

const mounted: VueWrapper[] = []

afterEach(() => {
    mounted.splice(0).forEach(wrapper => wrapper.unmount())
})

const promotion = field({ name: 'promotion', type: 'dateRange', label: 'Promotion', range: { kind: 'date', from: 'promotionStartsOn', to: 'promotionEndsOn' } })
const window = field({ name: 'window', type: 'dateRange', label: 'Window', range: { kind: 'datetime', from: 'startsAt', to: 'endsAt' } })

async function mountRange(input: VectorField, modelValue: { from: string | null, to: string | null } | null) {
    const wrapper = await mountSuspended(DateRangeInput, { props: { field: input, rules: [], errorMessages: [], modelValue } })
    mounted.push(wrapper)
    await flushPromises()
    return wrapper
}

describe('VxInputDateRange', () => {
    it('edits a range of days in two date controls, the end starting no earlier than the start', async () => {
        const wrapper = await mountRange(promotion, { from: '2026-12-01', to: null })
        const [from, to] = wrapper.findAll('input')

        expect(wrapper.find('legend').text()).toBe('Promotion')
        expect([from!.attributes('type'), to!.attributes('type')]).toEqual(['date', 'date'])
        expect(from!.element.value).toBe('2026-12-01')
        expect(to!.attributes('min')).toBe('2026-12-01')

        await to!.setValue('2026-12-24')
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([{ from: '2026-12-01', to: '2026-12-24' }])
    })

    it('edits a range of instants in local time and sends them in UTC', async () => {
        const wrapper = await mountRange(window, { from: null, to: null })
        const [from] = wrapper.findAll('input')

        expect(from!.attributes('type')).toBe('datetime-local')
        await from!.setValue('2026-12-01T09:30')
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([{ from: new Date('2026-12-01T09:30').toISOString(), to: null }])
    })

    it('starts from null as both ends empty', async () => {
        const wrapper = await mountRange(promotion, null)

        expect(wrapper.findAll('input').map(i => i.element.value)).toEqual(['', ''])
        await wrapper.findAll('input')[0]!.setValue('2026-01-05')
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([{ from: '2026-01-05', to: null }])
    })
})

describe('VxCellDateRange', () => {
    it('shows "from to to" in the UI\'s language, and nothing for no range', async () => {
        const cell = await mountSuspended(DateRangeCell, { props: { field: promotion, value: { from: '2026-12-01', to: '2026-12-24' } } })
        mounted.push(cell)
        expect(cell.text()).toBe(`${formatDate('2026-12-01')} to ${formatDate('2026-12-24')}`)

        const none = await mountSuspended(DateRangeCell, { props: { field: promotion, value: null } })
        mounted.push(none)
        expect(none.find('.text-disabled').exists()).toBe(true)
    })
})
