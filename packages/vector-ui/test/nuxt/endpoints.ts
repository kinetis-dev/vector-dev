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
    web: { request: Request }
    node: { res: { statusCode: number } }
}

/** A request's body as the tests assert it: a multipart form's fields (a file by its name), parsed JSON, or undefined when empty. */
async function readBody(request: Request): Promise<unknown> {
    if (request.headers.get('content-type')?.startsWith('multipart/form-data')) {
        const form = await request.formData()

        return Object.fromEntries([...form.entries()].map(([k, v]) => [k, typeof v === 'string' ? v : v.name]))
    }

    const raw = await request.text()

    return raw === '' ? undefined : JSON.parse(raw)
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
        const request: Received = {
            method: event.method,
            path: event.path.replace(/^\/_/, ''), // the environment mounts registered endpoints under /_
            headers: event.headers,
            // the environment hands registered endpoints the fetch's own web Request (h3's toWebHandler)
            body: await readBody(event.web.request),
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
