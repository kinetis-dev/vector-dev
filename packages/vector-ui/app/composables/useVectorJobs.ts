import type { VectorJob } from '#vector/app/types/vector'

/** Background jobs: list, poll one until it settles, download its file. */
export function useVectorJobs() {
    const api = useVectorApi()
    const runtime = useVectorRuntime()
    const config = useRuntimeConfig()

    async function list(): Promise<VectorJob[]> {
        return (await api<{ data: VectorJob[] }>('/vector/jobs')).data
    }

    async function show(id: number): Promise<VectorJob> {
        return (await api<{ data: VectorJob }>(`/vector/jobs/${id}`)).data
    }

    /** Polls every second (backing off to five) until the job is done or failed, reporting each state. */
    async function watch(id: number, onUpdate?: (job: VectorJob) => void): Promise<VectorJob> {
        let delay = 1000

        for (;;) {
            const job = await show(id)
            onUpdate?.(job)

            if (job.status === 'done' || job.status === 'failed') {
                return job
            }

            await new Promise(resolve => setTimeout(resolve, delay))
            delay = Math.min(5000, delay + 500)
        }
    }

    async function download(job: VectorJob): Promise<void> {
        if (!import.meta.client) return

        const response = await api.raw(`/vector/jobs/${job.id}/download`, { responseType: 'blob' })
        const url = URL.createObjectURL(response._data as Blob)
        const link = document.createElement('a')
        link.href = url
        link.download = job.fileName ?? `job-${job.id}`
        document.body.appendChild(link)
        link.click()
        link.remove()
        setTimeout(() => URL.revokeObjectURL(url), 1000)
    }

    /** Where the API lives, for the rare absolute link. */
    const apiBase = runtime.hosted ? (runtime.apiBase ?? '') : config.public.vector.apiBase

    return { list, show, watch, download, apiBase }
}
