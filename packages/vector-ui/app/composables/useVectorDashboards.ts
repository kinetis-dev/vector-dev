import type { VectorDashboard, VectorWidgetData } from '#vector/app/types/vector'

export function useVectorDashboards() {
    const api = useVectorApi()

    async function show(name: string): Promise<VectorDashboard> {
        return (await api<{ data: VectorDashboard }>(`/vector/dashboards/${encodeURIComponent(name)}`)).data
    }

    async function widget(dashboard: string, name: string, range?: string): Promise<VectorWidgetData> {
        return (await api<{ data: VectorWidgetData }>(`/vector/dashboards/${encodeURIComponent(dashboard)}/widgets/${encodeURIComponent(name)}`, { query: range ? { range } : {} })).data
    }

    return { show, widget }
}
