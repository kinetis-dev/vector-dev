/**
 * Impersonation from the UI. Bearer mode receives a new token and swaps
 * it in the auth store; cookie mode is told to reload, the session
 * having been swapped by the application.
 */
export function useVectorImpersonation() {
    const api = useVectorApi()
    const auth = useVectorAuthStore()
    const runtime = useVectorRuntime()
    const { load } = useVectorSchema()
    const impersonator = useState<{ id: number | string, name: string | null } | null>('vector.impersonator', () => null)

    async function apply(result: { accessToken?: string, expiresIn?: number, user?: { id: number | string, name: string }, reload?: boolean }) {
        if (result.reload && import.meta.client) {
            window.location.reload()

            return
        }

        if (result.accessToken && result.user) {
            const previous = { token: auth.token, expiresAt: auth.expiresAt, user: auth.user }
            auth.adopt(result.accessToken, result.expiresIn ?? 3600, { id: result.user.id, name: result.user.name })
            useVectorPreferences().clear()

            try {
                await load(true)
            } catch (error) {
                // the target cannot use the admin at all: undo the swap on both sides and say so
                const status = (error as { status?: number, statusCode?: number } | null)?.status ?? (error as { statusCode?: number } | null)?.statusCode

                if (status === 403) {
                    await api('/vector/impersonate', { method: 'DELETE' }).catch(() => undefined)
                    if (previous.token && previous.user) auth.adopt(previous.token, Math.max(1, Math.round((previous.expiresAt - Date.now()) / 1000)), previous.user)
                    await load(true)
                    throw new Error(vt('shell.impersonation.noAccess', { name: result.user.name }))
                }

                throw error
            }
        }
    }

    async function start(userId: number | string) {
        const result = await api<{ accessToken?: string, expiresIn?: number, user?: { id: number | string, name: string }, reload?: boolean, impersonator: number | string }>(`/vector/impersonate/${encodeURIComponent(String(userId))}`, { method: 'POST' })
        await apply(result)
    }

    async function stop() {
        const result = await api<{ accessToken?: string, expiresIn?: number, user?: { id: number | string, name: string }, reload?: boolean }>('/vector/impersonate', { method: 'DELETE' })
        await apply(result)
    }

    /** Reads who is impersonating from /vector/me; null when nobody. */
    async function refresh() {
        const me = await api<{ impersonator: number | string | null }>('/vector/me')
        impersonator.value = me.impersonator === null ? null : { id: me.impersonator, name: runtime.user && 'impersonatorName' in runtime.user ? String((runtime.user as { impersonatorName?: string }).impersonatorName ?? '') : null }
    }

    return { impersonator, start, stop, refresh }
}
