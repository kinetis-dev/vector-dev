import { describe, expect, it } from 'vitest'
import { applyViolations, initialValue } from '#vector/app/composables/useVectorForm'
import { field } from '../fixtures'

describe('initialValue', () => {
    it('is the field default when it has one', () => {
        expect(initialValue(field({ name: 'status', type: 'enum', hasDefault: true, default: 'draft' }))).toBe('draft')
        expect(initialValue(field({ name: 'isFeatured', type: 'boolean', hasDefault: true, default: true }))).toBe(true)
    })

    it('is the type\'s empty value otherwise', () => {
        expect(initialValue(field({ name: 'isFeatured', type: 'boolean' }))).toBe(false)
        expect(initialValue(field({ name: 'tags', type: 'manyToMany' }))).toEqual([])
        expect(initialValue(field({ name: 'name' }))).toBeNull()
    })
})

describe('applyViolations', () => {
    const unprocessable = (errors: { path: (string | number)[], message: string }[]) => ({ status: 422, data: { errors } })

    it('puts each violation on the field it names, capitalised', () => {
        const errors: Record<string, string[]> = {}

        const general = applyViolations(unprocessable([
            { path: ['name'], message: 'this value is too long.' },
            { path: ['name'], message: 'this value is already used.' },
            { path: ['color'], message: 'this value should not be blank.' },
        ]), { name: 'x', color: '' }, errors)

        expect(general).toBeNull()
        expect(errors).toEqual({
            name: ['This value is too long.', 'This value is already used.'],
            color: ['This value should not be blank.'],
        })
    })

    it('returns a violation that names no field of the form', () => {
        const errors: Record<string, string[]> = {}

        expect(applyViolations(unprocessable([{ path: [], message: 'The record changed meanwhile.' }]), { name: 'x' }, errors)).toBe('The record changed meanwhile.')
        expect(applyViolations(unprocessable([{ path: ['secret'], message: 'Nope.' }]), { name: 'x' }, errors)).toBe('Nope.')
        expect(errors).toEqual({})
    })

    it('says the server rejected the request when a 422 carries no violation', () => {
        expect(applyViolations(unprocessable([]), {}, {})).toBe('The server rejected the request.')
    })

    it('reads the status from ofetch\'s statusCode too', () => {
        const errors: Record<string, string[]> = {}
        applyViolations({ statusCode: 422, data: { errors: [{ path: ['name'], message: 'bad' }] } }, { name: '' }, errors)
        expect(errors.name).toEqual(['Bad'])
    })

    it('shows any other failure as its message', () => {
        expect(applyViolations({ status: 403, data: { error: 'You may not edit this record.' } }, {}, {})).toBe('You may not edit this record.')
        expect(applyViolations(new Error('Network down'), {}, {})).toBe('Network down')
    })
})
