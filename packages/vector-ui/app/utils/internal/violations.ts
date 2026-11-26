// The entries of the framework's 422 document. Nested under utils/ so
// Nuxt does not auto-import them: they are not part of the layer's public API.
import { localDay } from '#vector/app/utils/internal/dateBounds'

/** One segment of a violation's path: a field or key name, or a list index. */
export type ViolationSegment = string | number

/** One entry of a 422 document's `errors`, as the framework writes it. */
export interface Violation {
    path: ViolationSegment[]
    message: string
    code?: string
    parameters?: Record<string, unknown>
}

/** Whether an `errors` entry is a violation: a list of names and indexes for `path`, and a `message`. Anything else is ignored. */
export function isViolation(entry: unknown): entry is Violation {
    const candidate = entry as { path?: unknown, message?: unknown } | null

    return typeof candidate === 'object' && candidate !== null
        && typeof candidate.message === 'string'
        && Array.isArray(candidate.path)
        && candidate.path.every(s => typeof s === 'string' || Number.isInteger(s))
}

/** The violations of a 422 document's `errors`, malformed entries left out; none when it is not a list. */
export function violationsOf(errors: unknown): Violation[] {
    return Array.isArray(errors) ? errors.filter(isViolation) : []
}

/**
 * A violation's message in the UI's language where the layer knows its
 * code, else the server's own sentence. A date bound the server names
 * (`today`, `now` or a day or an instant) is shown as the user's date.
 */
export function violationText(violation: Violation): string {
    const side = violation.code === 'date_too_early' ? 'min' : violation.code === 'date_too_late' ? 'max' : null
    const bound = side ? violation.parameters?.[side] : undefined

    if (side && typeof bound === 'string') {
        const limit = bound === 'today' ? formatDate(localDay(new Date()))
            : bound === 'now' ? formatDateTime(new Date().toISOString())
                : /^\d{4}-\d{2}-\d{2}$/.test(bound) ? formatDate(bound) : formatDateTime(bound)

        return vt(side === 'min' ? 'fields.rules.minDate' : 'fields.rules.maxDate', { limit })
    }

    return violation.message
}
