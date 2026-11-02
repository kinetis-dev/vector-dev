import type { VectorResource, VectorSchema } from '#vector/app/types/vector'

// The latest descriptor request: an older answer arriving after it (the
// language switched twice, say) must not replace it. One app per page.
let latestLoad = 0

/** The descriptor contract this UI reads: the `version` kinetis/vector publishes (VectorRegistry::VERSION). */
export const DESCRIPTOR_VERSION = 4

/**
 * The descriptor, loaded once per session and shared by every page. The
 * server's ETag makes a reload cheap; the layer reloads it after login
 * because the permission overlay is per identity. A descriptor of another
 * contract version is refused rather than read as this one.
 */
export function useVectorSchema() {
    const schema = useState<VectorSchema | null>('vector.schema', () => null)
    const loading = useState<boolean>('vector.schema.loading', () => false)
    // whether the latest load failed (network, 5xx, CORS, a refusal, another contract version): what
    // waits on the descriptor, such as the extension loader's ready, settles on it instead of hanging
    const failed = useState<boolean>('vector.schema.failed', () => false)
    const api = useVectorApi()

    async function load(force = false): Promise<VectorSchema> {
        if (schema.value && !force) {
            adoptMinorUnits(schema.value.minorUnits ?? {})
            return schema.value
        }

        loading.value = true
        const request = ++latestLoad
        // read before the await: the refusal below must not need the app context
        const { hosted } = useVectorRuntime()

        try {
            const answer = await api<VectorSchema>('/vector/schema')

            if (answer?.version !== DESCRIPTOR_VERSION) {
                // a hard stop: the remedy is the drift banner's, for how this UI is deployed
                throw new Error(vt('shell.unsupportedDescriptor', { version: String(answer?.version), supported: DESCRIPTOR_VERSION, remedy: vectorUiRemedy(answer?.ui?.expected ?? null, hosted) }))
            }

            if (request === latestLoad) {
                schema.value = answer
                failed.value = false
                adoptMinorUnits(answer.minorUnits ?? {})
            }

            return answer
        } catch (error) {
            if (request === latestLoad) failed.value = true
            throw error
        } finally {
            if (request === latestLoad) loading.value = false
        }
    }

    function clear() {
        schema.value = null
        failed.value = false
    }

    function resource(slug: string): VectorResource | undefined {
        return schema.value?.resources[slug]
    }

    const menu = computed(() => (schema.value?.menu ?? []).map(group => ({
        label: group.label,
        items: group.resources
            .map(slug => schema.value!.resources[slug])
            .filter((resource): resource is VectorResource => resource !== undefined),
    })))

    return { schema, loading, failed, load, clear, resource, menu }
}
