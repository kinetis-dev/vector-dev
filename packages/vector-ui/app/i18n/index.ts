import de from './de'
import en from './en'
import es from './es'
import fr from './fr'
import he from './he'
import it from './it'
import nl from './nl'
import pt from './pt'

/**
 * Every language the UI ships. English is the base: each other locale
 * mirrors its keys (a test holds them to it), and a key a locale lacks
 * falls back to English. All but English are machine translations.
 */
export const bundledMessages: Record<string, typeof en> = { en, de, fr, nl, it, es, pt, he }

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
