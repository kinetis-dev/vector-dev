import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { bundledMessages, pluralCategories, pluralRule } from '#vector/app/i18n'

// Each language's own plural forms, from the browser's CLDR rules.

const pick = (locale: string, forms: number, counts: number[]) => counts.map(n => pluralRule(locale)(n, forms))

describe('pluralCategories', () => {
    it('lists the forms whole counts reach, in CLDR order', () => {
        expect(pluralCategories('en')).toEqual(['one', 'other'])
        expect(pluralCategories('fr')).toEqual(['one', 'other'])
        expect(pluralCategories('he')).toEqual(['one', 'two', 'other'])
        expect(pluralCategories('ru')).toEqual(['one', 'few', 'many'])
        expect(pluralCategories('pl')).toEqual(['one', 'few', 'many'])
        expect(pluralCategories('ar')).toEqual(['zero', 'one', 'two', 'few', 'many', 'other'])
        expect(pluralCategories('ja')).toEqual(['other'])
    })
})

describe('pluralRule', () => {
    it('picks English’s singular for one and plural otherwise', () => {
        expect(pick('en', 2, [0, 1, 2, 21])).toEqual([1, 0, 1, 1])
    })

    it('picks Russian’s one, few and many', () => {
        // 1 поле · 2 поля · 5 полей · 21 поле · 22 поля · 11 полей
        expect(pick('ru', 3, [1, 2, 5, 21, 22, 11])).toEqual([0, 1, 2, 0, 1, 2])
    })

    it('picks Hebrew’s dual', () => {
        expect(pick('he', 3, [1, 2, 3])).toEqual([0, 1, 2])
    })

    it('picks each of Arabic’s six', () => {
        expect(pick('ar', 6, [0, 1, 2, 3, 11, 100])).toEqual([0, 1, 2, 3, 4, 5])
    })

    it('gives Japanese its one form', () => {
        expect(pick('ja', 1, [0, 1, 7])).toEqual([0, 0, 0])
    })

    it('sends a count only millions reach to the other form', () => {
        expect(pick('fr', 2, [1, 1_000_000])).toEqual([0, 1])
    })

    it('reads an application’s two-form English-style message in any language', () => {
        expect(pick('ru', 2, [1, 5])).toEqual([0, 1])
    })
})

describe('messages with plural forms', () => {
    const i18n = (locale: string) => createI18n({
        legacy: false,
        locale,
        fallbackLocale: 'en',
        messages: { ...bundledMessages },
        pluralRules: { [locale]: pluralRule(locale) },
    }).global

    it('render in English', () => {
        expect(i18n('en').t('list.inline.pending', { count: 1 }, 1)).toBe('1 unsaved change')
        expect(i18n('en').t('list.inline.pending', { count: 2 }, 2)).toBe('2 unsaved changes')
    })

    it('render Hebrew’s dual', () => {
        const he = i18n('he')
        expect(he.t('list.inline.saved', { count: 1 }, 1)).toBe('רשומה אחת נשמרה.')
        expect(he.t('list.inline.saved', { count: 2 }, 2)).toBe('שתי רשומות נשמרו.')
        expect(he.t('list.inline.saved', { count: 5 }, 5)).toBe('5 רשומות נשמרו.')
    })
})
