import { useEventListener, useIdle } from '@vueuse/core'

/**
 * Ends the session when nobody has used the admin for a while. The
 * session otherwise never ends on its own: the store renews the token
 * before it runs out and the shell polls badges and the inbox, so an open
 * tab stays signed in for as long as it is open. This watches for a real
 * person — a pointer, a key, a touch, a scroll — and, once app config
 * `vector.idleMinutes` have passed without one, calls `onIdle` exactly
 * once. A tab that comes back into view after sleeping is checked at
 * that moment too, since a timer may not have fired meanwhile.
 * `idleMinutes: 0` turns it off. Call it from the signed-in layout.
 */
export function useVectorIdle(onIdle: () => void | Promise<void>): void {
    const minutes = useAppConfig().vector.idleMinutes

    if (!import.meta.client || minutes <= 0 || Number.isNaN(minutes)) return

    const timeout = minutes * 60_000
    const { idle, lastActive, reset } = useIdle(timeout, { listenForVisibilityChange: false })
    let fired = false

    const fire = () => {
        if (fired) return
        fired = true
        void onIdle()
    }

    // synchronous: the session ends the moment the wait is over, not on a render tick
    watch(idle, (now) => {
        if (now) fire()
    }, { flush: 'sync' })

    useEventListener(document, 'visibilitychange', () => {
        if (document.visibilityState !== 'visible') return
        if (Date.now() - lastActive.value >= timeout) fire()
        else reset()
    })
}
