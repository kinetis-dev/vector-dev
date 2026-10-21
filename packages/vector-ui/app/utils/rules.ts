import type { VectorCondition, VectorField, VectorFieldState, VectorRule } from '#vector/app/types/vector'
import { isEmailAddress, phpRegex } from '#vector/app/utils/internal/patterns'

type Rule = (value: unknown) => true | string

const empty = (value: unknown) => value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)

/**
 * The descriptor's rules, compiled to Vuetify rule functions. Each
 * mirrors the framework constraint the server runs, by its class's
 * short name; a constraint with no client equivalent is left to the
 * server, whose 422 lands on the field as `error-messages`.
 */
export function rulesFor(field: VectorField, operation: 'create' | 'update', state?: VectorFieldState): Rule[] {
    const rules: Rule[] = []
    const requiredHere = (operation === 'create' && field.nullable === false && !['manyToMany', 'hasMany', 'hasOne', 'boolean'].includes(field.type)) || state?.required === true

    if (requiredHere) {
        rules.push(value => !empty(value) || vt('common.required'))
    }

    for (const rule of field.rules) {
        const compiled = compile(rule)

        if (compiled) {
            rules.push(compiled)
        }
    }

    return rules
}

function compile(rule: VectorRule): Rule | null {
    const name = rule.constraint.split('\\').pop()
    const [a, b] = rule.args as [unknown, unknown]

    switch (name) {
        case 'NotBlank':
            return value => (typeof value === 'string' ? value.trim() !== '' : !empty(value)) || vt('common.required')
        case 'MaxLength':
            return value => empty(value) || typeof value !== 'string' || [...value].length <= Number(a) || vt('fields.rules.maxLength', { max: String(a) })
        case 'MinLength':
            return value => empty(value) || typeof value !== 'string' || [...value].length >= Number(a) || vt('fields.rules.minLength', { min: String(a) })
        case 'Email':
            return value => empty(value) || (typeof value === 'string' && isEmailAddress(value)) || vt('fields.rules.email')
        case 'Url':
        case 'WebUrl':
            return value => empty(value) || (typeof value === 'string' && /^https?:\/\/\S+$/i.test(value)) || vt('fields.rules.url')
        case 'GreaterThan':
            return value => empty(value) || Number(value) > Number(a) || vt('fields.rules.greaterThan', { limit: String(a) })
        case 'GreaterThanOrEqual':
            return value => empty(value) || Number(value) >= Number(a) || vt('fields.rules.greaterThanOrEqual', { limit: String(a) })
        case 'LessThan':
            return value => empty(value) || Number(value) < Number(a) || vt('fields.rules.lessThan', { limit: String(a) })
        case 'LessThanOrEqual':
            return value => empty(value) || Number(value) <= Number(a) || vt('fields.rules.lessThanOrEqual', { limit: String(a) })
        case 'Regex':
            return value => empty(value) || typeof value !== 'string' || phpRegex(String(a)).test(value) || (typeof b === 'string' ? b : vt('fields.rules.invalidFormat'))
        case 'In':
            return value => empty(value) || (Array.isArray(a) && a.includes(value)) || vt('fields.rules.notAllowed')
        default:
            return null
    }
}

/**
 * What a field's conditions decide under the form's current values: the
 * server evaluates the same conditions the same way, comparing a
 * relation by its id, a boolean as true/false, anything else as text.
 */
export function fieldState(field: VectorField, values: Record<string, unknown>): VectorFieldState {
    const c = field.conditions
    if (!c) return { visible: true, required: false, disabled: false }
    return {
        visible: holds(c.visible, values),
        required: c.required.length > 0 && holds(c.required, values),
        disabled: c.disabled.length > 0 && holds(c.disabled, values),
    }
}

function holds(conditions: VectorCondition[], values: Record<string, unknown>): boolean {
    return conditions.every((condition) => {
        const actual = normalize(values[condition.field])
        const list = Array.isArray(condition.value) ? condition.value.map(normalize) : [normalize(condition.value)]
        switch (condition.op) {
            case 'eq': return actual === normalize(condition.value)
            case 'ne': return actual !== normalize(condition.value)
            case 'in': return list.includes(actual)
            case 'nin': return !list.includes(actual)
            case 'null': return actual === null
            case 'nnull': return actual !== null
            default: return false
        }
    })
}

function normalize(value: unknown): string | null {
    if (value && typeof value === 'object' && 'id' in value) value = (value as { id: unknown }).id
    if (value === null || value === undefined || value === '') return null
    if (typeof value === 'boolean') return value ? 'true' : 'false'
    if (typeof value === 'number' || typeof value === 'string') return String(value)
    return null
}
