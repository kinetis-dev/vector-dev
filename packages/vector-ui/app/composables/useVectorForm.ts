import type { VectorField } from '#vector/app/types/vector'
import { violationsOf } from '#vector/app/utils/internal/violations'

/** The value a fresh input holds: the field's default, or the type's empty value. */
export function initialValue(field: VectorField): unknown {
    if (field.type === 'booleanGroup') return flagsStart(field, true)
    if (field.hasDefault) return field.default
    if (field.type === 'boolean') return false
    if (field.type === 'manyToMany') return []
    if (field.type === 'tag' || field.type === 'repeater') return []
    return null
}

/**
 * What a flag group starts with: every option key false, with the keys a
 * declared default names over them ($withDefault), so a form with no box
 * ticked still sends every flag. A nullable group (one JSON column that
 * may hold null) without a default starts empty instead, as null.
 */
export function flagsStart(field: VectorField, withDefault: boolean): Record<string, boolean> | null {
    const declared = withDefault && field.hasDefault ? field.default : undefined

    if ((declared === undefined || declared === null) && field.nullable === true) return null

    const flags: Record<string, boolean> = Object.fromEntries((field.options ?? []).map(option => [String(option.value), false]))

    if (declared !== null && typeof declared === 'object' && !Array.isArray(declared)) {
        for (const [key, on] of Object.entries(declared)) {
            if (key in flags) flags[key] = on === true
        }
    }

    return flags
}

/**
 * Maps the framework's 422 document onto field names; returns the
 * message that names no field, if any. A violation deeper in a value
 * (`tags.2`, or a JSON repeater's `specs.1.label` in bulk edit) shows on
 * its field as a whole: only `Vx/Resource/Form.vue` maps a repeater's
 * row errors onto their row.
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
