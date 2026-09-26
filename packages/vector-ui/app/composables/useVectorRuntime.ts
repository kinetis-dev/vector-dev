/**
 * How this UI was deployed. In hosted mode the PHP application serves the
 * shell page and injects `window.__VECTOR__` before the bundle loads:
 * same-origin API, the application's session cookie for authentication,
 * a CSRF token for writes, and where its login page lives. Standalone
 * mode has no such object and authenticates with a bearer token against
 * the API origin runtime config names.
 */
export interface VectorHostedConfig {
    apiBase: string
    basePath: string
    authMode: 'cookie'
    loginUrl: string
    logoutUrl: string
    csrfToken: string | null
    cspNonce: string | null
    user: { id: number | string, name: string | null } | null
    branding: import('#vector/app/types/vector').VectorBranding | null
    extensions: string[]
    /** The UI's language (VECTOR_UI_LOCALE), when the deployment names one. */
    locale?: string | null
}

declare global {
    interface Window {
        __VECTOR__?: VectorHostedConfig
    }
}

export function useVectorRuntime() {
    const hosted = import.meta.client ? window.__VECTOR__ ?? null : null

    return {
        hosted: hosted !== null,
        authMode: hosted ? 'cookie' as const : 'bearer' as const,
        apiBase: hosted?.apiBase ?? null,
        loginUrl: hosted?.loginUrl ?? null,
        logoutUrl: hosted?.logoutUrl ?? null,
        csrfToken: hosted?.csrfToken ?? null,
        user: hosted?.user ?? null,
    }
}
