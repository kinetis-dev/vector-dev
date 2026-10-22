import type { $Fetch } from 'nitropack'

/**
 * The one HTTP client every Vector request goes through. Bearer mode
 * attaches the in-memory token — renewed first when it has under two
 * minutes left — and on a 401 renews it once and repeats the request
 * (a 401 for a token another request already renewed only repeats it),
 * sending the user to the login page only when that fails too (a 401
 * means the request did nothing, so repeating even a POST is safe);
 * cookie mode (hosted) rides the application's session cookie,
 * sends the CSRF token on every unsafe method, and hands a 401 to the
 * application's own login URL.
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

                auth.clear()
                // back to this page after signing in again, as the route middleware would send a visitor
                const current = router.currentRoute.value.fullPath
                await navigateTo({ path: `${appConfig.vector.basePath || ''}/login`, query: current.startsWith(`${appConfig.vector.basePath || ''}/login`) ? {} : { redirect: current } })
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
