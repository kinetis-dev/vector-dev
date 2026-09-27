import type { VectorJob } from '#vector/app/types/vector'

/** Background jobs: list, poll one until it settles, download its file. */
export function useVectorJobs() {
    const api = useVectorApi()

    async function list(): Promise<VectorJob[]> {
        return (await api<{ data: VectorJob[] }>('/vector/jobs')).data
    }

    async function show(id: number): Promise<VectorJob> {
        return (await api<{ data: VectorJob }>(`/vector/jobs/${id}`)).data
    }

    /**
     * Polls every second (backing off to five) until the job is done or
     * failed, reporting each state; an aborted signal ends the poll with
     * the last state seen, so a page that goes away stops asking.
     */
    async function watch(id: number, onUpdate?: (job: VectorJob) => void, signal?: AbortSignal): Promise<VectorJob> {
        let delay = 1000

        for (;;) {
            const job = await show(id)
            onUpdate?.(job)

            if (job.status === 'done' || job.status === 'failed' || signal?.aborted) {
                return job
            }

            await new Promise<void>((resolve) => {
                const timer = setTimeout(resolve, delay)
                signal?.addEventListener('abort', () => {
                    clearTimeout(timer)
                    resolve()
                }, { once: true })
            })

            if (signal?.aborted) {
                return job
            }

            delay = Math.min(5000, delay + 500)
        }
    }

    async function download(job: VectorJob): Promise<void> {
        if (!import.meta.client) return

        const response = await asFile(() => api.raw(`/vector/jobs/${job.id}/download`, { responseType: 'blob' }))
        const url = URL.createObjectURL(response._data as Blob)
        const link = document.createElement('a')
        link.href = url
        link.download = job.fileName ?? `job-${job.id}`
        document.body.appendChild(link)
        link.click()
        link.remove()
        setTimeout(() => URL.revokeObjectURL(url), 1000)
    }

    return { list, show, watch, download }
}
