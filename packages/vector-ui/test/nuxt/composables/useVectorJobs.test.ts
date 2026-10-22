import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { endpoint, standaloneApi } from '../endpoints'
import type { VectorJob } from '#vector/app/types/vector'

// Following a job: polled until it settles, and stopped by the page's
// signal — which the poll leaves as it found it, however long it ran.

const job = (status: VectorJob['status']): VectorJob => ({
    id: 7, kind: 'export', resource: 'tags', name: 'Tags', actorId: '1', impersonatorId: null, status,
    total: 10, processed: status === 'done' ? 10 : 3, result: null, failure: null, hasFile: false, fileName: null,
    createdAt: '2026-10-21T08:00:00.000Z', updatedAt: '2026-10-21T08:00:00.000Z', finishedAt: null,
} as VectorJob)

let cleanup = () => {}

beforeEach(() => standaloneApi())
afterEach(() => cleanup())

describe('useVectorJobs().watch', () => {
    it('polls until the job settles, and leaves no listener on the signal', async () => {
        const answers: VectorJob['status'][] = ['running', 'done']
        const get = endpoint('/vector/jobs/7', () => ({ body: { data: job(answers.shift() ?? 'done') } }))
        cleanup = get.unregister
        const polling = new AbortController()
        const added = vi.spyOn(polling.signal, 'addEventListener')
        const removed = vi.spyOn(polling.signal, 'removeEventListener')
        const seen: string[] = []

        const settled = await useVectorJobs().watch(7, j => seen.push(j.status), polling.signal)

        expect(settled.status).toBe('done')
        expect(seen).toEqual(['running', 'done'])
        expect(get.requests).toHaveLength(2)
        expect(added).toHaveBeenCalledTimes(1)
        expect(removed.mock.calls.map(([type, listener]) => [type, listener])).toEqual(added.mock.calls.map(([type, listener]) => [type, listener]))
    })

    it('stops at once when the signal aborts during the wait', async () => {
        const get = endpoint('/vector/jobs/7', { body: { data: job('running') } })
        cleanup = get.unregister
        const polling = new AbortController()

        const watching = useVectorJobs().watch(7, undefined, polling.signal)
        await vi.waitFor(() => expect(get.requests).toHaveLength(1))
        polling.abort()

        await expect(watching).resolves.toMatchObject({ status: 'running' })
        expect(get.requests).toHaveLength(1)
    })
})
