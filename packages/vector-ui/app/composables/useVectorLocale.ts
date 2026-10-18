import type { VectorLocale, VectorSchemaI18n } from '#vector/app/types/vector'
import { isRtl, vuetifyLocale } from '#vector/app/i18n'

// The latest language the user picked: an earlier pick still in flight
// must not apply its answer over it. The UI runs in the browser only, one
// app per page, so one counter per module is one per session.
let latestChoice = 0

/**
 * The admin's language, per user. The descriptor says which language this
 * user is shown and which ones the administrator lets them choose from;
 * `choose()` stores the choice as the user's `locale` preference and
 * reloads the descriptor, whose labels the server translates. Applying a
 * language switches the UI's strings, Vuetify's, the formatting of dates,
 * numbers and money, and the page's direction (right to left for Hebrew).
 */
export function useVectorLocale() {
    const nuxtApp = useNuxtApp()
    const locales = useState<VectorLocale[]>('vector.locales', () => [])
    const current = computed(() => String(nuxtApp.$vectorI18n.global.locale.value))

    function apply(code: string): void {
        nuxtApp.$vectorI18n.global.locale.value = code
        setFormatLocale(code)

        const vuetify = nuxtApp.$vuetify as { locale?: { current: { value: string }, messages: { value: Record<string, unknown> } } } | undefined
        if (vuetify?.locale) {
            const tag = vuetifyLocale(code)
            const known = vuetify.locale.messages.value
            vuetify.locale.current.value = known[tag] ? tag : (known[code.split('-')[0]!] ? code.split('-')[0]! : 'en')
        }

        if (import.meta.client) {
            const known = locales.value.find(l => l.code === code)
            document.documentElement.lang = code
            document.documentElement.dir = known ? known.dir : (isRtl(code) ? 'rtl' : 'ltr')
            try {
                window.localStorage.setItem('vector.locale', code)
            } catch {
                // remembered for the next visit only when storage allows it
            }
        }
    }

    /** Takes what the descriptor says: the language to show and the ones on offer. */
    function adopt(i18n: VectorSchemaI18n | undefined): void {
        if (!i18n) return
        locales.value = i18n.locales
        if (i18n.locale !== current.value) apply(i18n.locale)
    }

    /**
     * The user picks a language: remembered as their preference, then applied
     * — in that order, since applying it makes every page fetch its labels
     * again, and the server answers in the preference it has — and the
     * descriptor reloaded in it.
     */
    async function choose(code: string): Promise<void> {
        const choice = ++latestChoice
        await useVectorPreferences().put({ locale: code })
        if (choice !== latestChoice) return
        apply(code)
        const schema = await useVectorSchema().load(true)
        if (choice === latestChoice) adopt(schema.i18n)
    }

    const direction = computed<'ltr' | 'rtl'>(() => locales.value.find(l => l.code === current.value)?.dir ?? (isRtl(current.value) ? 'rtl' : 'ltr'))

    return { current, locales, direction, apply, adopt, choose }
}
