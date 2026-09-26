import type { VectorResource, VectorSchema } from '#vector/app/types/vector'

/**
 * The descriptor, loaded once per session and shared by every page. The
 * server's ETag makes a reload cheap; the layer reloads it after login
 * because the permission overlay is per identity.
 */
export function useVectorSchema() {
    const schema = useState<VectorSchema | null>('vector.schema', () => null)
    const loading = useState<boolean>('vector.schema.loading', () => false)
    const api = useVectorApi()

    async function load(force = false): Promise<VectorSchema> {
        if (schema.value && !force) {
            adoptMinorUnits(schema.value.minorUnits ?? {})
            return schema.value
        }

        loading.value = true

        try {
            schema.value = await api<VectorSchema>('/vector/schema')
            adoptMinorUnits(schema.value.minorUnits ?? {})

            return schema.value
        } finally {
            loading.value = false
        }
    }

    function clear() {
        schema.value = null
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

    return { schema, loading, load, clear, resource, menu }
}
