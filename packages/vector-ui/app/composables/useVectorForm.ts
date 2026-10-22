import type { VectorField } from '#vector/app/types/vector'
import { violationsOf } from '#vector/app/utils/internal/violations'

/** The value a fresh input holds: the field's default, or the type's empty value. */
export function initialValue(field: VectorField): unknown {
    if (field.hasDefault) return field.default
    if (field.type === 'boolean') return false
    if (field.type === 'manyToMany') return []
    return null
}

/**
 * Maps the framework's 422 document onto field names; returns the
 * message that names no field, if any. A violation deeper in a value
 * (`tags.2`) shows on its field: the loose field lists this serves
 * (settings, bulk edit, action dialogs) hold no repeater, the one input
 * that takes a deeper key (`Vx/Resource/Form.vue` maps those).
 */
export function applyViolations(error: unknown, values: Record<string, unknown>, serverErrors: Record<string, string[]>): string | null {
    const failure = error as { status?: number, statusCode?: number, data?: { errors?: unknown } } | null
    const violations = failure?.data?.errors

    if ((failure?.status ?? failure?.statusCode) !== 422 || !Array.isArray(violations)) {
        return vectorErrorMessage(error)
    }

    let general: string | null = null

    for (const violation of violationsOf(violations)) {
        const key = String(violation.path[0] ?? '')

        if (key && key in values) {
            serverErrors[key] ??= []
            serverErrors[key].push(`${violation.message.charAt(0).toUpperCase()}${violation.message.slice(1)}`)
        } else {
            general = violation.message
        }
    }

    return general ?? (Object.keys(serverErrors).length === 0 ? vt('common.serverRejected') : null)
}
