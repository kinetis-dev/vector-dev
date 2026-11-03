import type { VectorQueueMetrics, VectorRetryResult } from '#vector/app/types/vector'

/**
 * The server's own limits, kept in step with kinetis/vector:
 * QueuesController::MAX_RETRY_BATCH and its prune age (MAX_PRUNE_DAYS),
 * and MetricsQuery::MAX_MINUTES, the longest window (the last of
 * QUEUE_WINDOWS).
 */
export const QUEUE_MAX_RETRY_BATCH = 50
export const QUEUE_MAX_PRUNE_DAYS = 36500
export const QUEUE_METRICS_MAX_MINUTES = 7 * 24 * 60

/** How often the overview asks again while it is shown. */
export const QUEUE_POLL_MS = 5000

/** The metrics window shown first, in minutes: the last hour, one of QUEUE_WINDOWS (at the end of this file). */
export const QUEUE_DEFAULT_WINDOW = 60

/** The most points the throughput sparkline draws: a longer window sums neighbouring minutes. */
export const QUEUE_SPARK_POINTS = 240

/** The HTTP status of an API client error, whichever field the client put it in. */
export function queueErrorStatus(error: unknown): number | null {
    const failure = error as { status?: number, statusCode?: number, response?: { status?: number } } | null
    return failure?.status ?? failure?.statusCode ?? failure?.response?.status ?? null
}

/** A notice for a failed call: 403, 404, 409 and 422 in words of their own, anything else with the server's reason. */
export function describeQueueError(error: unknown): string {
    switch (queueErrorStatus(error)) {
        case 403: return vt('queues.errors.forbidden')
        case 404: return vt('queues.errors.notFound')
        case 409: return vt('queues.errors.conflict', { detail: vectorErrorMessage(error) })
        case 422: return vt('queues.errors.invalid', { detail: vectorErrorMessage(error) })
        default: return vt('queues.errors.failed', { detail: vectorErrorMessage(error) })
    }
}

/** Whether a page-level read was refused because the dashboard is not there for this user: 403, or the 404 the gate answers. */
export function queueAccessRefused(error: unknown): boolean {
    const status = queueErrorStatus(error)
    return status === 403 || status === 404
}

/** Drops the empty filters, so an unset one is absent rather than an empty string the server refuses. */
export function compactQuery<T extends Record<string, string | number | null | undefined>>(query: T): Record<string, string | number> {
    return Object.fromEntries(Object.entries(query).filter(([, value]) => value !== '' && value !== null && value !== undefined)) as Record<string, string | number>
}

/**
 * A `datetime-local` value in the browser's zone as RFC 3339 in whole seconds, UTC; '' when unset or invalid.
 * An invalid one must not reach it unnoticed — '' drops the filter — so a form checks queueLocalDateValid() first.
 */
export function toRfc3339(local: string): string {
    if (!local) return ''
    const date = new Date(local)
    return Number.isNaN(date.getTime()) ? '' : date.toISOString().replace(/\.\d{3}Z$/, 'Z')
}

/** Whether a `datetime-local` value is unset or a date toRfc3339() can read. */
export function queueLocalDateValid(local: string): boolean {
    return !local || !Number.isNaN(new Date(local).getTime())
}

/** A count, or the dashboard's dash for none. */
export function queueNumber(value: number | null | undefined): string {
    return typeof value === 'number' ? formatNumber(value) : vt('queues.none')
}

/** Milliseconds, to at most one decimal. */
export function queueMs(value: number | null | undefined): string {
    return typeof value === 'number' ? formatNumber(Math.round(value * 10) / 10) : vt('queues.none')
}

/** A share of 0 to 1 as a percentage with one decimal. */
export function queueRate(value: number | null | undefined): string {
    return typeof value === 'number' ? vt('queues.percent', { value: (value * 100).toFixed(1) }) : vt('queues.none')
}

/** An RFC 3339 time, or unix seconds, in the UI's language. */
export function queueTime(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return vt('queues.none')
    return formatDateTime(typeof value === 'number' ? new Date(value * 1000).toISOString() : value)
}

/** A failed job's attempts: "3 of 5", or the count alone when it had no limit. */
export function queueAttempts(job: { attempts: number, maxAttempts: number | null }): string {
    return job.maxAttempts === null ? queueNumber(job.attempts) : vt('queues.failed.attemptsOf', { attempts: job.attempts, max: job.maxAttempts })
}

/** A retry's outcome in one line, and whether every job was retried. */
export function queueRetrySummary(results: VectorRetryResult[]): { text: string, ok: boolean } {
    const count = (status: VectorRetryResult['status']) => results.filter(result => result.status === status).length
    return {
        text: vt('queues.failed.retried', { retried: count('retried'), notFound: count('not_found'), failed: count('failed') }),
        ok: count('retried') === results.length,
    }
}

/** The days a prune field holds, as a whole number from 1 to the server's limit; null for anything else. */
export function queuePruneDays(value: string | number | null | undefined): number | null {
    const text = String(value ?? '').trim()
    if (!/^[1-9]\d*$/.test(text)) return null
    const days = Number(text)
    return days > QUEUE_MAX_PRUNE_DAYS ? null : days
}

/** The window's totals across every queue: failure rate over finished attempts, average over all of them. */
export function queueMetricsTotals(metrics: VectorQueueMetrics | null) {
    const sum = { succeeded: 0, released: 0, failed: 0, max: null as number | null, duration: 0 }
    for (const row of metrics?.queues ?? []) {
        sum.succeeded += row.succeeded
        sum.released += row.released
        sum.failed += row.failed
        sum.duration += row.averageDurationMs * (row.succeeded + row.released + row.failed)
        sum.max = Math.max(sum.max ?? 0, row.maxDurationMs)
    }
    const attempts = sum.succeeded + sum.released + sum.failed
    const finished = sum.succeeded + sum.failed
    return {
        succeeded: sum.succeeded,
        released: sum.released,
        failed: sum.failed,
        maxDurationMs: sum.max,
        failureRate: finished === 0 ? null : sum.failed / finished,
        averageDurationMs: attempts === 0 ? null : sum.duration / attempts,
    }
}

/**
 * The sparkline of attempts per minute: the buckets summed into at most
 * QUEUE_SPARK_POINTS points, as an SVG polyline in a 40-unit-high box.
 */
export function queueSparkline(buckets: VectorQueueMetrics['buckets']): { width: number, points: string } {
    const totals = buckets.map(bucket => bucket.succeeded + bucket.released + bucket.failed)
    const size = Math.max(1, Math.ceil(totals.length / QUEUE_SPARK_POINTS))
    const sums: number[] = []
    for (let index = 0; index < totals.length; index += size) {
        sums.push(totals.slice(index, index + size).reduce((sum, value) => sum + value, 0))
    }
    const peak = Math.max(1, ...sums)
    return {
        width: Math.max(1, sums.length - 1),
        points: sums.map((value, index) => `${index},${(40 - (value / peak) * 38 - 1).toFixed(2)}`).join(' '),
    }
}

/**
 * The metrics windows offered, in minutes, QUEUE_DEFAULT_WINDOW among them. Kept the
 * last declaration in this file: Nuxt's export scan misses a declaration that
 * follows an `as const`, so nothing may come after it.
 */
export const QUEUE_WINDOWS = [15, 60, 360, 1440, QUEUE_METRICS_MAX_MINUTES] as const
