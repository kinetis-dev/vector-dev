import { registerEndpoint } from '@nuxt/test-utils/runtime'

/** What a registered endpoint saw of one request. */
export interface Received {
    method: string
    path: string
    headers: Headers
    body: unknown
}

type Reply = { status?: number, body?: unknown } | ((request: Received) => { status?: number, body?: unknown })

interface H3Event {
    method: string
    path: string
    headers: Headers
    node: { req: { body?: string | Uint8Array | FormData | null }, res: { statusCode: number } }
}

/**
 * Registers a mock of one API endpoint and records every request it
 * answers. The test environment routes only relative URLs to registered
 * endpoints, so a test that uses this sets the API base to ''
 * (see `standaloneApi`).
 */
export function endpoint(url: string, reply: Reply = {}, method?: string) {
    const requests: Received[] = []

    const handler = async (event: H3Event) => {
        // the environment's mock request holds the whole body; it is not a readable stream
        const body = event.node.req.body
        // a multipart body arrives as the FormData itself: its fields, a file by its name
        const form = body instanceof FormData ? Object.fromEntries([...body.entries()].map(([k, v]) => [k, typeof v === 'string' ? v : v.name])) : null
        const raw = body === null || body === undefined || body instanceof FormData ? '' : typeof body === 'string' ? body : new TextDecoder().decode(body)

        const request: Received = {
            method: event.method,
            path: event.path.replace(/^\/_/, ''), // the environment mounts registered endpoints under /_
            headers: event.headers,
            body: form ?? (raw === '' ? undefined : JSON.parse(raw)),
        }
        requests.push(request)

        const answer = typeof reply === 'function' ? reply(request) : reply
        event.node.res.statusCode = answer.status ?? 200

        return answer.body ?? {}
    }

    const unregister = registerEndpoint(url, method ? { handler: handler as never, method: method as never } : handler as never)

    return { requests, unregister }
}

/** Points the standalone client at the test environment's own endpoint registry. */
export function standaloneApi() {
    useRuntimeConfig().public.vector.apiBase = ''
    delete window.__VECTOR__
}
