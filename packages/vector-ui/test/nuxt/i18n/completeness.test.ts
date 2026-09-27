import { describe, expect, it } from 'vitest'
import { bundledMessages } from '#vector/app/i18n'

// Every shipped locale mirrors English: the same keys, the same
// placeholders in each message, the same number of plural forms. This is
// a completeness test, not a translation check — the translations are
// machine translations.

type Messages = { [key: string]: string | Messages }

function flatten(messages: Messages, prefix = ''): Map<string, string> {
    const flat = new Map<string, string>()
    for (const [key, value] of Object.entries(messages)) {
        if (typeof value === 'string') flat.set(prefix + key, value)
        else for (const [inner, text] of flatten(value, `${prefix}${key}.`)) flat.set(inner, text)
    }
    return flat
}

const placeholders = (message: string) => [...message.matchAll(/\{(\w+)\}/g)].map(m => m[1]).sort()
const forms = (message: string) => message.split('|').length

const english = flatten(bundledMessages.en as unknown as Messages)
const locales = Object.keys(bundledMessages).filter(code => code !== 'en')

describe('shipped locales', () => {
    it('ship German, French, Dutch, Italian, Spanish, Portuguese and Hebrew besides English', () => {
        expect(locales.sort()).toEqual(['de', 'es', 'fr', 'he', 'it', 'nl', 'pt'])
    })

    describe.each(locales)('%s', (code) => {
        const translated = flatten(bundledMessages[code] as unknown as Messages)

        it('has every English key, and no other', () => {
            expect([...translated.keys()].filter(key => !english.has(key))).toEqual([])
            expect([...english.keys()].filter(key => !translated.has(key))).toEqual([])
        })

        it('keeps each message\'s placeholders and plural forms', () => {
            const mismatched = [...english].filter(([key, source]) => {
                const target = translated.get(key)
                return target === undefined
                    || JSON.stringify(placeholders(source)) !== JSON.stringify(placeholders(target))
                    || forms(source) !== forms(target)
            }).map(([key]) => key)

            expect(mismatched).toEqual([])
        })

        it('translates rather than copies', () => {
            // short technical words may stay the same; most messages must not
            const same = [...english].filter(([key, source]) => source.length > 12 && translated.get(key) === source)
            expect(same.length / english.size).toBeLessThan(0.1)
        })
    })
})
