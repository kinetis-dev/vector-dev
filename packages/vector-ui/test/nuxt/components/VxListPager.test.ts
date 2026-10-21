import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import VxListPager from '#vector/app/components/Vx/List/Pager.vue'

const props = { perPage: 25, perPageOptions: [10, 25, 50], page: 3, hasPrevious: true, hasNext: true }

describe('VxListPager', () => {
    it('shows the page it is on and no total', async () => {
        const wrapper = await mountSuspended(VxListPager, { props })

        expect(wrapper.text()).toContain('Page 3')
        expect(wrapper.text()).not.toMatch(/\bof\b/)
    })

    it('shows no page number for a cursor list', async () => {
        const wrapper = await mountSuspended(VxListPager, { props: { ...props, page: null } })

        expect(wrapper.text()).not.toContain('Page')
    })

    it('moves by previous and next, and disables each where there is nowhere to go', async () => {
        const wrapper = await mountSuspended(VxListPager, { props })
        await wrapper.get('button[aria-label="Previous page"]').trigger('click')
        await wrapper.get('button[aria-label="Next page"]').trigger('click')
        expect(wrapper.emitted('previous')).toHaveLength(1)
        expect(wrapper.emitted('next')).toHaveLength(1)

        await wrapper.setProps({ hasPrevious: false, hasNext: false })
        expect(wrapper.get('button[aria-label="Previous page"]').attributes('disabled')).toBeDefined()
        expect(wrapper.get('button[aria-label="Next page"]').attributes('disabled')).toBeDefined()

        await wrapper.setProps({ hasPrevious: true, hasNext: true, disabled: true })
        expect(wrapper.get('button[aria-label="Next page"]').attributes('disabled')).toBeDefined()
    })
})
