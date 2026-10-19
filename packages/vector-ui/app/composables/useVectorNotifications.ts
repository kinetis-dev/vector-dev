export interface VectorToast {
    text: string
    color?: 'success' | 'error' | 'warning' | 'info'
    timeout?: number
}

/**
 * The queue VxNotifications renders. Shared app state, so a page, a
 * store or a fetch hook can notify from anywhere.
 */
export function useVectorNotifications() {
    const queue = useState<VectorToast[]>('vector.notifications', () => [])

    function notify(text: string, color: VectorToast['color'] = 'info', timeout?: number) {
        queue.value.push({ text, color, timeout })
    }

    return {
        queue,
        notify,
        success: (text: string) => notify(text, 'success'),
        error: (text: string) => notify(text, 'error', 6000),
    }
}
