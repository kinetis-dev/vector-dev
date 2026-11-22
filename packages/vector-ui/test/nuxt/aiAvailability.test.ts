import { describe, expect, it } from 'vitest'
import { aiCapabilities, aiFailureMessage, aiOffered, aiStatusOf } from '#vector/app/utils/aiAvailability'

// The layer's one check of whether the admin offers an AI feature, and its
// one wording of a failed AI call, which the filter, writing help and the
// assistant all use.

const AI = {
    filters: true, assistant: true, assistantWrites: false, writing: true,
    excluded: { profiles: true }, excludedFields: { users: ['email'] }, noWriting: { products: ['sku'] },
}

describe('AI availability in the admin', () => {
    it('reads capabilities.ai from the descriptor, when there is one', () => {
        expect(aiCapabilities({ capabilities: { impersonation: false, ai: AI } })).toEqual(AI)
        expect(aiCapabilities({ capabilities: { impersonation: false } })).toBeUndefined()
        expect(aiCapabilities(null)).toBeUndefined()
    })

    it('offers a feature the server flags, off a resource or field kept from the model', () => {
        expect(aiOffered(AI, 'assistant')).toBe(true)
        expect(aiOffered(AI, 'assistantWrites')).toBe(false)
        expect(aiOffered(undefined, 'filters')).toBe(false)
        expect(aiOffered(AI, 'filters', 'products')).toBe(true)
        expect(aiOffered(AI, 'filters', 'profiles')).toBe(false)
        expect(aiOffered(AI, 'writing', 'users', 'name')).toBe(true)
        expect(aiOffered(AI, 'writing', 'users', 'email')).toBe(false)
    })

    it('offers no writing help on a field the server says it is off for, and still every other feature', () => {
        expect(aiOffered(AI, 'writing', 'products', 'sku')).toBe(false)
        expect(aiOffered(AI, 'writing', 'products', 'name')).toBe(true)
        expect(aiOffered(AI, 'filters', 'products', 'sku')).toBe(true)
        // without noWriting, writing help is off only where something is kept back
        expect(aiOffered({ ...AI, noWriting: undefined }, 'writing', 'products', 'sku')).toBe(true)
    })

    it('reads the status however the client reports it', () => {
        expect(aiStatusOf({ status: 429 })).toBe(429)
        expect(aiStatusOf({ statusCode: 403 })).toBe(403)
        expect(aiStatusOf({ response: { status: 501 } })).toBe(501)
        expect(aiStatusOf(null)).toBeUndefined()
    })

    it('says when a spent budget renews, else the server\'s words, else that it is spent', () => {
        const renews = aiFailureMessage({ status: 429, response: { status: 429, headers: new Headers({ 'Retry-After': '3600' }) } }, 'failed')
        expect(renews).toMatch(/^Today's AI budget is spent; try again after /)
        expect(aiFailureMessage({ status: 429, data: { error: 'Spent until midnight.' } }, 'failed')).toBe('Spent until midnight.')
        expect(aiFailureMessage({ status: 429 }, 'failed')).toBe('Today\'s AI budget is spent.')
    })

    it('says any other failure in the server\'s words, else the fallback', () => {
        expect(aiFailureMessage({ status: 403, data: { error: 'Not yours.' } }, 'failed')).toBe('Not yours.')
        expect(aiFailureMessage({ status: 500 }, 'failed')).toBe('failed')
        expect(aiFailureMessage({ status: 500, data: { error: 'ignored' } }, 'failed', { error: 'From the stream.' })).toBe('From the stream.')
    })
})
