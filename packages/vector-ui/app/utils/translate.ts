import type { I18n } from 'vue-i18n'

// The admin's translator, set once by the vector-i18n plugin. The UI runs in
// the browser only (ssr: false), one app per page, so a module-level instance
// is safe and lets utilities, stores and async handlers translate outside a
// component's setup.
let instance: I18n<Record<string, unknown>, Record<string, unknown>, Record<string, unknown>, string, false> | null = null

export function setVectorI18n(i18n: NonNullable<typeof instance>): void {
    instance = i18n
}

/**
 * Translates one of the admin's own strings: `vt('list.selected', { count }, count)`.
 * `{name}` interpolates; `a | b | c` pluralizes on the count passed last.
 * Before the plugin runs (never in practice) the key itself comes back.
 */
export function vt(key: string, params: Record<string, unknown> = {}, plural?: number): string {
    if (!instance) return key
    return plural === undefined ? instance.global.t(key, params) : instance.global.t(key, params, plural)
}

/**
 * A resource label as it reads inside a sentence (`Create product`,
 * `Delete 3 products?`): lower-cased in the current language, except
 * where nouns keep their capital — German writes every noun with one.
 */
export function vnoun(label: string): string {
    const locale = instance ? String(instance.global.locale.value) : 'en'
    return locale === 'de' || locale.startsWith('de-') ? label : label.toLocaleLowerCase(locale)
}
