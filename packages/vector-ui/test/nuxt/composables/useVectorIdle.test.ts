import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'

const MINUTE = 60_000

describe('useVectorIdle', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        useAppConfig().vector.idleMinutes = 30
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('signs out once after the configured minutes without a person, and not while there is one', async () => {
        const onIdle = vi.fn()
        const scope = effectScope()
        scope.run(() => useVectorIdle(onIdle))

        await vi.advanceTimersByTimeAsync(20 * MINUTE)
        window.dispatchEvent(new Event('mousemove')) // someone is there
        await vi.advanceTimersByTimeAsync(20 * MINUTE)
        expect(onIdle).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(11 * MINUTE)
        expect(onIdle).toHaveBeenCalledTimes(1)

        window.dispatchEvent(new Event('keydown'))
        await vi.advanceTimersByTimeAsync(31 * MINUTE)
        expect(onIdle).toHaveBeenCalledTimes(1) // ended once; the login page takes over

        scope.stop()
    })

    it('checks when a tab comes back into view, in case no timer fired meanwhile', () => {
        const onIdle = vi.fn()
        const scope = effectScope()
        scope.run(() => useVectorIdle(onIdle))
        const visibility = vi.spyOn(document, 'visibilityState', 'get')

        visibility.mockReturnValue('hidden')
        document.dispatchEvent(new Event('visibilitychange'))
        vi.setSystemTime(Date.now() + 31 * MINUTE) // asleep: the clock moved, no timer ran
        visibility.mockReturnValue('visible')
        document.dispatchEvent(new Event('visibilitychange'))

        expect(onIdle).toHaveBeenCalledTimes(1)
        visibility.mockRestore()
        scope.stop()
    })

    it('does nothing when switched off', () => {
        useAppConfig().vector.idleMinutes = 0
        const onIdle = vi.fn()
        const scope = effectScope()
        scope.run(() => useVectorIdle(onIdle))

        vi.advanceTimersByTime(24 * 60 * MINUTE)
        expect(onIdle).not.toHaveBeenCalled()

        scope.stop()
    })
})
