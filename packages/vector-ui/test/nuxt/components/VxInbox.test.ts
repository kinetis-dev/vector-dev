import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import VxInbox from '#vector/app/components/Vx/Inbox.vue'
import { endpoint, standaloneApi } from '../endpoints'

// Opening a notification marks it read and follows its link; when either
// fails, the user is told, and nothing rejects unhandled.

const cleanups: (() => void)[] = []
const mounted: VueWrapper[] = []
const mock = (...args: Parameters<typeof endpoint>) => {
    const registered = endpoint(...args)
    cleanups.push(registered.unregister)
    return registered
}

const unhandled: unknown[] = []
const onBrowser = (event: PromiseRejectionEvent) => unhandled.push(event.reason)
const onNode = (reason: unknown) => unhandled.push(reason)

beforeEach(() => {
    standaloneApi()
    useVectorAuthStore().adopt('token-123', 3600, { id: 1, name: 'Vector Admin' })
    useVectorNotifications().queue.value = []
    useVectorNotificationsInbox().items.value = [] // shared state: no earlier test's notification
    unhandled.splice(0)
    window.addEventListener('unhandledrejection', onBrowser)
    process.on('unhandledRejection', onNode)
})
afterEach(() => {
    window.removeEventListener('unhandledrejection', onBrowser)
    process.off('unhandledRejection', onNode)
    cleanups.splice(0).forEach(unregister => unregister())
    mounted.splice(0).forEach(wrapper => wrapper.unmount())
    document.querySelectorAll('.v-overlay-container').forEach(container => container.replaceChildren())
    useVectorAuthStore().clear()
})

async function openFirst(link: string | null) {
    mock('/vector/notifications', { body: { data: [{ id: 5, kind: 'info', title: 'Export ready', body: null, link, readAt: null, createdAt: '2026-10-21T09:00:00Z' }], unread: 1 } })
    const wrapper = await mountSuspended(VxInbox)
    mounted.push(wrapper)
    await flushPromises()
    await wrapper.find('button').trigger('click')
    await vi.waitFor(() => expect(document.body.textContent).toContain('Export ready'))
    // the menu's content is teleported to the body: this mount's is the last one there
    const item = [...document.querySelectorAll<HTMLElement>('.v-list-item')].filter(el => el.textContent?.includes('Export ready')).at(-1)!
    item.click()
    await flushPromises()
    await new Promise(resolve => setTimeout(resolve, 50))
}

describe('VxInbox', () => {
    it('says so when marking a notification read fails', async () => {
        mock('/vector/notifications/5/read', { status: 500, body: { error: 'Store down.' } }, 'POST')

        await openFirst('/r/products')

        expect(unhandled).toEqual([])
        expect(useVectorNotifications().queue.value.map(t => t.text)).toContain('Store down.')
    })

    it('follows no link outside the admin', async () => {
        const read = mock('/vector/notifications/5/read', { body: {} }, 'POST')
        const push = vi.spyOn(useRouter(), 'push')

        for (const link of ['//evil.example/phish', '/\\evil.example', 'https://evil.example', 'javascript:alert(1)']) {
            await openFirst(link)
            mounted.splice(0).forEach(wrapper => wrapper.unmount())
        }

        expect(read.requests).toHaveLength(4)
        expect(push).not.toHaveBeenCalled()
        expect(unhandled).toEqual([])
        expect(useVectorNotifications().queue.value).toEqual([])
    })

    it('follows a link of the admin', async () => {
        mock('/vector/notifications/5/read', { body: {} }, 'POST')
        mock('/vector/descriptor', { status: 500, body: { error: 'not under test' } })
        const push = vi.spyOn(useRouter(), 'push').mockResolvedValue(undefined)

        await openFirst('/r/products/7')

        expect(push).toHaveBeenCalledWith('/r/products/7')
    })
})
