import type { $Fetch } from 'nitropack'

/**
 * The one HTTP client every Vector request goes through. Bearer mode
 * attaches the in-memory token and sends the user to the login page on
 * a 401; cookie mode (hosted) rides the application's session cookie,
 * sends the CSRF token on every unsafe method, and hands a 401 to the
 * application's own login URL.
 */
export function useVectorApi(): $Fetch {
    const config = useRuntimeConfig()
    const appConfig = useAppConfig()
    const runtime = useVectorRuntime()
    const auth = useVectorAuthStore()

    return $fetch.create({
        baseURL: runtime.hosted ? (runtime.apiBase ?? '') : config.public.vector.apiBase,
        credentials: runtime.hosted ? 'same-origin' : 'omit',
        onRequest({ options }) {
            if (runtime.hosted) {
                const method = String(options.method ?? 'GET').toUpperCase()

                if (runtime.csrfToken && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
                    options.headers.set('X-CSRF-Token', runtime.csrfToken)
                }
            } else if (auth.token) {
                options.headers.set('Authorization', `Bearer ${auth.token}`)
            }
        },
        async onResponseError({ response }) {
            if (response.status === 401) {
                if (runtime.hosted && runtime.loginUrl && import.meta.client) {
                    window.location.assign(runtime.loginUrl)

                    return
                }

                auth.clear()
                await navigateTo(`${appConfig.vector.basePath || ''}/login`)
            }
        },
    }) as $Fetch
}

/** The message a failed request should show, from either Kinetis error shape. */
export function vectorErrorMessage(error: unknown): string {
    const failure = error as { data?: { error?: string, detail?: string }, message?: string } | null
    const data = failure?.data

    if (data && typeof data === 'object') {
        if (typeof data.error === 'string') return data.error
        if (typeof data.detail === 'string') return data.detail
    }

    return typeof failure?.message === 'string' ? failure.message : String(error)
}
