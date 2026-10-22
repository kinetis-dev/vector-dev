import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { endpoint, standaloneApi } from '../endpoints'

const KEY = 'vector.session'

beforeEach(() => {
    // a fresh store per test: restore() runs once per store
    setActivePinia(createPinia())
    standaloneApi()
    sessionStorage.clear()
})

afterEach(() => {
    vi.useRealTimers()
    delete window.__VECTOR__
})

describe('useVectorAuthStore', () => {
    it('logs in against the API and mirrors the session to sessionStorage', async () => {
        const login = endpoint('/auth/login', { body: { accessToken: 'jwt', tokenType: 'Bearer', expiresIn: 900, user: { id: 1, name: 'Vector Admin', email: 'admin@vector.local' } } }, 'POST')
        const auth = useVectorAuthStore()

        await auth.login('admin@vector.local', 'password')

        expect(login.requests[0]!.body).toEqual({ email: 'admin@vector.local', password: 'password' })
        expect(auth.token).toBe('jwt')
        expect(auth.isAuthenticated).toBe(true)
        expect(auth.user?.name).toBe('Vector Admin')
        expect(JSON.parse(sessionStorage.getItem(KEY)!)).toMatchObject({ token: 'jwt', user: { id: 1 } })
        expect(localStorage.getItem(KEY)).toBeNull()

        login.unregister()
    })

    it('keeps nothing when the login is rejected', async () => {
        const login = endpoint('/auth/login', { status: 401, body: { error: 'Invalid credentials.' } }, 'POST')
        const auth = useVectorAuthStore()

        await expect(auth.login('admin@vector.local', 'wrong')).rejects.toMatchObject({ status: 401 })

        expect(auth.token).toBeNull()
        expect(auth.isAuthenticated).toBe(false)
        expect(sessionStorage.getItem(KEY)).toBeNull()

        login.unregister()
    })

    it('restores an unexpired session after a reload', () => {
        sessionStorage.setItem(KEY, JSON.stringify({ token: 'jwt', expiresAt: Date.now() + 60_000, user: { id: 1, name: 'Vector Admin' } }))
        const auth = useVectorAuthStore()

        auth.restore()

        expect(auth.token).toBe('jwt')
        expect(auth.isAuthenticated).toBe(true)
    })

    it('ignores an expired or unreadable session', () => {
        sessionStorage.setItem(KEY, JSON.stringify({ token: 'jwt', expiresAt: Date.now() - 1, user: { id: 1, name: 'Vector Admin' } }))
        useVectorAuthStore().restore()
        expect(useVectorAuthStore().isAuthenticated).toBe(false)

        setActivePinia(createPinia())
        sessionStorage.setItem(KEY, '{not json')
        useVectorAuthStore().restore()
        expect(useVectorAuthStore().isAuthenticated).toBe(false)
    })

    it('restores only once', () => {
        const auth = useVectorAuthStore()
        auth.restore()
        sessionStorage.setItem(KEY, JSON.stringify({ token: 'late', expiresAt: Date.now() + 60_000, user: { id: 1, name: 'x' } }))

        auth.restore()

        expect(auth.token).toBeNull()
    })

    // `isAuthenticated` is a cached getter and the clock is not state: once
    // read true, only the session's own expiry timer can turn it false.
    it('stops counting as signed in once the token expires', () => {
        vi.useFakeTimers()
        const auth = useVectorAuthStore()
        auth.adopt('jwt', 60, { id: 1, name: 'Vector Admin' })
        expect(auth.isAuthenticated).toBe(true)

        vi.advanceTimersByTime(61_000)

        expect(auth.isAuthenticated).toBe(false)
        expect(auth.token).toBeNull()
        expect(sessionStorage.getItem(KEY)).toBeNull()
    })

    it('expires a restored session at its own time', () => {
        vi.useFakeTimers()
        sessionStorage.setItem(KEY, JSON.stringify({ token: 'jwt', expiresAt: Date.now() + 30_000, user: { id: 1, name: 'Vector Admin' } }))
        const auth = useVectorAuthStore()
        auth.restore()
        expect(auth.isAuthenticated).toBe(true)

        vi.advanceTimersByTime(31_000)

        expect(auth.isAuthenticated).toBe(false)
    })

    it('keeps a newer token past the older one\'s expiry', () => {
        vi.useFakeTimers()
        const auth = useVectorAuthStore()
        auth.adopt('first', 60, { id: 1, name: 'Vector Admin' })
        vi.advanceTimersByTime(30_000)
        auth.adopt('second', 3600, { id: 2, name: 'Demo Editor' }) // impersonation

        vi.advanceTimersByTime(60_000)

        expect(auth.isAuthenticated).toBe(true)
        expect(auth.token).toBe('second')
    })

    it('does not clear a later session when an ended one would have expired', () => {
        vi.useFakeTimers()
        const auth = useVectorAuthStore()
        auth.adopt('first', 60, { id: 1, name: 'Vector Admin' })
        auth.clear()
        auth.adopt('second', 3600, { id: 1, name: 'Vector Admin' })

        vi.advanceTimersByTime(120_000)

        expect(auth.token).toBe('second')
    })

    it('clears the token, the user and the mirror', () => {
        const auth = useVectorAuthStore()
        auth.adopt('jwt', 60, { id: 1, name: 'Vector Admin' })

        auth.clear()

        expect(auth.token).toBeNull()
        expect(auth.user).toBeNull()
        expect(sessionStorage.getItem(KEY)).toBeNull()
    })

    describe('refresh tokens', () => {
        const pair = (access: string, refresh: string) => ({ accessToken: access, tokenType: 'Bearer', expiresIn: 900, refreshToken: refresh, refreshExpiresIn: 43_200, user: { id: 1, name: 'Vector Admin' } })

        it('keeps the refresh token beside the access token, in sessionStorage only', async () => {
            const login = endpoint('/auth/login', { body: pair('jwt-1', 'r-1') }, 'POST')
            const auth = useVectorAuthStore()

            await auth.login('admin@vector.local', 'password')

            expect(auth.refreshToken).toBe('r-1')
            expect(auth.canRefresh).toBe(true)
            expect(JSON.parse(sessionStorage.getItem(KEY)!)).toMatchObject({ token: 'jwt-1', refreshToken: 'r-1', refreshSubject: '1' })
            expect(localStorage.getItem(KEY)).toBeNull()
            login.unregister()
        })

        it('renews a token with under two minutes left before it is used, once however many ask', async () => {
            const login = endpoint('/auth/login', { body: { ...pair('jwt-1', 'r-1'), expiresIn: 60 } }, 'POST')
            const refresh = endpoint('/auth/refresh', { body: pair('jwt-2', 'r-2') }, 'POST')
            const auth = useVectorAuthStore()
            await auth.login('admin@vector.local', 'password')

            const [a, b] = await Promise.all([auth.fresh(), auth.fresh()])

            expect([a, b]).toEqual(['jwt-2', 'jwt-2'])
            expect(refresh.requests).toHaveLength(1)
            expect(refresh.requests[0]!.body).toEqual({ refreshToken: 'r-1' })
            expect(auth.refreshToken).toBe('r-2')
            login.unregister()
            refresh.unregister()
        })

        it('outlives the access token: a reload after it expired renews the session', async () => {
            sessionStorage.setItem(KEY, JSON.stringify({ token: 'old', expiresAt: Date.now() - 1000, user: { id: 1, name: 'Vector Admin' }, refreshToken: 'r-1', refreshExpiresAt: Date.now() + 60_000, refreshSubject: '1' }))
            const refresh = endpoint('/auth/refresh', { body: pair('jwt-2', 'r-2') }, 'POST')
            const auth = useVectorAuthStore()

            auth.restore()
            expect(auth.isAuthenticated).toBe(true)
            expect(await auth.fresh()).toBe('jwt-2')
            refresh.unregister()
        })

        it('signs out when the refresh token is refused', async () => {
            const login = endpoint('/auth/login', { body: { ...pair('jwt-1', 'r-1'), expiresIn: 60 } }, 'POST')
            const refresh = endpoint('/auth/refresh', { status: 401, body: { error: 'Invalid or expired refresh token.' } }, 'POST')
            const auth = useVectorAuthStore()
            await auth.login('admin@vector.local', 'password')

            expect(await auth.refresh()).toBe(false)
            expect(auth.token).toBeNull()
            expect(auth.isAuthenticated).toBe(false)
            expect(sessionStorage.getItem(KEY)).toBeNull()
            login.unregister()
            refresh.unregister()
        })

        it('never renews an impersonation: the refresh token is the signed-in user\'s', async () => {
            const login = endpoint('/auth/login', { body: pair('jwt-1', 'r-1') }, 'POST')
            const auth = useVectorAuthStore()
            await auth.login('admin@vector.local', 'password')

            auth.adopt('as-editor', 60, { id: 2, name: 'Demo Editor' })

            expect(auth.canRefresh).toBe(false)
            expect(await auth.fresh()).toBe('as-editor')
            login.unregister()
        })

        it('keeps the signer\'s refresh token when an impersonation runs out, for the client to resume their session', async () => {
            const login = endpoint('/auth/login', { body: pair('jwt-1', 'r-1') }, 'POST')
            const auth = useVectorAuthStore()
            await auth.login('admin@vector.local', 'password')
            vi.useFakeTimers()
            auth.adopt('as-editor', 60, { id: 2, name: 'Demo Editor' })

            vi.advanceTimersByTime(61_000)

            expect(auth.impersonating).toBe(true)
            expect(auth.canResume).toBe(true)
            expect(auth.refreshToken).toBe('r-1')
            expect(auth.isAuthenticated).toBe(true)
            login.unregister()
        })

        it('revokes the refresh token on logout', async () => {
            const login = endpoint('/auth/login', { body: pair('jwt-1', 'r-1') }, 'POST')
            const logout = endpoint('/auth/logout', { status: 204 }, 'POST')
            const auth = useVectorAuthStore()
            await auth.login('admin@vector.local', 'password')

            await auth.logout()

            expect(logout.requests[0]!.body).toEqual({ refreshToken: 'r-1' })
            expect(auth.token).toBeNull()
            login.unregister()
            logout.unregister()
        })
    })

    it('in hosted mode is signed in by the application, from the shell\'s user', () => {
        sessionStorage.setItem(KEY, JSON.stringify({ token: 'stale', expiresAt: Date.now() + 60_000, user: { id: 9, name: 'x' } }))
        window.__VECTOR__ = {
            apiBase: '', basePath: '', authMode: 'cookie', loginUrl: '/login', logoutUrl: '/logout',
            csrfToken: 't', cspNonce: null, user: { id: 1, name: null }, branding: null, extensions: [],
        }
        const auth = useVectorAuthStore()

        auth.restore()

        expect(auth.isAuthenticated).toBe(true)
        expect(auth.token).toBeNull()
        expect(auth.user).toEqual({ id: 1, name: 'Account' })
    })
})
