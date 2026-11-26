import { describe, expect, it } from 'vitest'
import { COUNTRY_CODES, COUNTRY_LOCALES, loadCountryNames } from '#vector/app/utils/countries'

// The static country names behind the Country field: one file per locale,
// all keyed by the same 249 ISO 3166-1 alpha-2 codes.

describe('country names', () => {
    it('lists the 249 assigned codes, sorted', () => {
        expect(COUNTRY_CODES).toHaveLength(249)
        expect(new Set(COUNTRY_CODES).size).toBe(249)
        expect([...COUNTRY_CODES]).toEqual([...COUNTRY_CODES].sort())
    })

    it.each(Object.keys(COUNTRY_LOCALES))('%s holds exactly the codes of COUNTRY_CODES, each named', async (locale) => {
        const names = await loadCountryNames(locale)
        expect(Object.keys(names)).toEqual([...COUNTRY_CODES])
        for (const code of COUNTRY_CODES) {
            expect(names[code]).toBeTruthy()
            expect(names[code]).not.toBe(code)
        }
    })

    it('loads the named locale', async () => {
        expect((await loadCountryNames('fr')).DE).toBe('Allemagne')
        expect((await loadCountryNames('de')).DE).toBe('Deutschland')
    })

    it('keeps a region tag that has a file of its own', async () => {
        expect(await loadCountryNames('pt-BR')).toBe((await COUNTRY_LOCALES['pt-BR']!()).default)
    })

    it('names pt in European Portuguese and pt-BR in Brazilian Portuguese', async () => {
        expect((await loadCountryNames('pt')).IR).toBe('Irão')
        expect((await loadCountryNames('pt-BR')).IR).toBe('Irã')
    })

    it('falls back from a region tag to its base language', async () => {
        expect(await loadCountryNames('pt-PT')).toBe((await COUNTRY_LOCALES.pt!()).default)
        expect(await loadCountryNames('fr_CA')).toBe((await COUNTRY_LOCALES.fr!()).default)
        expect(await loadCountryNames('zh-Hans-CN')).toBe((await COUNTRY_LOCALES['zh-Hans']!()).default)
    })

    it('falls back to English for an unknown locale', async () => {
        const en = (await COUNTRY_LOCALES.en!()).default
        expect(await loadCountryNames('xx')).toBe(en)
        expect(await loadCountryNames('')).toBe(en)
    })
})
