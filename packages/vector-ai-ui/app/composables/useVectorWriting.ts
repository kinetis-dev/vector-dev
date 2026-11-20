/** What writing help does to a text. */
export type WritingOperation = 'rewrite' | 'shorten' | 'grammar' | 'translate'

/** What POST /vector/ai/writing/{resource}/{field} answers. */
export interface WritingAnswer {
    text: string
    operation: WritingOperation
    model: string
    /** The answer was longer than the field allows, and was cut. */
    truncated: boolean
}

export interface WritingRequest {
    operation: WritingOperation
    text: string
    format: 'plain' | 'markdown'
    targetLocale?: string | null
    /** The record being edited; null on the create form. */
    recordId?: string | null
}

interface AiCapabilities {
    writing?: boolean
    excluded?: Record<string, unknown>
    excludedFields?: Record<string, unknown>
}

/** The languages "Translate to…" offers, as BCP 47 tags. */
export const WRITING_LANGUAGES = ['en', 'de', 'fr', 'es', 'it', 'nl', 'pt', 'pl', 'sv', 'ja', 'zh'] as const

/**
 * Writing help for one field: whether the admin offers it there, and the
 * call. Offered when `capabilities.ai.writing` is on, the resource is not
 * kept from AI (`excluded`) and the field is not one of those kept back
 * (`excludedFields`) — the server refuses all three anyway. The answer is
 * only ever put in the input, for the person to review and save.
 */
export function useVectorWriting() {
    const { schema } = useVectorSchema()
    const api = useVectorApi()
    const { t } = useVectorT()
    const language = useVectorLocale()

    function offered(resource: string | undefined, field: string): boolean {
        const ai = schema.value?.capabilities?.ai as AiCapabilities | undefined
        if (ai?.writing !== true || resource === undefined || resource === '') return false
        if (ai.excluded?.[resource] !== undefined) return false
        const kept = ai.excludedFields?.[resource]
        return !(Array.isArray(kept) && kept.includes(field))
    }

    function write(resource: string, field: string, request: WritingRequest): Promise<WritingAnswer> {
        return api<WritingAnswer>(`/vector/ai/writing/${encodeURIComponent(resource)}/${encodeURIComponent(field)}`, {
            method: 'POST',
            body: { targetLocale: null, recordId: null, ...request },
        })
    }

    /** A failure in words: a spent budget says when it renews, when Retry-After can be read; otherwise the server's reason. */
    function describe(error: unknown): string {
        const failed = error as { status?: number, statusCode?: number, response?: { status?: number, headers?: Headers }, data?: { error?: unknown } } | null
        const status = failed?.status ?? failed?.statusCode ?? failed?.response?.status

        if (status === 429) {
            const seconds = Number(failed?.response?.headers?.get('Retry-After') ?? Number.NaN)
            if (Number.isFinite(seconds) && seconds > 0) {
                const at = new Date(Date.now() + seconds * 1000)
                return t('ai.filters.budget', { time: at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) })
            }
        }

        const said = failed?.data?.error
        if (typeof said === 'string' && said !== '') return said
        return status === 429 ? t('ai.filters.budgetSpent') : t('ai.writing.failed')
    }

    /** A language's name in the admin's own language, falling back to its tag. */
    function languageName(tag: string): string {
        try {
            return new Intl.DisplayNames([language.current.value, 'en'], { type: 'language' }).of(tag) ?? tag
        } catch {
            return tag
        }
    }

    return { offered, write, describe, languageName }
}
