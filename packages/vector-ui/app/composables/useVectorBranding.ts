import { useTheme } from 'vuetify'
import type { VectorBranding } from '#vector/app/types/vector'

/**
 * What the shell shows about the application: the Branding settings
 * group, published with the descriptor (and, hosted, injected before
 * the first paint). Falls back to app.config while nothing loaded.
 */
export function useVectorBranding() {
    const appConfig = useAppConfig()
    const { schema } = useVectorSchema()
    const theme = useTheme()
    const applied = useState<string>('vector.branding.applied', () => '')

    const hosted = import.meta.client ? window.__VECTOR__?.branding ?? null : null

    const branding = computed<VectorBranding>(() => ({
        title: appConfig.vector.brand.title,
        logoUrl: null,
        primaryColor: null,
        primaryColorDark: null,
        theme: null,
        footerText: null,
        ...(schema.value?.branding ?? hosted),
    }))

    /** Pushes the colours into Vuetify's theme and picks the default theme once per session. */
    function apply() {
        const b = branding.value
        const signature = JSON.stringify(b)

        if (applied.value === signature) return

        if (b.primaryColor) theme.themes.value.light!.colors.primary = b.primaryColor
        if (b.primaryColorDark) theme.themes.value.dark!.colors.primary = b.primaryColorDark

        if (applied.value === '' && b.theme && b.theme !== 'system') {
            theme.change(b.theme)
        }

        applied.value = signature
    }

    return { branding, apply }
}
