import type { VectorListState, VectorSavedView } from '#vector/app/types/vector'

/** Saved views of one resource's list. */
export function useVectorViews(slug: MaybeRefOrGetter<string>) {
    const api = useVectorApi()
    const base = () => `/vector/views/${encodeURIComponent(toValue(slug))}`

    async function list(): Promise<VectorSavedView[]> {
        return (await api<{ data: VectorSavedView[] }>(base())).data
    }

    async function create(name: string, state: VectorListState, shared: boolean, isDefault: boolean, resourceDefault = false): Promise<VectorSavedView> {
        return (await api<{ data: VectorSavedView }>(base(), { method: 'POST', body: { name, state, shared, isDefault, resourceDefault } })).data
    }

    async function update(id: number, name: string, state: VectorListState, shared: boolean, isDefault: boolean, resourceDefault = false): Promise<VectorSavedView> {
        return (await api<{ data: VectorSavedView }>(`${base()}/${id}`, { method: 'PUT', body: { name, state, shared, isDefault, resourceDefault } })).data
    }

    async function remove(id: number): Promise<void> {
        await api(`${base()}/${id}`, { method: 'DELETE' })
    }

    return { list, create, update, remove }
}
