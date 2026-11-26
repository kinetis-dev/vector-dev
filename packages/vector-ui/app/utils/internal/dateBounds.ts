// A date or date-time field's minDate() and maxDate(), as its DateBounds
// rule carries them. Nested under utils/ so Nuxt does not auto-import
// them: they are not part of the layer's public API.
import type { VectorField, VectorRule } from '#vector/app/types/vector'

export type DateKind = 'date' | 'datetime'

/** The bounds as declared: a day or `today`, a UTC instant or `now`, or none. */
export interface DateBounds {
    kind: DateKind
    min: string | null
    max: string | null
}

const SHORT_NAME = 'DateBounds'

/** Whether a rule is the server's DateBounds. */
export function isDateBoundsRule(rule: VectorRule): boolean {
    return rule.constraint.split('\\').pop() === SHORT_NAME
}

/** The bounds a rule declares. */
export function boundsOfRule(rule: VectorRule): DateBounds {
    const [kind, min, max] = rule.args as [unknown, unknown, unknown]

    return {
        kind: kind === 'datetime' ? 'datetime' : 'date',
        min: typeof min === 'string' ? min : null,
        max: typeof max === 'string' ? max : null,
    }
}

/** The field's bounds, or null when it declares none. */
export function dateBoundsOf(field: VectorField): DateBounds | null {
    const rule = field.rules.find(isDateBoundsRule)

    return rule ? boundsOfRule(rule) : null
}

function pad(n: number): string {
    return String(n).padStart(2, '0')
}

/** A moment's calendar day in the user's own time zone, as `YYYY-MM-DD`. */
export function localDay(moment: Date): string {
    return `${moment.getFullYear()}-${pad(moment.getMonth() + 1)}-${pad(moment.getDate())}`
}

/** A moment as a `datetime-local` control writes it, to the minute, in the user's own time zone. */
export function localMinute(moment: Date): string {
    return `${localDay(moment)}T${pad(moment.getHours())}:${pad(moment.getMinutes())}`
}

/**
 * A bound resolved at `now`: `today` is the user's current day, `now`
 * the current instant. A date's bound is a day; a date-time's an instant.
 */
export function resolveBound(kind: DateKind, bound: string | null, now: Date): string | Date | null {
    if (bound === null) return null
    if (kind === 'date') return bound === 'today' ? localDay(now) : bound
    return bound === 'now' ? now : new Date(bound)
}

/**
 * What is wrong with one value under the bounds at `now`: the bound it
 * falls before or after, resolved, or null when it is inside them (or is
 * no date at all, which other rules report). Days compare as days,
 * instants as instants.
 */
export function boundViolation(bounds: DateBounds, value: unknown, now: Date): { side: 'min' | 'max', bound: string | Date } | null {
    if (typeof value !== 'string' || value === '') return null

    const min = resolveBound(bounds.kind, bounds.min, now)
    const max = resolveBound(bounds.kind, bounds.max, now)

    if (bounds.kind === 'date') {
        const day = value.slice(0, 10)
        if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) return null
        if (typeof min === 'string' && day < min) return { side: 'min', bound: min }
        if (typeof max === 'string' && day > max) return { side: 'max', bound: max }
        return null
    }

    const instant = new Date(value).getTime()
    if (Number.isNaN(instant)) return null
    if (min instanceof Date && instant < min.getTime()) return { side: 'min', bound: min }
    if (max instanceof Date && instant > max.getTime()) return { side: 'max', bound: max }
    return null
}

/** The native control's `min` and `max` attributes, in its own local format, resolved at `now`. */
export function controlLimits(bounds: DateBounds | null, now: Date): { min?: string, max?: string } {
    if (!bounds) return {}

    const attribute = (bound: string | null): string | undefined => {
        const resolved = resolveBound(bounds.kind, bound, now)
        if (resolved === null) return undefined
        return typeof resolved === 'string' ? resolved : localMinute(resolved)
    }

    return { min: attribute(bounds.min), max: attribute(bounds.max) }
}
