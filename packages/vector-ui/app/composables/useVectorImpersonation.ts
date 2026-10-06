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

    type Swap = { accessToken?: string, expiresIn?: number, user?: { id: number | string, name: string }, target?: { id: number | string, name: string }, reload?: boolean }

    async function apply(result: Swap) {
        if (result.reload && import.meta.client) {
            window.location.reload()

            return
        }

        if (!result.accessToken) {
            throw new Error(vt('shell.impersonation.noToken'))
        }

        // who the token is for: the application's `user`, the controller's `target`, or the token's own subject
        const subject = result.user ?? result.target ?? { id: subjectOf(result.accessToken) ?? '', name: auth.user?.name ?? '' }

        {
            const previous = { token: auth.token, expiresAt: auth.expiresAt, user: auth.user }
            auth.adopt(result.accessToken, result.expiresIn ?? 3600, { id: subject.id, name: subject.name })
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
                    throw new Error(vt('shell.impersonation.noAccess', { name: subject.name }), { cause: error })
                }

                throw error
            }
        }
    }

    /** The `sub` of a JWT, read without verifying it: the server already did, and this only labels the session. */
    function subjectOf(token: string): string | null {
        try {
            const payload = JSON.parse(atob(token.split('.')[1]?.replace(/-/g, '+').replace(/_/g, '/') ?? '')) as { sub?: unknown }

            return typeof payload.sub === 'string' || typeof payload.sub === 'number' ? String(payload.sub) : null
        } catch {
            return null
        }
    }

    async function start(userId: number | string) {
        await apply(await api<Swap>(`/vector/impersonate/${encodeURIComponent(String(userId))}`, { method: 'POST' }))
    }

    async function stop() {
        await apply(await api<Swap>('/vector/impersonate', { method: 'DELETE' }))
    }

    /** Reads who is impersonating from /vector/me; null when nobody. */
    async function refresh() {
        const me = await api<{ impersonator: number | string | null }>('/vector/me')
        impersonator.value = me.impersonator === null ? null : { id: me.impersonator, name: runtime.user && 'impersonatorName' in runtime.user ? String((runtime.user as { impersonatorName?: string }).impersonatorName ?? '') : null }
    }

    return { impersonator, start, stop, refresh }
}
