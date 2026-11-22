import { bundledMessages } from '#vector/app/i18n'

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


/**
 * Writing help for one field: whether the admin offers it there, and the
 * call. Offered when `capabilities.ai.writing` is on, the resource is not
 * kept from AI (`excluded`), the field is not one of those kept back
 * (`excludedFields`) and not one writing help is off for (`noWriting`: a
 * unique string, or `ai.writing` false); the server refuses all four
 * anyway. The answer is
 * only ever put in the input, for the person to review and save.
 */
export function useVectorWriting() {
    const { schema } = useVectorSchema()
    const api = useVectorApi()
    const { t } = useVectorT()
    const language = useVectorLocale()

    function offered(resource: string | undefined, field: string): boolean {
        return resource !== undefined && resource !== '' && aiOffered(aiCapabilities(schema.value), 'writing', resource, field)
    }

    function write(resource: string, field: string, request: WritingRequest): Promise<WritingAnswer> {
        return api<WritingAnswer>(`/vector/ai/writing/${encodeURIComponent(resource)}/${encodeURIComponent(field)}`, {
            method: 'POST',
            body: { targetLocale: null, recordId: null, ...request },
        })
    }

    /** A failure in words: a spent budget says when it renews, when Retry-After can be read; otherwise the server's reason. */
    function describe(error: unknown): string {
        return aiFailureMessage(error, t('ai.writing.failed'))
    }

    /** A language's name in the admin's own language, falling back to its tag. */
    function languageName(tag: string): string {
        try {
            return new Intl.DisplayNames([language.current.value, 'en'], { type: 'language' }).of(tag) ?? tag
        } catch {
            return tag
        }
    }

    /**
     * The languages "Translate to…" offers, as BCP 47 tags: every language
     * the UI ships, then any other the descriptor lets this user choose.
     */
    const languages = computed(() => [...new Set([...Object.keys(bundledMessages), ...language.locales.value.map(l => l.code)])])

    return { offered, write, describe, languageName, languages }
}
