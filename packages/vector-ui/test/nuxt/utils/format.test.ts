import { afterEach, describe, expect, it } from 'vitest'
import { adoptMinorUnits, currencyLabel, currencyOf, formatDate, formatDateTime, formatMoney, formatNumber, minorDigits } from '#vector/app/utils/format'

// The formatters use the runtime's default locale, so each expectation is
// built with the same Intl call rather than a hard-coded string.
const money = (amount: number, currency: string, digits = 2) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: digits, maximumFractionDigits: digits }).format(amount)

describe('minor units', () => {
    afterEach(() => adoptMinorUnits({}))

    it('defaults to hundredths', () => {
        expect(minorDigits('EUR')).toBe(2)
        expect(minorDigits(null)).toBe(2)
        expect(minorDigits(undefined)).toBe(2)
    })

    it('takes the exponents the schema publishes, case-insensitively', () => {
        adoptMinorUnits({ JPY: 0, KWD: 3 })

        expect(minorDigits('jpy')).toBe(0)
        expect(minorDigits('KWD')).toBe(3)
        expect(minorDigits('EUR')).toBe(2)
    })

    it('replaces, not merges, the previous table', () => {
        adoptMinorUnits({ JPY: 0 })
        adoptMinorUnits({ KWD: 3 })

        expect(minorDigits('JPY')).toBe(2)
    })
})

describe('formatMoney', () => {
    afterEach(() => adoptMinorUnits({}))

    it('divides minor units by the currency exponent', () => {
        expect(formatMoney(123456, 'EUR')).toBe(money(1234.56, 'EUR'))
        expect(formatMoney(5, 'USD')).toBe(money(0.05, 'USD'))
        expect(formatMoney(-250, 'GBP')).toBe(money(-2.5, 'GBP'))
    })

    it('follows the server table over the browser for zero- and three-decimal currencies', () => {
        adoptMinorUnits({ JPY: 0, KWD: 3 })

        expect(formatMoney(1234, 'JPY')).toBe(money(1234, 'JPY', 0))
        expect(formatMoney(1234, 'KWD')).toBe(money(1.234, 'KWD', 3))
    })

    it('does not reuse a cached formatter after the exponent changes', () => {
        expect(formatMoney(1234, 'JPY')).toBe(money(12.34, 'JPY', 2))
        adoptMinorUnits({ JPY: 0 })
        expect(formatMoney(1234, 'JPY')).toBe(money(1234, 'JPY', 0))
    })
})

describe('dates', () => {
    it('keeps a date-only value on its calendar day whatever the local zone', () => {
        // The suite runs in Pacific/Honolulu (UTC-10): `new Date('2026-03-01')` is 28 February there.
        const expected = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(2026, 2, 1))

        expect(formatDate('2026-03-01')).toBe(expected)
        expect(formatDate('2026-03-01T00:00:00Z')).toBe(expected)
    })

    it('shows a datetime as an instant in the local zone', () => {
        const iso = '2026-03-01T06:30:00Z'
        const expected = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))

        expect(formatDateTime(iso)).toBe(expected)
    })
})

describe('formatNumber', () => {
    it('formats with the given decimals, or as the value has them', () => {
        expect(formatNumber(1234.5, 2)).toBe(new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(1234.5))
        expect(formatNumber(1234.5)).toBe(new Intl.NumberFormat(undefined).format(1234.5))
    })
})

describe('currencyOf', () => {
    it('prefers a fixed currency', () => {
        expect(currencyOf({ props: { currency: 'USD', currencyField: 'currency' } }, { currency: 'EUR' })).toBe('USD')
    })

    it('reads the currency field from the record, through a relation', () => {
        expect(currencyOf({ props: { currencyField: 'currency' } }, { currency: 'GBP' })).toBe('GBP')
        expect(currencyOf({ props: { currencyField: 'order.currency' } }, { order: { id: 7, label: '#7', currency: 'EUR' } })).toBe('EUR')
    })

    it('is null when the record does not say', () => {
        expect(currencyOf({ props: { currencyField: 'currency' } }, { currency: '' })).toBeNull()
        expect(currencyOf({ props: { currencyField: 'order.currency' } }, { order: null })).toBeNull()
        expect(currencyOf({ props: { currencyField: 'currency' } }, undefined)).toBeNull()
        expect(currencyOf({ props: [] }, { currency: 'EUR' })).toBeNull()
        expect(currencyOf({ props: null }, { currency: 'EUR' })).toBeNull()
    })
})

describe('currencyLabel', () => {
    it('names the currency in the given language', () => {
        expect(currencyLabel('EUR', 'en')).toBe('EUR · Euro')
        expect(currencyLabel('EUR', 'de')).toBe('EUR · Euro')
        expect(currencyLabel('USD', 'en')).toBe('USD · US Dollar')
    })

    it('falls back to the bare code', () => {
        expect(currencyLabel('XYZ', 'en')).toBe('XYZ')
        expect(currencyLabel('EUR', 'not a locale!')).toBe('EUR')
    })
})
