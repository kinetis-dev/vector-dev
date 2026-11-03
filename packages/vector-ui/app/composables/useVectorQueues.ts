import type {
    VectorFailedJob,
    VectorFailedJobFilters,
    VectorFailedJobPage,
    VectorQueue,
    VectorQueueMetrics,
    VectorQueuePermissions,
    VectorRetryResult,
} from '#vector/app/types/vector'

/**
 * The queue dashboard's API, under /vector/queues: the overview, the
 * permissions the policy grants, the failed jobs (where the descriptor's
 * `capabilities.failedJobs` says they are there) and the job metrics.
 * What the permissions disallow the UI leaves out, but that is cosmetic:
 * the server asks the policy on every call.
 */
export function useVectorQueues() {
    const api = useVectorApi()
    const path = (queue: string) => `/vector/queues/${encodeURIComponent(queue)}`

    async function overview(): Promise<VectorQueue[]> {
        return (await api<{ data: VectorQueue[] }>('/vector/queues')).data
    }

    async function permissions(): Promise<VectorQueuePermissions> {
        return await api<VectorQueuePermissions>('/vector/queues/permissions')
    }

    /** Discards every job waiting on `queue`; the body names it again, as the server requires. */
    async function clear(queue: string): Promise<number> {
        return (await api<{ data: { queue: string, cleared: number } }>(`${path(queue)}/clear`, { method: 'POST', body: { confirm: queue } })).data.cleared
    }

    /** One page, newest first: the first for `filters`, or the one after `cursor`. */
    async function failed(filters: VectorFailedJobFilters, cursor: string | null = null): Promise<VectorFailedJobPage> {
        const query = compactQuery({
            queue: filters.queue,
            class: filters.class.trim(),
            failedFrom: toRfc3339(filters.failedFrom),
            failedTo: toRfc3339(filters.failedTo),
            cursor,
        })
        return await api<VectorFailedJobPage>('/vector/queues/failed', { query })
    }

    async function failedJob(id: string): Promise<VectorFailedJob> {
        return (await api<{ data: VectorFailedJob }>(`/vector/queues/failed/${encodeURIComponent(id)}`)).data
    }

    /** Retries up to QUEUE_MAX_RETRY_BATCH jobs, one result each. */
    async function retry(ids: string[]): Promise<VectorRetryResult[]> {
        return (await api<{ data: VectorRetryResult[] }>('/vector/queues/failed/retry', { method: 'POST', body: { ids } })).data
    }

    async function forget(id: string): Promise<void> {
        await api(`/vector/queues/failed/${encodeURIComponent(id)}`, { method: 'DELETE' })
    }

    /** Deletes every failure record older than `days`, on every queue; how many it deleted. */
    async function prune(days: number): Promise<number> {
        return (await api<{ data: { deleted: number, olderThanSeconds: number } }>('/vector/queues/failed/prune', { method: 'POST', body: { days } })).data.deleted
    }

    /**
     * The metrics for the last `minutes` minutes on `queue` ('' for all):
     * both ends from this clock, so the window is exactly the one chosen
     * whatever the server's clock says.
     */
    async function metrics(queue: string, minutes: number): Promise<VectorQueueMetrics> {
        const to = Math.floor(Date.now() / 60000) * 60
        const from = to - (minutes - 1) * 60
        return (await api<{ data: VectorQueueMetrics }>('/vector/queues/metrics', { query: compactQuery({ queue, from, to }) })).data
    }

    return { overview, permissions, clear, failed, failedJob, retry, forget, prune, metrics }
}
