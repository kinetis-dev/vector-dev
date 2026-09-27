import { describe, expect, it } from 'vitest'
import { vt } from '#vector/app/utils/translate'

// The vector-i18n plugin has run by the time a test does, as it has
// before any page renders in the admin.
describe('vt', () => {
    it('translates a key of the admin\'s own strings', () => {
        expect(vt('common.save')).toBe('Save')
        expect(vt('shell.login.submit')).toBe('Sign in')
    })

    it('interpolates named parameters', () => {
        expect(vt('record.deleteTitle', { noun: 'tag', title: 'Sale' })).toBe('Delete tag “Sale”?')
    })

    it('pluralizes on the count passed last', () => {
        expect(vt('list.inline.pending', { count: 1 }, 1)).toBe('1 unsaved change')
        expect(vt('list.inline.pending', { count: 4 }, 4)).toBe('4 unsaved changes')
        expect(vt('list.inline.saved', { count: 3 }, 3)).toBe('3 records saved.')
    })

    it('returns the key for a string it does not know', () => {
        expect(vt('nowhere.to.be.found')).toBe('nowhere.to.be.found')
    })

    it('is the same function components get from useVectorT', () => {
        expect(useVectorT().t).toBe(vt)
        expect(useVectorT().locale.value).toBe('en')
    })
})
