import { describe, expect, it } from 'vitest'
import { fieldState, hasEmailShape, isEmailAddress, phpRegex, rulesFor } from '#vector/app/utils/rules'
import { field, rule } from '../fixtures'

/** Every rule's verdict on one value: the first message, or true. */
function check(rules: ((value: unknown) => true | string)[], value: unknown): true | string {
    for (const r of rules) {
        const verdict = r(value)
        if (verdict !== true) return verdict
    }
    return true
}

describe('rulesFor: required', () => {
    const name = field({ name: 'name', nullable: false })

    it('requires a non-nullable field on create only', () => {
        expect(check(rulesFor(name, 'create'), '')).toBe('Required')
        expect(check(rulesFor(name, 'create'), null)).toBe('Required')
        expect(check(rulesFor(name, 'create'), 'Blue')).toBe(true)
        expect(check(rulesFor(name, 'update'), '')).toBe(true)
    })

    it('never requires a field that has a value of its own', () => {
        for (const type of ['boolean', 'manyToMany', 'hasMany', 'hasOne'] as const) {
            expect(check(rulesFor(field({ name: 'x', type, nullable: false }), 'create'), null)).toBe(true)
        }
    })

    it('requires what a condition makes required, in either operation', () => {
        const optional = field({ name: 'releasedAt', nullable: true })
        const state = { visible: true, required: true, disabled: false }

        expect(check(rulesFor(optional, 'update', state), null)).toBe('Required')
        expect(check(rulesFor(optional, 'update'), null)).toBe(true)
    })

    it('treats an empty list as empty', () => {
        expect(check(rulesFor(field({ name: 'ids', nullable: false }), 'create'), [])).toBe('Required')
    })
})

describe('rulesFor: constraints', () => {
    const rulesOf = (...rules: ReturnType<typeof rule>[]) => rulesFor(field({ name: 'x', rules }), 'update')

    it('NotBlank rejects whitespace', () => {
        const rules = rulesOf(rule('NotBlank'))
        expect(check(rules, '   ')).toBe('Required')
        expect(check(rules, ' a ')).toBe(true)
    })

    it('MaxLength and MinLength count characters, not UTF-16 units', () => {
        expect(check(rulesOf(rule('MaxLength', 3)), '😀😀😀')).toBe(true)
        expect(check(rulesOf(rule('MaxLength', 3)), 'abcd')).toBe('At most 3 characters')
        expect(check(rulesOf(rule('MinLength', 2)), '😀')).toBe('At least 2 characters')
    })

    it('leaves an empty value to the required rule', () => {
        for (const constraint of [rule('MaxLength', 1), rule('MinLength', 5), rule('Email'), rule('Url'), rule('GreaterThan', 0), rule('Regex', '/^x$/'), rule('In', ['a'])]) {
            expect(check(rulesOf(constraint), '')).toBe(true)
            expect(check(rulesOf(constraint), null)).toBe(true)
        }
    })

    it('Email and Url', () => {
        expect(check(rulesOf(rule('Email')), 'admin@vector.local')).toBe(true)
        expect(check(rulesOf(rule('Email')), 'admin@vector')).toBe('Enter a valid e-mail address')
        expect(check(rulesOf(rule('Url')), 'https://kinetis.dev/docs')).toBe(true)
        expect(check(rulesOf(rule('Url')), 'ftp://kinetis.dev')).toBe('Enter a valid URL')
        expect(check(rulesOf(rule('WebUrl')), 'https://kinetis.dev')).toBe(true)
        expect(check(rulesOf(rule('WebUrl')), 'javascript:alert(1)')).toBe('Enter a valid URL')
    })

    it('numeric comparisons', () => {
        expect(check(rulesOf(rule('GreaterThan', 0)), 0)).toBe('Must be greater than 0')
        expect(check(rulesOf(rule('GreaterThan', 0)), 1)).toBe(true)
        expect(check(rulesOf(rule('GreaterThanOrEqual', 0)), 0)).toBe(true)
        expect(check(rulesOf(rule('GreaterThanOrEqual', 0)), -1)).toBe('Must be at least 0')
        expect(check(rulesOf(rule('LessThan', 10)), 10)).toBe('Must be less than 10')
        expect(check(rulesOf(rule('LessThanOrEqual', 10)), 10)).toBe(true)
        expect(check(rulesOf(rule('LessThanOrEqual', 10)), '11')).toBe('Must be at most 10')
    })

    it('Regex takes a PCRE pattern with delimiters and flags', () => {
        expect(check(rulesOf(rule('Regex', '/^[a-z0-9-]+$/')), 'summer-sale')).toBe(true)
        expect(check(rulesOf(rule('Regex', '/^[a-z0-9-]+$/')), 'Summer Sale')).toBe('Invalid format')
        expect(check(rulesOf(rule('Regex', '#^abc$#i')), 'ABC')).toBe(true)
        // a PCRE-only flag is dropped rather than failing to compile
        expect(check(rulesOf(rule('Regex', '~^a b$~x')), 'a b')).toBe(true)
    })

    it('Regex shows the constraint\'s own message', () => {
        expect(check(rulesOf(rule('Regex', '/^#[0-9a-f]{6}$/i', 'Use a hex colour.')), 'red')).toBe('Use a hex colour.')
    })

    it('In', () => {
        expect(check(rulesOf(rule('In', ['draft', 'active'])), 'active')).toBe(true)
        expect(check(rulesOf(rule('In', ['draft', 'active'])), 'deleted')).toBe('Not an allowed value')
    })

    it('leaves a constraint with no client equivalent to the server', () => {
        expect(rulesOf(rule('UniqueEntity', 'tags', 'name'))).toHaveLength(0)
    })
})

describe('isEmailAddress', () => {
    it('takes one @ with text before it and a dotted domain after it', () => {
        for (const value of ['a@b.c', 'admin@vector.local', 'a.b@c.d.e', 'a@b..c', 'a@.b.c', '+x@y.z', 'a@b.c.']) {
            expect(isEmailAddress(value), value).toBe(true)
        }
    })

    it('rejects whitespace, a second @, an empty side and a domain without an inner dot', () => {
        for (const value of ['', 'a@b', 'a@b.', 'a@.b', '@b.c', 'a@', 'a@@b.c', 'a@b@c.d', 'a b@c.d', 'a@b.c ', ' a@b.c', 'a@b.c\n', 'a@b\t.c', 'a@b.c\u00a0', 'ab.c']) {
            expect(isEmailAddress(value), JSON.stringify(value)).toBe(false)
        }
    })
})

describe('hasEmailShape', () => {
    it('takes text, an @, text, a dot and text anywhere on one line', () => {
        for (const value of ['a@b.c', 'x a@b.c y', '@@@.@', 'a b@c d.e', 'name: a@b.c', 'a@b.c\nnext', 'first\na@b.c', '.a@b.c', 'a@@.c']) {
            expect(hasEmailShape(value), JSON.stringify(value)).toBe(true)
        }
    })

    it('rejects a missing part, or the parts split across lines', () => {
        for (const value of ['', 'a@b', '@b.c', 'a@.c', 'a@b.', 'a.b@c', 'a@b\n.c', 'a\n@b.c', 'a@b\r.c', 'a@b\u2028.c', 'a@b\u2029.c']) {
            expect(hasEmailShape(value), JSON.stringify(value)).toBe(false)
        }
    })
})

describe('phpRegex', () => {
    it('reads the first character as the delimiter and the letters after its last occurrence as flags', () => {
        expect(phpRegex('/abc/i')).toMatchObject({ source: 'abc', flags: 'i' })
        expect(phpRegex('#a/b#')).toMatchObject({ source: 'a\\/b', flags: '' })
        expect(phpRegex('/a/b/')).toMatchObject({ source: 'a\\/b', flags: '' })
        expect(phpRegex('//')).toMatchObject({ source: '(?:)', flags: '' })
        expect(phpRegex('aba')).toMatchObject({ source: 'b', flags: '' })
        expect(phpRegex('/a\nb/m')).toMatchObject({ source: 'a\\nb', flags: 'm' })
    })

    it('keeps the flags JavaScript has and drops PCRE-only ones', () => {
        expect(phpRegex('~^a b$~xiu')).toMatchObject({ source: '^a b$', flags: 'iu' })
        expect(phpRegex('/a/D')).toMatchObject({ source: 'a', flags: '' })
    })

    it('takes a pattern without a closing delimiter, or with anything but letters after it, as it is', () => {
        expect(phpRegex('abc')).toMatchObject({ source: 'abc', flags: '' })
        expect(phpRegex('/')).toMatchObject({ source: '\\/', flags: '' })
        expect(phpRegex('/abc/i1')).toMatchObject({ source: '\\/abc\\/i1', flags: '' })
        expect(phpRegex('/abc/ i')).toMatchObject({ source: '\\/abc\\/ i', flags: '' })
        expect(phpRegex('^[a-z]+$')).toMatchObject({ source: '^[a-z]+$', flags: '' })
        // a line terminator is never a delimiter
        expect(phpRegex('\nabc\n')).toMatchObject({ source: '\\nabc\\n', flags: '' })
    })
})

describe('fieldState', () => {
    const conditions = (c: Partial<NonNullable<ReturnType<typeof field>['conditions']>>) => ({ visible: [], required: [], disabled: [], ...c })

    it('is visible, optional and enabled without conditions', () => {
        expect(fieldState(field({ name: 'x' }), {})).toEqual({ visible: true, required: false, disabled: false })
    })

    it('evaluates every operator', () => {
        const at = (op: 'eq' | 'ne' | 'in' | 'nin' | 'null' | 'nnull', value: unknown, actual: unknown) =>
            fieldState(field({ name: 'x', conditions: conditions({ visible: [{ field: 'status', op, value }] }) }), { status: actual }).visible

        expect(at('eq', 'active', 'active')).toBe(true)
        expect(at('eq', 'active', 'draft')).toBe(false)
        expect(at('ne', 'draft', 'active')).toBe(true)
        expect(at('in', ['active', 'archived'], 'archived')).toBe(true)
        expect(at('in', ['active', 'archived'], 'draft')).toBe(false)
        expect(at('nin', ['draft'], 'active')).toBe(true)
        expect(at('null', null, '')).toBe(true)
        expect(at('null', null, undefined)).toBe(true)
        expect(at('nnull', null, 'x')).toBe(true)
    })

    it('compares as the server does: relation by id, boolean as text, number as text', () => {
        const eq = (value: unknown, actual: unknown) =>
            fieldState(field({ name: 'x', conditions: conditions({ visible: [{ field: 'f', op: 'eq', value }] }) }), { f: actual }).visible

        expect(eq(3, { id: 3, label: 'Books' })).toBe(true)
        expect(eq('3', 3)).toBe(true)
        expect(eq(true, true)).toBe(true)
        expect(eq('true', true)).toBe(true)
        expect(eq(false, true)).toBe(false)
    })

    it('needs every condition of a kind to hold', () => {
        const f = field({
            name: 'isFeatured',
            conditions: conditions({ disabled: [{ field: 'status', op: 'eq', value: 'archived' }, { field: 'stock', op: 'eq', value: 0 }] }),
        })

        expect(fieldState(f, { status: 'archived', stock: 0 }).disabled).toBe(true)
        expect(fieldState(f, { status: 'archived', stock: 5 }).disabled).toBe(false)
    })

    it('mirrors the demo product: releasedAt shows unless draft, and is required when active', () => {
        const releasedAt = field({
            name: 'releasedAt',
            type: 'datetime',
            conditions: conditions({
                visible: [{ field: 'status', op: 'ne', value: 'draft' }],
                required: [{ field: 'status', op: 'eq', value: 'active' }],
            }),
        })

        expect(fieldState(releasedAt, { status: 'draft' })).toEqual({ visible: false, required: false, disabled: false })
        expect(fieldState(releasedAt, { status: 'active' })).toEqual({ visible: true, required: true, disabled: false })
        expect(fieldState(releasedAt, { status: 'archived' })).toEqual({ visible: true, required: false, disabled: false })
    })
})
