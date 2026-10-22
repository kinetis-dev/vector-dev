// The entries of the framework's 422 document. Nested under utils/ so
// Nuxt does not auto-import them: they are not part of the layer's public API.

/** One segment of a violation's path: a field or key name, or a list index. */
export type ViolationSegment = string | number

/** One entry of a 422 document's `errors`, as the framework writes it (it adds a `code` and `parameters`). */
export interface Violation {
    path: ViolationSegment[]
    message: string
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
