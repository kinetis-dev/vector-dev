import { describe, expect, it } from 'vitest'
import { languageName } from '#vector/app/i18n'

// A language's name in the viewer's language, under its name in itself.

describe('languageName', () => {
    it('names a language in the viewer\'s language', () => {
        expect(languageName('de', 'en', 'Deutsch')).toBe('German')
        expect(languageName('he', 'en', 'עברית')).toBe('Hebrew')
        expect(languageName('de', 'he', 'Deutsch')).toBe('גרמנית')
    })

    it('adds nothing that would repeat the name', () => {
        expect(languageName('de', 'de', 'Deutsch')).toBeNull()
        expect(languageName('he', 'he', 'עברית')).toBeNull()
    })

    it('adds nothing for a language the browser cannot name', () => {
        expect(languageName('qaa', 'en', 'Private')).toBeNull()
    })
})
