import { describe, expect, it } from 'vitest'
import * as autoImports from '#imports'
import * as queues from '#vector/app/utils/queues'

describe('the queue utilities', () => {
    // Nuxt's export scan once missed the declaration after QUEUE_WINDOWS' `as const`,
    // so QUEUE_DEFAULT_WINDOW was imported by hand: every export must be auto-imported.
    it('are every one auto-imported', () => {
        const missing = Object.keys(queues).filter(name => !(name in autoImports))

        expect(missing).toEqual([])
        expect((autoImports as Record<string, unknown>).QUEUE_DEFAULT_WINDOW).toBe(60)
        expect((autoImports as Record<string, unknown>).QUEUE_WINDOWS).toEqual([15, 60, 360, 1440, 10080])
    })

    it('offer the default metrics window among the windows', () => {
        expect(queues.QUEUE_WINDOWS).toContain(queues.QUEUE_DEFAULT_WINDOW)
    })
})

describe('the failed-job date filters', () => {
    it('tell an unset or readable datetime-local value from an unreadable one', () => {
        expect(queues.queueLocalDateValid('')).toBe(true)
        expect(queues.queueLocalDateValid('2026-11-01T08:30:00')).toBe(true)
        expect(queues.queueLocalDateValid('not a date')).toBe(false)
    })

    it('send a local time as RFC 3339 in UTC, whole seconds', () => {
        // the tests run in Pacific/Honolulu, UTC-10
        expect(queues.toRfc3339('2026-11-01T08:30:00')).toBe('2026-11-01T18:30:00Z')
        expect(queues.toRfc3339('')).toBe('')
    })
})
