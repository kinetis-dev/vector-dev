import type { $Fetch } from 'nitropack'

// The sign-out a 401 started, and the token whose session it ends: every
// request refused with that token (several waiting on one failed renewal)
// joins it rather than signing out again. One per module is one per session,
// the UI running in the browser only.
let ending: { token: string | null | undefined, done: Promise<void> } | null = null

/**
 * The one HTTP client every Vector request goes through. Bearer mode
 * attaches the in-memory token — renewed first when it has under two
 * minutes left — and on a 401 renews it once and repeats the request
 * (a 401 for a token another request already renewed only repeats it),
 * sending the user to the login page — once, however many requests
 * that renewal failed — only when that fails too (a 401 means the
 * request did nothing, so repeating even a POST is safe); cookie mode
 * (hosted) rides the application's session cookie, sends the CSRF token
 * on every unsafe method, and hands a 401 to the application's own login
 * URL.
 */
export function useVectorApi(): $Fetch {
    const config = useRuntimeConfig()
    const appConfig = useAppConfig()
    const runtime = useVectorRuntime()
    const auth = useVectorAuthStore()
    const router = useRouter()

    return $fetch.create({
        baseURL: runtime.hosted ? (runtime.apiBase ?? '') : config.public.vector.apiBase,
        credentials: runtime.hosted ? 'same-origin' : 'omit',
        async onRequest({ options }) {
            if (runtime.hosted) {
                const method = String(options.method ?? 'GET').toUpperCase()

                if (runtime.csrfToken && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
                    options.headers.set('X-CSRF-Token', runtime.csrfToken)
                }
            } else {
                const token = await auth.fresh()
                if (token !== null) ending = null // a new session: its own 401 signs out again
                Object.assign(options, { vectorToken: token }) // which token this request carried, for its 401
                if (token) options.headers.set('Authorization', `Bearer ${token}`)
            }
        },
        async onResponseError({ response, options }) {
            const once = options as typeof options & { vectorRenewed?: boolean, vectorToken?: string | null }
            // renewed since this request left (another request's 401 did it): repeat it, without renewing again
            const renewedSince = auth.token !== null && auth.token !== once.vectorToken

            if (response.status === 401 && !runtime.hosted && !once.vectorRenewed && (renewedSince || (auth.canRefresh && await auth.refresh()))) {
                // repeated once, with the renewed token (onRequest sets it)
                once.vectorRenewed = true
                options.retry = 1
                options.retryStatusCodes = [401]

                return
            }

            if (response.status === 401) {
                if (runtime.hosted && runtime.loginUrl && import.meta.client) {
                    window.location.assign(runtime.loginUrl)

                    return
                }

                if (ending !== null && ending.token === once.vectorToken && auth.token === null) {
                    await ending.done

                    return
                }

                auth.clear()
                // back to this page after signing in again, as the route middleware would send a visitor
                const current = router.currentRoute.value.fullPath
                const done = navigateTo({ path: `${appConfig.vector.basePath || ''}/login`, query: current.startsWith(`${appConfig.vector.basePath || ''}/login`) ? {} : { redirect: current } })
                ending = { token: once.vectorToken, done: Promise.resolve(done).then(() => undefined, () => undefined) }
                await done
            }
        },
    }) as $Fetch
}

/** The message a failed request should show, from either Kinetis error shape: `error`, or problem+json's `detail`, then its `title`. */
export function vectorErrorMessage(error: unknown): string {
    const failure = error as { data?: { error?: string, detail?: string, title?: string }, message?: string } | null
    const data = failure?.data

    if (data && typeof data === 'object') {
        if (typeof data.error === 'string') return data.error
        if (typeof data.detail === 'string') return data.detail
        if (typeof data.title === 'string') return data.title
    }

    return typeof failure?.message === 'string' ? failure.message : String(error)
}
