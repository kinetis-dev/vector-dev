import { createI18n } from 'vue-i18n'
import en from '#vector/app/i18n/en'

/**
 * The admin's own strings, translated through vue-i18n (the descriptor's
 * labels are the application's and arrive already in its language).
 *
 * The locale is the hosted shell's (`VECTOR_UI_LOCALE`), else app config
 * `vector.locale`, else English; an unknown one falls back to English
 * string by string. An application adds or overrides messages in app
 * config `vector.messages` ({ de: { common: { save: 'Speichern' } } }),
 * and a runtime extension through `window.Vector.defineMessages()`.
 * Vuetify's own component strings follow the same locale.
 */
export default defineNuxtPlugin({
    name: 'vector-i18n',
    enforce: 'pre',
    setup(nuxtApp) {
        const appConfig = useAppConfig()
        const hosted = import.meta.client ? window.__VECTOR__?.locale : undefined
        const locale = hosted || appConfig.vector.locale || 'en'

        const i18n = createI18n({
            legacy: false,
            locale,
            fallbackLocale: 'en',
            messages: { en },
            missingWarn: false,
            fallbackWarn: false,
        })

        for (const [code, messages] of Object.entries(appConfig.vector.messages ?? {})) {
            i18n.global.mergeLocaleMessage(code, messages as Record<string, unknown>)
        }

        nuxtApp.vueApp.use(i18n)
        setVectorI18n(i18n as Parameters<typeof setVectorI18n>[0])

        // Vuetify's component strings (pagination, "No data available", …) in the same locale.
        nuxtApp.hook('vuetify:before-create', async ({ vuetifyOptions }) => {
            if (locale === 'en') return
            const all = await import('vuetify/locale') as Record<string, unknown>
            const code = locale.replace(/-([a-z])/gi, (_, c: string) => c.toUpperCase())
            const messages = all[code] ?? all[locale.split('-')[0]!]
            if (!messages) return
            vuetifyOptions.locale = { ...(typeof vuetifyOptions.locale === 'object' ? vuetifyOptions.locale : {}), locale: code, fallback: 'en', messages: { [code]: messages as never } }
        })

        return { provide: { vectorI18n: i18n } }
    },
})
