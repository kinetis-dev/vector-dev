// Where a dismissal is remembered: this browser session only, and only for
// the pair of versions it was dismissed for.
const DISMISSED = 'vector.uiVersion.dismissed'

/** The npm package this UI is, as kinetis/vector's resources/ui.json pins it. */
export const UI_PACKAGE = '@kinetis/vector-ui'

/**
 * What to do about a UI that is not the one kinetis/vector expects, in
 * the words of how it is deployed: a hosted bundle (`window.__VECTOR__`
 * present) is reinstalled on the server, a layer is upgraded and rebuilt.
 */
export function vectorUiRemedy(expected: string | null): string {
    if (useVectorRuntime().hosted) {
        return vt('shell.version.hostedRemedy')
    }

    return expected === null
        ? vt('shell.version.layeredRemedyAny', { package: UI_PACKAGE })
        : vt('shell.version.layeredRemedy', { package: UI_PACKAGE, version: expected })
}

/**
 * This UI's own release — the layer's package.json version, baked into
 * the build — against the one the descriptor says kinetis/vector pins.
 * A mismatch is a warning, not a stop: the descriptor contract is what
 * must match (useVectorSchema refuses another), the release only should.
 * No pin on the server is no expectation, and no warning.
 */
export function useVectorVersion() {
    const { schema } = useVectorSchema()
    const current = String(useRuntimeConfig().public.vector.uiVersion ?? '')
    const expected = computed(() => schema.value?.ui?.expected ?? null)
    const pair = computed(() => `${current}→${expected.value}`)
    const dismissed = useState<string | null>(DISMISSED, () => import.meta.client ? sessionStorage.getItem(DISMISSED) : null)

    const mismatch = computed(() => expected.value !== null && expected.value !== current)
    const visible = computed(() => mismatch.value && dismissed.value !== pair.value)
    const remedy = computed(() => vectorUiRemedy(expected.value))

    function dismiss() {
        dismissed.value = pair.value

        if (import.meta.client) {
            sessionStorage.setItem(DISMISSED, pair.value)
        }
    }

    return { current, expected, mismatch, visible, remedy, dismiss }
}
