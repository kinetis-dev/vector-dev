import type { VectorField } from '#vector/app/types/vector'

/** Whether a field is a relationship repeater: rows that are child records, loaded from their own endpoint. */
export function childRepeater(field: VectorField): boolean {
    return field.type === 'repeater' && field.repeater?.mode === 'relationship'
}

/**
 * Tags as the server stores them: trimmed, without empty ones, each at
 * most once ignoring case (the first spelling kept), in their order.
 */
export function normalizeTags(tags: unknown[]): string[] {
    const seen = new Set<string>()
    const list: string[] = []

    for (const tag of tags) {
        if (typeof tag !== 'string') continue
        const text = tag.trim()
        const key = text.toLowerCase()
        if (text === '' || seen.has(key)) continue
        seen.add(key)
        list.push(text)
    }

    return list
}
