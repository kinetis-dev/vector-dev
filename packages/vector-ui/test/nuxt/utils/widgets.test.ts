import { describe, expect, it } from 'vitest'
import { formatWidgetValue } from '#vector/app/utils/widgets'
import { formatMoney, formatNumber } from '#vector/app/utils/format'

describe('formatWidgetValue', () => {
    it('shows a missing value as the none mark', () => {
        expect(formatWidgetValue(null, undefined)).toBe('—')
        expect(formatWidgetValue(undefined, 'money:EUR')).toBe('—')
    })

    it('formats money from minor units, rounding a computed average', () => {
        expect(formatWidgetValue(1999.6, 'money:EUR')).toBe(formatMoney(2000, 'EUR'))
    })

    it('formats a percentage to one decimal', () => {
        expect(formatWidgetValue(12.345, 'percent')).toBe(`${formatNumber(12.345, 1)}%`)
    })

    it('formats a duration in seconds as minutes, then hours', () => {
        expect(formatWidgetValue(90, 'duration')).toBe('2 min')
        expect(formatWidgetValue(59 * 60, 'duration')).toBe('59 min')
        expect(formatWidgetValue(90 * 60, 'duration')).toBe(`${formatNumber(1.5, 1)} h`)
    })

    it('shows integers as they are and fractions to two decimals', () => {
        expect(formatWidgetValue(1234, undefined)).toBe(formatNumber(1234))
        expect(formatWidgetValue(3.14159, 'number')).toBe(formatNumber(3.14159, 2))
    })
})
