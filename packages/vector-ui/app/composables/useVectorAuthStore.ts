import { defineStore } from 'pinia'

export interface VectorUser {
    id: number | string
    name: string
    email?: string
}

interface TokenResponse {
    accessToken: string
    tokenType: string
    expiresIn: number
    /** Present when the application issues refresh tokens (POST /auth/refresh renews both, once). */
    refreshToken?: string
    refreshExpiresIn?: number
    user: VectorUser
}

interface Saved {
    token: string
    expiresAt: number
    user: VectorUser
    refreshToken?: string | null
    refreshExpiresAt?: number
    refreshSubject?: string | null
}

const STORAGE_KEY = 'vector.session'
/** An access token with less than this left is renewed before it is used. */
export const REFRESH_MARGIN_MS = 120_000

// The pending end (or renewal) of the session, and a renewal in flight. The
// UI runs in the browser only (ssr: false), one app per page, so one of
// each per module is one per session.
let expiryTimer: ReturnType<typeof setTimeout> | null = null
let refreshing: Promise<boolean> | null = null
// setTimeout's longest delay; a later expiry is re-checked when it fires
const MAX_DELAY = 2 ** 31 - 1

/**
 * Bearer-mode session. The access token lives in memory for the tab and
 * is mirrored to sessionStorage — never localStorage — beside the refresh
 * token when the application issues one (`/auth/login` answering
 * `refreshToken`). The session renews itself through `POST /auth/refresh`
 * when the access token has under two minutes left, and once more on a
 * 401; a failed renewal signs the user out. Logout revokes the refresh
 * token (`POST /auth/logout`). An impersonation token is never renewed:
 * the refresh token is the signed-in user's own, used only while the
 * session is theirs. Hosted mode rides the application's session instead.
 */
export const useVectorAuthStore = defineStore('vectorAuth', {
    state: () => ({
        token: null as string | null,
        expiresAt: 0,
        user: null as VectorUser | null,
        refreshToken: null as string | null,
        refreshExpiresAt: 0,
        /** Whose refresh token it is: the user who signed in. */
        refreshSubject: null as string | null,
        restored: false,
        hosted: false,
    }),

    getters: {
        /** Whether the session can renew itself: a live refresh token, and the session is its owner's. */
        canRefresh: state => state.refreshToken !== null
            && state.refreshExpiresAt > Date.now()
            && state.user !== null
            && String(state.user.id) === state.refreshSubject,
        // A getter is cached until the state changes, and the clock is not
        // state: the session's own timer (expireAt) renews or clears the
        // token when it runs out, which is the change that updates this.
        isAuthenticated(state): boolean {
            return state.hosted || (state.token !== null && state.expiresAt > Date.now()) || this.canRefresh
        },
    },

    actions: {
        restore() {
            if (this.restored) {
                return
            }

            this.restored = true
            const runtime = useVectorRuntime()

            if (runtime.hosted) {
                this.hosted = true
                this.user = runtime.user ? { id: runtime.user.id, name: runtime.user.name ?? vt('shell.account') } : null

                return
            }

            try {
                const raw = sessionStorage.getItem(STORAGE_KEY)

                if (raw) {
                    const saved = JSON.parse(raw) as Saved
                    const refreshable = !!saved.refreshToken && (saved.refreshExpiresAt ?? 0) > Date.now()

                    if (saved.expiresAt > Date.now() || refreshable) {
                        this.token = saved.token
                        this.expiresAt = saved.expiresAt
                        this.user = saved.user
                        this.refreshToken = refreshable ? saved.refreshToken! : null
                        this.refreshExpiresAt = refreshable ? saved.refreshExpiresAt! : 0
                        this.refreshSubject = refreshable ? saved.refreshSubject ?? null : null
                        this.expireAt(saved.expiresAt)
                    }
                }
            } catch {
                // Storage unavailable or unreadable: the user simply logs in again.
            }
        },

        async login(email: string, password: string) {
            const config = useRuntimeConfig()
            const response = await $fetch<TokenResponse>('/auth/login', {
                baseURL: config.public.vector.apiBase,
                method: 'POST',
                body: { email, password },
            })

            this.take(response)
        },

        /**
         * Renews both tokens, once at a time however many callers ask.
         * False, and the session ended, when the refresh token is refused.
         */
        refresh(): Promise<boolean> {
            if (!this.canRefresh) return Promise.resolve(false)

            refreshing ??= (async () => {
                try {
                    const config = useRuntimeConfig()
                    const response = await $fetch<TokenResponse>('/auth/refresh', {
                        baseURL: config.public.vector.apiBase,
                        method: 'POST',
                        body: { refreshToken: this.refreshToken },
                    })
                    this.take(response)

                    return true
                } catch {
                    this.clear()

                    return false
                } finally {
                    refreshing = null
                }
            })()

            return refreshing
        },

        /** A token fit to send: renewed first when it has under two minutes left and the session can renew. */
        async fresh(): Promise<string | null> {
            if (this.token !== null && this.expiresAt - Date.now() < REFRESH_MARGIN_MS && this.canRefresh) {
                await this.refresh()
            }

            return this.token
        },

        /** Takes a token the server issued outside the login form: impersonation. It is not renewed. */
        adopt(token: string, expiresIn: number, user: VectorUser) {
            this.token = token
            this.expiresAt = Date.now() + expiresIn * 1000
            this.user = user
            this.expireAt(this.expiresAt)
            this.save()
        },

        /** Signs out: the refresh token is revoked where the application issued it, then the session is forgotten. */
        async logout() {
            const refreshToken = this.refreshToken

            if (refreshToken) {
                const config = useRuntimeConfig()
                await $fetch('/auth/logout', { baseURL: config.public.vector.apiBase, method: 'POST', body: { refreshToken } }).catch(() => undefined)
            }

            this.clear()
        },

        /** Renews the session shortly before its token runs out, or ends it when it cannot. */
        expireAt(expiresAt: number) {
            if (expiryTimer !== null) clearTimeout(expiryTimer)

            const due = this.canRefresh ? expiresAt - REFRESH_MARGIN_MS : expiresAt

            expiryTimer = setTimeout(() => {
                expiryTimer = null

                if (this.expiresAt !== expiresAt) return // a newer token has its own timer
                if (Date.now() < due) this.expireAt(expiresAt) // past setTimeout's range: wait again
                else if (this.canRefresh) void this.refresh()
                else this.clear()
            }, Math.min(MAX_DELAY, Math.max(0, due - Date.now())))
        },

        clear() {
            if (expiryTimer !== null) {
                clearTimeout(expiryTimer)
                expiryTimer = null
            }

            this.token = null
            this.expiresAt = 0
            this.user = null
            this.refreshToken = null
            this.refreshExpiresAt = 0
            this.refreshSubject = null

            try {
                sessionStorage.removeItem(STORAGE_KEY)
            } catch {
                // Nothing to remove.
            }
        },

        /** A login or refresh answer: the tokens, the user, and the timer that renews them. */
        take(response: TokenResponse) {
            this.token = response.accessToken
            this.expiresAt = Date.now() + response.expiresIn * 1000
            this.user = response.user

            if (response.refreshToken) {
                this.refreshToken = response.refreshToken
                this.refreshExpiresAt = Date.now() + (response.refreshExpiresIn ?? 0) * 1000
                this.refreshSubject = String(response.user.id)
            } else {
                this.refreshToken = null
                this.refreshExpiresAt = 0
                this.refreshSubject = null
            }

            this.expireAt(this.expiresAt)
            this.save()
        },

        save() {
            try {
                const saved: Saved = { token: this.token!, expiresAt: this.expiresAt, user: this.user!, refreshToken: this.refreshToken, refreshExpiresAt: this.refreshExpiresAt, refreshSubject: this.refreshSubject }
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify(saved))
            } catch {
                // Fine without the mirror.
            }
        },
    },
})
