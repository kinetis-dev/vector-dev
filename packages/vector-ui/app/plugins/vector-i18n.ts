import { createI18n } from 'vue-i18n'
import { bundledMessages, isRtl, rtlLanguages, vuetifyLocale } from '#vector/app/i18n'

/**
 * The admin's own strings, translated through vue-i18n, in the language
 * the user chose. The descriptor's labels arrive translated by the server.
 *
 * The UI ships English and seven machine-translated locales. It starts in
 * the hosted shell's language (the server's answer for this user), else
 * the one this browser last used, else app config `vector.locale`, else
 * English; the descriptor then confirms it, and the language menu in the
 * app bar changes it (useVectorLocale). A key a locale lacks falls back to
 * English. An application adds or overrides messages in app config
 * `vector.messages` ({ de: { common: { save: 'Speichern' } } }), and a
 * runtime extension through `window.Vector.defineMessages()`. Vuetify's
 * own component strings and the page's direction follow the same locale.
 */
export default defineNuxtPlugin({
    name: 'vector-i18n',
    enforce: 'pre',
    setup(nuxtApp) {
        const appConfig = useAppConfig()
        const hosted = import.meta.client ? window.__VECTOR__?.locale : undefined
        let remembered: string | null = null
        try {
            remembered = import.meta.client ? window.localStorage.getItem('vector.locale') : null
        } catch {
            // storage may be unavailable (a private window, blocked site data)
        }
        const locale = hosted || remembered || appConfig.vector.locale || 'en'

        const i18n = createI18n({
            legacy: false,
            locale,
            fallbackLocale: 'en',
            messages: { ...bundledMessages },
            missingWarn: false,
            fallbackWarn: false,
        })

        for (const [code, messages] of Object.entries(appConfig.vector.messages ?? {})) {
            i18n.global.mergeLocaleMessage(code, messages as Record<string, unknown>)
        }

        nuxtApp.vueApp.use(i18n)
        setVectorI18n(i18n as Parameters<typeof setVectorI18n>[0])
        setFormatLocale(locale)

        if (import.meta.client) {
            document.documentElement.lang = locale
            document.documentElement.dir = isRtl(locale) ? 'rtl' : 'ltr'
        }

        // Vuetify's component strings (pagination, "No data available", …) for every language it knows, switched at runtime.
        nuxtApp.hook('vuetify:before-create', async ({ vuetifyOptions }) => {
            const all = await import('vuetify/locale') as Record<string, unknown>
            const messages = Object.fromEntries(Object.entries(all).filter(([, value]) => value && typeof value === 'object' && !Array.isArray(value)))
            const current = messages[vuetifyLocale(locale)] ? vuetifyLocale(locale) : (messages[locale.split('-')[0]!] ? locale.split('-')[0]! : 'en')
            vuetifyOptions.locale = {
                ...(typeof vuetifyOptions.locale === 'object' ? vuetifyOptions.locale : {}),
                locale: current,
                fallback: 'en',
                messages: messages as never,
                rtl: Object.fromEntries(rtlLanguages.map(code => [code, true])),
            }
        })

        return { provide: { vectorI18n: i18n } }
    },
})
