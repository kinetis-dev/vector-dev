import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import VxActionDialog from '#vector/app/components/Vx/Action/Dialog.vue'
import { endpoint, standaloneApi } from '../endpoints'
import { field, resource } from '../fixtures'
import type { VectorAction } from '#vector/app/types/vector'

// What an action's answer asks the client to do. An `url` answer opens
// in a new window; only where a link could go: an action may echo a
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
    const opened = vi.spyOn(window, 'open').mockReturnValue(null)
    await run({ type: 'url', message: null, url, affected: 1 })

    return opened
}

/** Runs the action against $answer, until the dialog is done. */
async function run(answer: Record<string, unknown>) {
    cleanups.push(endpoint('/vector/r/tags/actions/visit', { body: answer }, 'POST').unregister)
    const wrapper = await mountSuspended(VxActionDialog, { props: { resource: resource(), action, selection: { ids: [1] }, count: 1 } })
    mounted.push(wrapper)
    await flushPromises()

    const submit = [...document.querySelectorAll<HTMLButtonElement>('.v-overlay__content button[type="submit"]')].find(b => b.textContent?.trim() === 'Visit')
    submit!.click()
    await vi.waitFor(() => expect(wrapper.emitted('done')).toBeTruthy())
    await flushPromises()

    return wrapper
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

    it('goes to the path of the admin a redirect answers', async () => {
        const push = vi.spyOn(useRouter(), 'push').mockResolvedValue(undefined)

        await run({ type: 'redirect', message: null, url: '/r/tags/1', affected: 1 })

        expect(push).toHaveBeenCalledWith('/r/tags/1')
    })

    it.each(['//evil.example', '/\\evil.example', 'https://evil.example', '/\t/evil.example', null])('goes nowhere for a redirect to %s, and says so', async (url) => {
        useVectorNotifications().queue.value = []
        const push = vi.spyOn(useRouter(), 'push').mockResolvedValue(undefined)

        const wrapper = await run({ type: 'redirect', message: 'Merged.', url, affected: 2 })

        expect(push).not.toHaveBeenCalled()
        expect(wrapper.emitted('done')).toEqual([[2]])
        expect(useVectorNotifications().queue.value.map(n => n.text)).toEqual(['Merged.', 'The action asked to open a page outside the admin; it was not opened.'])
    })
})

describe('VxActionDialog: a tag field', () => {
    it('starts a tag field as an empty list and sends it as one when left alone', async () => {
        const tagged: VectorAction = { ...action, name: 'label', label: 'Label', fields: [field({ name: 'keywords', type: 'tag', nullable: false, tags: { suggestions: [], maxLength: 50, maxTags: 20 } })] }
        const sent = endpoint('/vector/r/tags/actions/label', { body: { type: 'message', message: 'Done', url: null, affected: 1 } }, 'POST')
        cleanups.push(sent.unregister)
        const wrapper = await mountSuspended(VxActionDialog, { props: { resource: resource(), action: tagged, selection: { ids: [1] }, count: 1 } })
        mounted.push(wrapper)
        await flushPromises()
        await vi.waitFor(() => expect(wrapper.findComponent({ name: 'VCombobox' }).exists()).toBe(true))

        expect(wrapper.findComponent({ name: 'VCombobox' }).props('modelValue')).toEqual([])

        const submit = [...document.querySelectorAll<HTMLButtonElement>('.v-overlay__content button[type="submit"]')].find(b => b.textContent?.trim() === 'Label')
        submit!.click()
        await vi.waitFor(() => expect(sent.requests).toHaveLength(1))

        expect((sent.requests[0]!.body as { fields: Record<string, unknown> }).fields).toEqual({ keywords: [] })
    })
})

describe('VxActionDialog: a flag group', () => {
    // a loose group is published non-nullable (even without required()): it starts and is sent as every key, off
    it('sends every flag, off, when no box is ticked', async () => {
        const options = [{ value: 'email', label: 'Email', color: null }, { value: 'sms', label: 'SMS', color: null }]
        const flagged: VectorAction = { ...action, name: 'notify', label: 'Notify', fields: [field({ name: 'channels', type: 'booleanGroup', storage: 'json', nullable: false, options })] }
        const sent = endpoint('/vector/r/tags/actions/notify', { body: { type: 'message', message: 'Done', url: null, affected: 1 } }, 'POST')
        cleanups.push(sent.unregister)
        const wrapper = await mountSuspended(VxActionDialog, { props: { resource: resource(), action: flagged, selection: { ids: [1] }, count: 1 } })
        mounted.push(wrapper)
        await flushPromises()

        const submit = [...document.querySelectorAll<HTMLButtonElement>('.v-overlay__content button[type="submit"]')].find(b => b.textContent?.trim() === 'Notify')
        submit!.click()
        await vi.waitFor(() => expect(sent.requests).toHaveLength(1))

        expect((sent.requests[0]!.body as { fields: Record<string, unknown> }).fields).toEqual({ channels: { email: false, sms: false } })
    })
})
