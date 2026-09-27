import { afterEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import VxConfirm from '#vector/app/components/Vx/Confirm.vue'

// The dialog teleports to the document body, so the tests look there.
const dialog = () => document.querySelector<HTMLElement>('.v-overlay__content .v-card')
const button = (label: string) => [...document.querySelectorAll<HTMLButtonElement>('.v-overlay__content button')].find(b => b.textContent?.trim() === label)

afterEach(() => useConfirm().settle(false))

describe('VxConfirm with useConfirm', () => {
    it('shows what is asked and resolves true on confirm', async () => {
        await mountSuspended(VxConfirm)
        const answer = useConfirm().confirm({ title: 'Delete tag “Sale”?', text: 'This cannot be undone.', confirmText: 'Delete', destructive: true })
        await flushPromises()

        expect(dialog()?.textContent).toContain('Delete tag “Sale”?')
        expect(dialog()?.textContent).toContain('This cannot be undone.')
        // the answer that destroys is the error colour, whatever variant the app's defaults give buttons
        expect(button('Delete')?.className).toMatch(/\b(bg|text)-error\b/)
        expect(button('Cancel')?.className).not.toMatch(/-error\b/)

        button('Delete')!.click()

        await expect(answer).resolves.toBe(true)
        expect(useConfirm().pending.value).toBeNull()
    })

    it('resolves false on cancel, with the default labels', async () => {
        await mountSuspended(VxConfirm)
        const answer = useConfirm().confirm({ title: 'Revert this change?' })
        await flushPromises()

        expect(button('Confirm')).toBeDefined()
        button('Cancel')!.click()

        await expect(answer).resolves.toBe(false)
    })

    it('answers an earlier question false when a new one is asked', async () => {
        const first = useConfirm().confirm({ title: 'First?' })
        const second = useConfirm().confirm({ title: 'Second?' })

        await expect(first).resolves.toBe(false)
        expect(useConfirm().pending.value?.title).toBe('Second?')

        useConfirm().settle(true)
        await expect(second).resolves.toBe(true)
    })
})
