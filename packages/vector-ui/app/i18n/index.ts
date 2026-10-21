import ar from './ar'
import de from './de'
import en from './en'
import es from './es'
import fr from './fr'
import he from './he'
import it from './it'
import ja from './ja'
import ko from './ko'
import nl from './nl'
import pl from './pl'
import pt from './pt'
import ptBR from './pt-BR'
import ru from './ru'
import tr from './tr'
import uk from './uk'
import zhHans from './zh-Hans'

/**
 * Every language the UI ships. English is the base: each other locale
 * mirrors its keys (a test holds them to it), and a key a locale lacks
 * falls back to English. All but English are machine translations.
 */
export const bundledMessages: Record<string, typeof en> = {
    en, de, fr, nl, it, es, pt, 'pt-BR': ptBR, pl, tr, ru, uk, he, ar, ko, ja, 'zh-Hans': zhHans,
}

/** Languages written right to left: the page's direction follows the language. */
export const rtlLanguages = ['ar', 'fa', 'he', 'ur', 'yi']

/** Whether a language tag is written right to left. */
export function isRtl(tag: string): boolean {
    return rtlLanguages.includes(tag.split('-')[0]!.toLowerCase())
}

/** Vuetify's name for a locale: `pt-BR` is `ptBR`. */
export function vuetifyLocale(tag: string): string {
    return tag.replace(/-([a-z])/gi, (_, c: string) => c.toUpperCase())
}

/** The Vuetify locale a tag switches to, of those `known`: its own, else its language's, else English. */
export function knownVuetifyLocale(tag: string, known: Record<string, unknown>): string {
    const own = vuetifyLocale(tag)
    if (known[own]) return own
    const language = tag.split('-')[0]!
    return known[language] ? language : 'en'
}

/** The direction a language tag is written in. */
export function textDirection(tag: string): 'ltr' | 'rtl' {
    return isRtl(tag) ? 'rtl' : 'ltr'
}

/**
 * A language's name in the viewer's language (`de` shown in English is
 * "German", in Hebrew "גרמנית"), from the browser's own CLDR data: a
 * subtitle under the language's name in itself, so a script the viewer
 * cannot read is still recognisable. Null when the browser has no name
 * for it, or when it would repeat `name`.
 */
export function languageName(code: string, inLanguage: string, name?: string): string | null {
    try {
        const shown = new Intl.DisplayNames([inLanguage], { type: 'language' }).of(code)
        return shown && shown !== code && shown.toLocaleLowerCase(inLanguage) !== name?.toLocaleLowerCase(inLanguage) ? shown : null
    } catch {
        return null
    }
}

/** CLDR's plural categories, in the order a message lists its forms. */
const pluralOrder = ['zero', 'one', 'two', 'few', 'many', 'other'] as const

/** Whole counts the admin shows: every category they reach decides a form. */
const pluralSample = [...Array.from({ length: 1001 }, (_, n) => n), 10_000, 100_000]
const categoriesOf = new Map<string, string[]>()

/**
 * The plural forms a message has in `locale`, in order: the CLDR categories
 * whole counts reach there. English and German: one, other. Hebrew: one,
 * two, other. Russian, Polish, Ukrainian: one, few, many. Arabic: zero,
 * one, two, few, many, other. Chinese, Japanese, Korean: other — a single
 * form. A category only fractions or exact millions reach (French `many`
 * at 1 000 000) is not a form of its own; such a count takes `other`.
 */
export function pluralCategories(locale: string): string[] {
    let categories = categoriesOf.get(locale)
    if (!categories) {
        let rules: Intl.PluralRules
        try {
            rules = new Intl.PluralRules(locale)
        } catch {
            rules = new Intl.PluralRules('en')
        }
        const reached = new Set(pluralSample.map(n => rules.select(n)))
        categories = pluralOrder.filter(c => reached.has(c))
        categoriesOf.set(locale, categories)
    }
    return categories
}

/**
 * vue-i18n's plural rule for `locale`: which of a message's `a | b | …`
 * forms a count takes. A message with the locale's own forms is chosen by
 * the count's CLDR category; one with two forms, as an application's own
 * English-style message may have, is singular for 1 and plural otherwise;
 * one with a single form always takes it.
 */
export function pluralRule(locale: string): (choice: number, choicesLength: number) => number {
    return (choice, choicesLength) => {
        const forms = pluralCategories(locale)
        if (choicesLength === forms.length) {
            let category: string
            try {
                category = new Intl.PluralRules(locale).select(choice)
            } catch {
                category = 'other'
            }
            const index = forms.indexOf(category)
            if (index >= 0) return index
            return forms.includes('other') ? forms.indexOf('other') : choicesLength - 1
        }
        if (choicesLength === 2) return Math.abs(choice) === 1 ? 0 : 1
        return Math.min(Math.max(choicesLength - 1, 0), Math.abs(choice) === 1 ? 0 : choicesLength - 1)
    }
}
