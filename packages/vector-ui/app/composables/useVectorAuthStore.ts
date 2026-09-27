import { defineStore } from 'pinia'

export interface VectorUser {
    id: number | string
    name: string
    email?: string
}

interface LoginResponse {
    accessToken: string
    tokenType: string
    expiresIn: number
    user: VectorUser
}

const STORAGE_KEY = 'vector.session'

// The pending end of the session. The UI runs in the browser only
// (ssr: false), one app per page, so one timer per module is one per session.
let expiryTimer: ReturnType<typeof setTimeout> | null = null
// setTimeout's longest delay; a later expiry is re-checked when it fires
const MAX_DELAY = 2 ** 31 - 1

/**
 * Bearer-mode session. The access token lives in memory for the tab and
 * is mirrored to sessionStorage only so a reload during development does
 * not sign the user out; the refresh-token flow of Phase 3 replaces that
 * mirror. Never localStorage.
 */
export const useVectorAuthStore = defineStore('vectorAuth', {
    state: () => ({
        token: null as string | null,
        expiresAt: 0,
        user: null as VectorUser | null,
        restored: false,
        hosted: false,
    }),

    getters: {
        // A getter is cached until the state changes, and the clock is not
        // state: the session's own timer (expireAt) clears the token when it
        // runs out, which is the change that brings this to false.
        isAuthenticated: state => state.hosted || (state.token !== null && state.expiresAt > Date.now()),
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
                    const saved = JSON.parse(raw) as { token: string, expiresAt: number, user: VectorUser }

                    if (saved.expiresAt > Date.now()) {
                        this.token = saved.token
                        this.expiresAt = saved.expiresAt
                        this.user = saved.user
                        this.expireAt(saved.expiresAt)
                    }
                }
            } catch {
                // Storage unavailable or unreadable: the user simply logs in again.
            }
        },

        async login(email: string, password: string) {
            const config = useRuntimeConfig()
            const response = await $fetch<LoginResponse>('/auth/login', {
                baseURL: config.public.vector.apiBase,
                method: 'POST',
                body: { email, password },
            })

            this.token = response.accessToken
            this.expiresAt = Date.now() + response.expiresIn * 1000
            this.user = response.user
            this.expireAt(this.expiresAt)

            try {
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ token: this.token, expiresAt: this.expiresAt, user: this.user }))
            } catch {
                // Fine without the mirror.
            }
        },

        /** Takes a token the server issued outside the login form: impersonation. */
        adopt(token: string, expiresIn: number, user: VectorUser) {
            this.token = token
            this.expiresAt = Date.now() + expiresIn * 1000
            this.user = user
            this.expireAt(this.expiresAt)

            try {
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ token: this.token, expiresAt: this.expiresAt, user: this.user }))
            } catch {
                // Fine without the mirror.
            }
        },

        /** Ends the session when its token does: the next navigation goes to the login page. */
        expireAt(expiresAt: number) {
            if (expiryTimer !== null) clearTimeout(expiryTimer)

            expiryTimer = setTimeout(() => {
                expiryTimer = null

                if (this.expiresAt !== expiresAt) return // a newer token has its own timer
                if (Date.now() < expiresAt) this.expireAt(expiresAt) // past setTimeout's range: wait again
                else this.clear()
            }, Math.min(MAX_DELAY, Math.max(0, expiresAt - Date.now())))
        },

        clear() {
            if (expiryTimer !== null) {
                clearTimeout(expiryTimer)
                expiryTimer = null
            }

            this.token = null
            this.expiresAt = 0
            this.user = null

            try {
                sessionStorage.removeItem(STORAGE_KEY)
            } catch {
                // Nothing to remove.
            }
        },
    },
})
