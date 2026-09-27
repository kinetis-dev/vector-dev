const currencyFormatters = new Map<string, Intl.NumberFormat>()

// The UI's language, which dates, numbers and money format in; undefined (the
// browser's) until the i18n plugin sets it. The UI runs in the browser only,
// one app per page, so a module-level value is safe.
let formatLocale: string | undefined

/** Formats in `locale` from now on: the admin's current language. */
export function setFormatLocale(locale: string | undefined): void {
    if (locale === formatLocale) return
    formatLocale = locale
    currencyFormatters.clear()
    dateTime = null
    date = null
}

// The server's ISO 4217 exponents for the codes that are not hundredths. The
// server stores minor units, so its table — not the browser's CLDR data, which
// differs for a few codes — decides where the decimal point goes.
const minorUnitExceptions = new Map<string, number>()

/** Takes the exponents the schema publishes. */
export function adoptMinorUnits(exponents: Record<string, number>): void {
    minorUnitExceptions.clear()
    for (const [code, digits] of Object.entries(exponents)) minorUnitExceptions.set(code, digits)
}

/** How many decimals a money value in this currency has: 2 unless ISO 4217 says otherwise. */
export function minorDigits(currency: string | null | undefined): number {
    if (!currency) return 2
    return minorUnitExceptions.get(currency.toUpperCase()) ?? 2
}

export function formatMoney(minorUnits: number, currency: string): string {
    const digits = minorDigits(currency)
    const key = `${currency}:${digits}`
    let formatter = currencyFormatters.get(key)

    if (!formatter) {
        formatter = new Intl.NumberFormat(formatLocale, { style: 'currency', currency, minimumFractionDigits: digits, maximumFractionDigits: digits })
        currencyFormatters.set(key, formatter)
    }

    return formatter.format(minorUnits / 10 ** digits)
}

let dateTime: Intl.DateTimeFormat | null = null
let date: Intl.DateTimeFormat | null = null

export function formatDateTime(iso: string): string {
    dateTime ??= new Intl.DateTimeFormat(formatLocale, { dateStyle: 'medium', timeStyle: 'short' })
    return dateTime.format(new Date(iso))
}

export function formatDate(iso: string): string {
    // A date-only value is a calendar date, not an instant: keep it out of the local zone.
    const [y, m, d] = iso.slice(0, 10).split('-').map(Number)

    date ??= new Intl.DateTimeFormat(formatLocale, { dateStyle: 'medium' })
    return date.format(new Date(y!, m! - 1, d!))
}

export function formatNumber(value: number, decimals?: number): string {
    return new Intl.NumberFormat(formatLocale, decimals === undefined ? {} : { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(value)
}

/**
 * A money field's currency: its fixed one, or the one its currencyField
 * names in the record — through a relation for `order.currency`, whose
 * value is the related record (or `{id, label, currency}`). Null when the
 * record does not say.
 */
export function currencyOf(field: { props?: Record<string, unknown> | unknown[] | null }, record: Record<string, unknown> | undefined): string | null {
    const props = field.props && !Array.isArray(field.props) ? field.props : {}
    if (typeof props.currency === 'string') return props.currency
    if (typeof props.currencyField !== 'string') return null
    const value = props.currencyField.split('.').reduce<unknown>(
        (current, key) => (current && typeof current === 'object' ? (current as Record<string, unknown>)[key] : undefined),
        record,
    )
    return typeof value === 'string' && value !== '' ? value : null
}

const currencyNames = new Map<string, Intl.DisplayNames>()

/** A currency code with its name in the given language: `EUR · Euro`. */
export function currencyLabel(code: string, locale: string | undefined = formatLocale): string {
    const key = locale ?? ''
    let names = currencyNames.get(key)
    if (!names) {
        try {
            names = new Intl.DisplayNames(locale ? [locale] : undefined, { type: 'currency' })
        } catch {
            return code
        }
        currencyNames.set(key, names)
    }
    const name = names.of(code)
    return name && name !== code ? `${code} · ${name}` : code
}
