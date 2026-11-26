import type { VectorCondition, VectorField, VectorFieldState, VectorRule } from '#vector/app/types/vector'
import { isEmailAddress, phpRegex } from '#vector/app/utils/internal/patterns'
import { boundsOfRule, boundViolation, type DateBounds } from '#vector/app/utils/internal/dateBounds'

type Rule = (value: unknown) => true | string

const blank = (value: unknown) => value === null || value === undefined || value === ''

/** A date range's `{from, to}`, or null for any other value. */
function rangeOf(value: unknown): { from: unknown, to: unknown } | null {
    return value !== null && typeof value === 'object' && !Array.isArray(value) && ('from' in value || 'to' in value) ? value as { from: unknown, to: unknown } : null
}

const empty = (value: unknown) => blank(value) || (Array.isArray(value) && value.length === 0)

/** A date range's value as its rules read it: with neither end set it is no value, as the server reads it. */
const rangeValue = (value: unknown) => {
    const range = rangeOf(value)
    return range !== null && blank(range.from) && blank(range.to) ? null : value
}

/**
 * The descriptor's rules, compiled to Vuetify rule functions. Each
 * mirrors the framework constraint the server runs, by its class's
 * short name; a constraint with no client equivalent is left to the
 * server, whose 422 lands on the field as `error-messages`.
 */
export function rulesFor(field: VectorField, operation: 'create' | 'update', state?: VectorFieldState): Rule[] {
    const rules: Rule[] = []
    const requiredHere = (operation === 'create' && field.nullable === false && !['manyToMany', 'hasMany', 'hasOne', 'boolean', 'repeater', 'tag'].includes(field.type)) || state?.required === true

    if (requiredHere) {
        rules.push(value => !empty(value) || vt('common.required'))
    }

    if (field.type === 'dateRange') {
        rules.push(rangeRule(field.range?.kind ?? 'date'))
    }

    for (const rule of field.rules) {
        const compiled = compile(rule)

        if (compiled) {
            rules.push(compiled)
        }
    }

    // only a date range reads an object of two empty ends as no value
    return field.type === 'dateRange' ? rules.map(rule => (value: unknown) => rule(rangeValue(value))) : rules
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
        case 'DateBounds':
            return dateRule(boundsOfRule(rule))
        default:
            return null
    }
}

/**
 * minDate() and maxDate(): days compared as days, instants as instants,
 * `today` and `now` resolved each time the rule runs, in the user's own
 * time zone.
 */
function dateRule(bounds: DateBounds): Rule {
    return (value) => {
        const range = rangeOf(value)
        const now = new Date()
        // a date range's two ends are bounded alike
        const broken = range ? boundViolation(bounds, range.from, now) ?? boundViolation(bounds, range.to, now) : boundViolation(bounds, value, now)
        if (!broken) return true
        const limit = typeof broken.bound === 'string' ? formatDate(broken.bound) : formatDateTime(broken.bound.toISOString())
        return vt(broken.side === 'min' ? 'fields.rules.minDate' : 'fields.rules.maxDate', { limit })
    }
}

/**
 * A date range has both ends or neither, and its end is never before its
 * start: days compared as days, instants as instants.
 */
function rangeRule(kind: 'date' | 'datetime'): Rule {
    return (value) => {
        const range = rangeOf(value)
        if (!range || (blank(range.from) && blank(range.to))) return true
        if (blank(range.from) || blank(range.to)) return vt('fields.dateRange.incomplete')
        const { from, to } = range
        // the controls send text; anything else is the server's to reject
        if (typeof from !== 'string' || typeof to !== 'string') return true
        const reversed = kind === 'date' ? to.slice(0, 10) < from.slice(0, 10) : new Date(to).getTime() < new Date(from).getTime()
        return !reversed || vt('fields.dateRange.reversed')
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
        const raw = values[condition.field]
        const actual = normalize(raw)
        const list = Array.isArray(condition.value) ? condition.value.map(normalize) : [normalize(condition.value)]
        // a list (a multiple select's values, a relation's ids) is empty when it holds nothing, as the server decides
        const empty = Array.isArray(raw) ? raw.length === 0 : actual === null
        switch (condition.op) {
            case 'eq': return actual === normalize(condition.value)
            case 'ne': return actual !== normalize(condition.value)
            case 'in': return list.includes(actual)
            case 'nin': return !list.includes(actual)
            case 'null': return empty
            case 'nnull': return !empty
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
