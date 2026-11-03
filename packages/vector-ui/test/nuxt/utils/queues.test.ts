import { describe, expect, it } from 'vitest'
import * as queues from '#vector/app/utils/queues'

describe('the queue utilities', () => {
    // Nuxt's export scan once missed the declaration after QUEUE_WINDOWS' `as const`,
    // so QUEUE_DEFAULT_WINDOW was imported by hand. Each export is named below as a
    // bare identifier — the auto-import, with no import of its own — so one the scan
    // misses is a ReferenceError here, and each must be the module's own export.
    it('are every one auto-imported', () => {
        const bare: Record<string, unknown> = {
            QUEUE_MAX_RETRY_BATCH,
            QUEUE_MAX_PRUNE_DAYS,
            QUEUE_METRICS_MAX_MINUTES,
            QUEUE_POLL_MS,
            QUEUE_DEFAULT_WINDOW,
            QUEUE_SPARK_POINTS,
            queueErrorStatus,
            describeQueueError,
            queueAccessRefused,
            compactQuery,
            toRfc3339,
            queueLocalDateValid,
            queueNumber,
            queueMs,
            queueRate,
            queueTime,
            queueAttempts,
            queueRetrySummary,
            queuePruneDays,
            queueMetricsTotals,
            queueSparkline,
            QUEUE_WINDOWS,
        }

        // the list above names every export: a new one must be added to it
        expect(Object.keys(bare).sort()).toEqual(Object.keys(queues).sort())
        for (const [name, value] of Object.entries(bare)) {
            if (typeof value === 'function') expect(value.name, name).toBe(name)
            else expect(value, name).toEqual((queues as Record<string, unknown>)[name])
        }
        expect(QUEUE_DEFAULT_WINDOW).toBe(60)
        expect(QUEUE_WINDOWS).toEqual([15, 60, 360, 1440, 10080])
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
