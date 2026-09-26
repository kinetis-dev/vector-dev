import type { VectorNotification } from '#vector/app/types/vector'

/** The inbox and its unread count, polled while the admin is open. */
export function useVectorNotificationsInbox() {
    const api = useVectorApi()
    const unread = useState<number>('vector.inbox.unread', () => 0)
    const items = useState<VectorNotification[]>('vector.inbox.items', () => [])

    async function refresh(): Promise<void> {
        const inbox = await api<{ data: VectorNotification[], unread: number }>('/vector/notifications')
        items.value = inbox.data
        unread.value = inbox.unread
    }

    async function markRead(id: number): Promise<void> {
        await api(`/vector/notifications/${id}/read`, { method: 'POST' })
        await refresh()
    }

    async function markAllRead(): Promise<void> {
        await api('/vector/notifications/read-all', { method: 'POST' })
        await refresh()
    }

    return { unread, items, refresh, markRead, markAllRead }
}
