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

    // Known bug: `isAuthenticated` is a Pinia getter (a cached computed) over
    // Date.now(), which is not reactive, so once read it stays true past the
    // expiry until some store state changes. The route guard then admits an
    // expired session, which only ends at the next API call's 401. When the
    // getter is fixed this test starts passing and `it.fails` reports it:
    // turn it back into `it`.
    it.fails('stops counting as signed in once the token expires', () => {
        vi.useFakeTimers()
        const auth = useVectorAuthStore()
        auth.adopt('jwt', 60, { id: 1, name: 'Vector Admin' })
        expect(auth.isAuthenticated).toBe(true)

        vi.advanceTimersByTime(61_000)

        expect(auth.isAuthenticated).toBe(false)
    })

    it('clears the token, the user and the mirror', () => {
        const auth = useVectorAuthStore()
        auth.adopt('jwt', 60, { id: 1, name: 'Vector Admin' })

        auth.clear()

        expect(auth.token).toBeNull()
        expect(auth.user).toBeNull()
        expect(sessionStorage.getItem(KEY)).toBeNull()
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
