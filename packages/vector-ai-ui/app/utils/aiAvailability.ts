/**
 * `capabilities.ai` as GET /vector/schema publishes it for the signed-in
 * user (kinetis/vector-ai's AiShellContributor): which AI features the
 * admin offers them — each flag the very check its endpoint makes — and
 * what is kept from the model.
 */
export interface AiCapabilities {
    filters?: boolean
    assistant?: boolean
    assistantWrites?: boolean
    writing?: boolean
    /** The resources kept from the model whole, `{slug: true}`. */
    excluded?: Record<string, unknown>
    /** The fields kept back of the others, `{slug: [field, …]}`. */
    excludedFields?: Record<string, unknown>
}

/** A feature `capabilities.ai` flags. */
export type AiFeature = 'filters' | 'assistant' | 'assistantWrites' | 'writing'

/** A descriptor's `capabilities.ai`: undefined before the descriptor, and where kinetis/vector-ai is not installed. */
export function aiCapabilities(schema: { capabilities?: object } | null | undefined): AiCapabilities | undefined {
    return (schema?.capabilities as { ai?: AiCapabilities } | undefined)?.ai
}

/**
 * Whether the admin offers $feature: the server's flag for this user and,
 * when given, $resource not kept from the model and $field not one kept
 * back of it. The one check every part of the layer asks; the server
 * refuses anything else anyway.
 */
export function aiOffered(ai: AiCapabilities | undefined, feature: AiFeature, resource?: string, field?: string): boolean {
    if (ai?.[feature] !== true) return false
    if (resource === undefined) return true
    if (ai.excluded?.[resource] !== undefined) return false
    const kept = ai.excludedFields?.[resource]
    return field === undefined || !(Array.isArray(kept) && kept.includes(field))
}

/** The HTTP status of a failed API call. */
export function aiStatusOf(error: unknown): number | undefined {
    const failed = error as { status?: number, statusCode?: number, response?: { status?: number } } | null
    return failed?.status ?? failed?.statusCode ?? failed?.response?.status
}

/**
 * A failed AI call in words. A spent daily budget (429) says when it
 * renews, when Retry-After can be read — cross-origin, only when the
 * API's CORS exposes it — and otherwise what the server said, or that it
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
