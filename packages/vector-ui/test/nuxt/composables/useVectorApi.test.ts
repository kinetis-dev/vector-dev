import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { endpoint, standaloneApi } from '../endpoints'

const cleanups: (() => void)[] = []
const mock = (...args: Parameters<typeof endpoint>) => {
    const registered = endpoint(...args)
    cleanups.push(registered.unregister)
    return registered
}

beforeEach(() => {
    standaloneApi()
    useVectorAuthStore().clear()
})

afterEach(() => {
    cleanups.splice(0).forEach(unregister => unregister())
    delete window.__VECTOR__
})

describe('useVectorApi, standalone (bearer)', () => {
    it('sends the in-memory token as a bearer header', async () => {
        const auth = useVectorAuthStore()
        auth.adopt('token-123', 3600, { id: 1, name: 'Vector Admin' })
        const probe = mock('/vector/probe', { body: { ok: true } })

        await useVectorApi()('/vector/probe')

        expect(probe.requests[0]!.headers.get('authorization')).toBe('Bearer token-123')
    })

    it('sends no authorization header without a token', async () => {
        const probe = mock('/vector/probe', { body: { ok: true } })

        await useVectorApi()('/vector/probe')

        expect(probe.requests[0]!.headers.get('authorization')).toBeNull()
    })

    it('never sends a CSRF token', async () => {
        useVectorAuthStore().adopt('token-123', 3600, { id: 1, name: 'Vector Admin' })
        const probe = mock('/vector/probe', { body: {} }, 'POST')

        await useVectorApi()('/vector/probe', { method: 'POST', body: {} })

        expect(probe.requests[0]!.headers.get('x-csrf-token')).toBeNull()
    })

    it('signs the user out and goes to the login page on a 401', async () => {
        const auth = useVectorAuthStore()
        auth.adopt('expired', 3600, { id: 1, name: 'Vector Admin' })
        mock('/vector/probe', { status: 401, body: { error: 'Unauthenticated.' } })

        await expect(useVectorApi()('/vector/probe')).rejects.toMatchObject({ status: 401 })

        expect(auth.token).toBeNull()
        expect(auth.user).toBeNull()
        expect(useRouter().currentRoute.value.path).toBe('/login')
    })

    it('on a 401 renews the session once and repeats the request', async () => {
        const auth = useVectorAuthStore()
        mock('/auth/login', { body: { accessToken: 'revoked', tokenType: 'Bearer', expiresIn: 900, refreshToken: 'r-1', refreshExpiresIn: 3600, user: { id: 1, name: 'Vector Admin' } } }, 'POST')
        const refresh = mock('/auth/refresh', { body: { accessToken: 'renewed', tokenType: 'Bearer', expiresIn: 900, refreshToken: 'r-2', refreshExpiresIn: 3600, user: { id: 1, name: 'Vector Admin' } } }, 'POST')
        const probe = mock('/vector/probe', request => request.headers.get('authorization') === 'Bearer renewed' ? { body: { ok: true } } : { status: 401, body: { error: 'Unauthenticated.' } }, 'POST')
        await auth.login('admin@vector.local', 'password')

        const answer = await useVectorApi()('/vector/probe', { method: 'POST', body: { a: 1 } })

        expect(answer).toEqual({ ok: true })
        expect(refresh.requests).toHaveLength(1)
        expect(probe.requests.map(r => r.headers.get('authorization'))).toEqual(['Bearer revoked', 'Bearer renewed'])
        expect(auth.token).toBe('renewed')
    })

    it('repeats a request refused for a token already renewed, without renewing again', async () => {
        const auth = useVectorAuthStore()
        mock('/auth/login', { body: { accessToken: 'revoked', tokenType: 'Bearer', expiresIn: 900, refreshToken: 'r-1', refreshExpiresIn: 3600, user: { id: 1, name: 'Vector Admin' } } }, 'POST')
        const refresh = mock('/auth/refresh', { body: { accessToken: 'renewed', tokenType: 'Bearer', expiresIn: 900, refreshToken: 'r-2', refreshExpiresIn: 3600, user: { id: 1, name: 'Vector Admin' } } }, 'POST')
        let release!: () => void
        const held = new Promise<void>((resolve) => { release = resolve })
        // the slow request's 401 arrives only after the fast one's renewal finished
        const slow = mock('/vector/slow', async (request) => {
            if (request.headers.get('authorization') === 'Bearer renewed') return { body: { ok: 'slow' } }
            await held
            return { status: 401, body: { error: 'Unauthenticated.' } }
        })
        mock('/vector/fast', request => request.headers.get('authorization') === 'Bearer renewed' ? { body: { ok: 'fast' } } : { status: 401, body: { error: 'Unauthenticated.' } })
        await auth.login('admin@vector.local', 'password')
        const api = useVectorApi()

        const slowAnswer = api('/vector/slow')
        await vi.waitFor(() => expect(slow.requests).toHaveLength(1))
        expect(await api('/vector/fast')).toEqual({ ok: 'fast' })
        release()

        expect(await slowAnswer).toEqual({ ok: 'slow' })
        expect(refresh.requests).toHaveLength(1)
        expect(auth.token).toBe('renewed')
        expect(auth.refreshToken).toBe('r-2')
    })

    it('leaves the session alone on other errors', async () => {
        const auth = useVectorAuthStore()
        auth.adopt('token-123', 3600, { id: 1, name: 'Vector Admin' })
        mock('/vector/probe', { status: 403, body: { error: 'Forbidden.' } })

        await expect(useVectorApi()('/vector/probe')).rejects.toMatchObject({ status: 403 })

        expect(auth.token).toBe('token-123')
    })
})

describe('useVectorApi, hosted (cookie)', () => {
    function host(csrfToken: string | null) {
        window.__VECTOR__ = {
            apiBase: '',
            basePath: '',
            authMode: 'cookie',
            loginUrl: '/login',
            logoutUrl: '/logout',
            csrfToken,
            cspNonce: null,
            user: { id: 1, name: 'Vector Admin' },
            branding: null,
            extensions: [],
        }
    }

    it('sends the CSRF token on unsafe methods only', async () => {
        host('csrf-abc')
        const write = mock('/vector/probe', { body: {} }, 'PATCH')
        const read = mock('/vector/probe', { body: {} }, 'GET')

        await useVectorApi()('/vector/probe', { method: 'PATCH', body: {} })
        await useVectorApi()('/vector/probe')

        expect(write.requests[0]!.headers.get('x-csrf-token')).toBe('csrf-abc')
        expect(read.requests[0]!.headers.get('x-csrf-token')).toBeNull()
    })

    it('never sends a bearer token, even with one in the store', async () => {
        host('csrf-abc')
        useVectorAuthStore().adopt('token-123', 3600, { id: 1, name: 'Vector Admin' })
        const probe = mock('/vector/probe', { body: {} })

        await useVectorApi()('/vector/probe')

        expect(probe.requests[0]!.headers.get('authorization')).toBeNull()
    })
})

describe('vectorErrorMessage', () => {
    it('reads either Kinetis error shape, then the error itself', () => {
        expect(vectorErrorMessage({ data: { error: 'Forbidden.' } })).toBe('Forbidden.')
        expect(vectorErrorMessage({ data: { detail: 'Record not found.' } })).toBe('Record not found.')
        expect(vectorErrorMessage({ data: 'text', message: 'Network down' })).toBe('Network down')
        expect(vectorErrorMessage(new Error('Boom'))).toBe('Boom')
        expect(vectorErrorMessage('plain')).toBe('plain')
    })
})
