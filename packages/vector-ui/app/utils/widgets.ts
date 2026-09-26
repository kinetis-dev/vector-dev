/** Formats a widget value by its declared format: number, money:CUR, percent, duration. */
export function formatWidgetValue(value: number | null | undefined, format: string | undefined): string {
    if (value === null || value === undefined) return vt('common.none')
    if (format?.startsWith('money:')) return formatMoney(Math.round(value), format.slice(6))
    if (format === 'percent') return vt('dashboard.percent', { value: formatNumber(value, 1) })
    if (format === 'duration') {
        const minutes = Math.round(value / 60)
        return minutes < 60 ? vt('dashboard.duration.minutes', { value: minutes }) : vt('dashboard.duration.hours', { value: formatNumber(minutes / 60, 1) })
    }
    return formatNumber(value, Number.isInteger(value) ? undefined : 2)
}
