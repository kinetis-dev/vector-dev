import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import VxActionDialog from '#vector/app/components/Vx/Action/Dialog.vue'
import { endpoint, standaloneApi } from '../endpoints'
import { resource } from '../fixtures'
import type { VectorAction } from '#vector/app/types/vector'

// What an action's answer asks the client to do. An `url` answer opens
// in a new window — only where a link could go: an action may echo a
// record's value, and a `javascript:` URL opened from the admin runs as it.

const action: VectorAction = { name: 'visit', label: 'Visit', icon: null, confirmation: null, destructive: false, standalone: false, showOnIndex: true, showOnDetail: true, fields: [] }

const cleanups: (() => void)[] = []
const mounted: VueWrapper[] = []

beforeEach(() => standaloneApi())

afterEach(() => {
    cleanups.splice(0).forEach(unregister => unregister())
    mounted.splice(0).forEach(wrapper => wrapper.unmount())
})

/** Runs the action against an answer of type `url` with $url, and reports what window.open was asked. */
async function runAnswering(url: string) {
    cleanups.push(endpoint('/vector/r/tags/actions/visit', { body: { type: 'url', message: null, url, affected: 1 } }, 'POST').unregister)
    const opened = vi.spyOn(window, 'open').mockReturnValue(null)
    const wrapper = await mountSuspended(VxActionDialog, { props: { resource: resource(), action, selection: { ids: [1] }, count: 1 } })
    mounted.push(wrapper)
    await flushPromises()

    const submit = [...document.querySelectorAll<HTMLButtonElement>('.v-overlay__content button[type="submit"]')].find(b => b.textContent?.trim() === 'Visit')
    submit!.click()
    await vi.waitFor(() => expect(wrapper.emitted('done')).toBeTruthy())

    return opened
}

describe('VxActionDialog', () => {
    it('opens the URL an action answers', async () => {
        const opened = await runAnswering('https://example.com/orders/1')

        expect(opened).toHaveBeenCalledWith('https://example.com/orders/1', '_blank', 'noopener')
    })

    it.each(['javascript:alert(document.domain)', ' JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>'])('does not open %s', async (url) => {
        const opened = await runAnswering(url)

        expect(opened).not.toHaveBeenCalled()
    })
})
