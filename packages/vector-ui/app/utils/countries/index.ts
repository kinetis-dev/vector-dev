import en from './en'

/** Every assigned ISO 3166-1 alpha-2 code, sorted; the keys of each locale file. */
export const COUNTRY_CODES: readonly string[] = Object.keys(en)

type CountryNames = Record<string, string>

// One dynamic import per locale, so a bundler splits each into its own chunk
// and only the one in use is fetched. Keep in step with generate-countries.mjs.
export const COUNTRY_LOCALES: Readonly<Record<string, () => Promise<{ default: CountryNames }>>> = {
    'ar': () => import('./ar'),
    'de': () => import('./de'),
    'en': () => import('./en'),
    'es': () => import('./es'),
    'fr': () => import('./fr'),
    'he': () => import('./he'),
    'it': () => import('./it'),
    'ja': () => import('./ja'),
    'ko': () => import('./ko'),
    'nl': () => import('./nl'),
    'pl': () => import('./pl'),
    'pt': () => import('./pt'),
    'pt-BR': () => import('./pt-BR'),
    'ru': () => import('./ru'),
    'tr': () => import('./tr'),
    'uk': () => import('./uk'),
    'zh-Hans': () => import('./zh-Hans'),
}

/**
 * The country names of a locale, keyed by code. A tag with no file of its own
 * falls back to its base language (pt-PT to pt, zh-Hans-CN to zh-Hans, then
 * zh), and finally to English.
 */
export async function loadCountryNames(locale: string): Promise<CountryNames> {
    const parts = locale.replaceAll('_', '-').split('-')
    for (let length = parts.length; length > 0; length--) {
        const load = COUNTRY_LOCALES[parts.slice(0, length).join('-')]
        if (load) {
            return (await load()).default
        }
    }
    return en
}
