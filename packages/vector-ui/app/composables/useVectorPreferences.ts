/**
 * Per-user UI preferences, loaded once per session and written through
 * as they change. Column state lives under `columns.<resource>`.
 */
export function useVectorPreferences() {
    const api = useVectorApi()
    const preferences = useState<Record<string, unknown> | null>('vector.preferences', () => null)

    async function load(): Promise<Record<string, unknown>> {
        if (preferences.value === null) {
            preferences.value = (await api<{ data: Record<string, unknown> }>('/vector/preferences')).data
        }

        return preferences.value
    }

    function get<T>(key: string): T | undefined {
        return preferences.value?.[key] as T | undefined
    }

    async function put(values: Record<string, unknown>): Promise<void> {
        preferences.value = (await api<{ data: Record<string, unknown> }>('/vector/preferences', { method: 'PUT', body: values })).data
    }

    function clear() {
        preferences.value = null
    }

    return { preferences, load, get, put, clear }
}
