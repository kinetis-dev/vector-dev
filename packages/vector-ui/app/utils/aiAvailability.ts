import type { AiCapabilities } from '../types/vector'

/** A feature `capabilities.ai` flags. */
export type AiFeature = 'filters' | 'assistant' | 'assistantWrites' | 'writing'

/** A descriptor's `capabilities.ai`: undefined before the descriptor, and where kinetis/vector-ai is not installed. */
export function aiCapabilities(schema: { capabilities?: { ai?: AiCapabilities, [key: string]: unknown } } | null | undefined): AiCapabilities | undefined {
    return schema?.capabilities?.ai
}

/**
 * Whether the admin offers $feature: the server's flag for this user and,
 * when given, $resource not kept from the model and $field not one kept
 * back of it, nor, for writing help, one of `noWriting`. The one check
 * every AI part asks; the server refuses anything else anyway.
 */
export function aiOffered(ai: AiCapabilities | undefined, feature: AiFeature, resource?: string, field?: string): boolean {
    if (ai?.[feature] !== true) return false
    if (resource === undefined) return true
    if (ai.excluded?.[resource] !== undefined) return false
    if (field === undefined) return true
    const kept = ai.excludedFields?.[resource]
    if (Array.isArray(kept) && kept.includes(field)) return false
    const off = feature === 'writing' ? ai.noWriting?.[resource] : undefined
    return !(Array.isArray(off) && off.includes(field))
}

/** The HTTP status of a failed API call. */
export function aiStatusOf(error: unknown): number | undefined {
    const failed = error as { status?: number, statusCode?: number, response?: { status?: number } } | null
    return failed?.status ?? failed?.statusCode ?? failed?.response?.status
}

/**
 * A failed AI call in words. A spent daily budget (429) says when it
 * renews, when Retry-After can be read (cross-origin, only when the
 * API's CORS exposes it) and otherwise what the server said, or that it
 * is spent. Any other failure is what the server said ($data's `error`,
 * the response body by default), or $fallback.
 */
export function aiFailureMessage(error: unknown, fallback: string, data: unknown = (error as { data?: unknown } | null)?.data): string {
    const status = aiStatusOf(error)

    if (status === 429) {
        const seconds = Number((error as { response?: { headers?: Headers } } | null)?.response?.headers?.get('Retry-After') ?? Number.NaN)
        if (Number.isFinite(seconds) && seconds > 0) {
            const at = new Date(Date.now() + seconds * 1000)
            return vt('ai.filters.budget', { time: at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) })
        }
    }

    const said = data !== null && typeof data === 'object' ? (data as { error?: unknown }).error : undefined
    if (typeof said === 'string' && said !== '') return said
    return status === 429 ? vt('ai.filters.budgetSpent') : fallback
}
