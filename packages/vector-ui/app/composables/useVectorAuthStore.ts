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

            try {
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ token: this.token, expiresAt: this.expiresAt, user: this.user }))
            } catch {
                // Fine without the mirror.
            }
        },

        clear() {
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
