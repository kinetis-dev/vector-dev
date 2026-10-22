import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import LoginPage from '#vector/app/pages/login.vue'
import { endpoint, standaloneApi } from '../endpoints'
import { schema } from '../fixtures'

// After signing in, the page goes back to where the visitor was sent from,
// and only when that is a path of this admin.

const { navigate, query } = vi.hoisted(() => ({ navigate: vi.fn(), query: {} as Record<string, string> }))
mockNuxtImport('navigateTo', () => navigate)
mockNuxtImport('useRoute', () => () => ({ path: '/login', fullPath: '/login', query }))

const cleanups: (() => void)[] = []
const mock = (...args: Parameters<typeof endpoint>) => {
    const registered = endpoint(...args)
    cleanups.push(registered.unregister)
    return registered
}

beforeEach(() => {
    standaloneApi()
    useVectorAuthStore().clear()
    navigate.mockClear()
    mock('/auth/login', { body: { accessToken: 'jwt', tokenType: 'Bearer', expiresIn: 900, user: { id: 1, name: 'Vector Admin' } } }, 'POST')
    mock('/vector/schema', { body: schema([]) })
})
afterEach(() => {
    cleanups.splice(0).forEach(unregister => unregister())
    useVectorAuthStore().clear()
})

async function signInFrom(redirect: string) {
    query.redirect = redirect
    const wrapper = await mountSuspended(LoginPage)
    await wrapper.find('input[type="email"]').setValue('admin@vector.local')
    await wrapper.find('input[type="password"]').setValue('secret')
    navigate.mockClear()
    await wrapper.find('form').trigger('submit')
    await vi.waitFor(() => expect(navigate).toHaveBeenCalled())
    await flushPromises()
    wrapper.unmount()
    useVectorAuthStore().clear()

    return navigate.mock.calls.at(-1)?.[0]
}

describe('login page', () => {
    it('goes back to a path of the admin', async () => {
        expect(await signInFrom('/r/products?page=2')).toBe('/r/products?page=2')
    })

    it('goes nowhere a browser would read as another origin', async () => {
        // a browser strips tab, CR and LF from a URL: `/\t/host` is `//host`
        for (const redirect of ['//evil.example', '/\\evil.example', 'https://evil.example', '/\t/evil.example', '/\n/evil.example', '/\r/evil.example', '/ /evil.example', '/r\\..\\evil', '/\u0000/evil.example']) {
            expect(await signInFrom(redirect), JSON.stringify(redirect)).toBe('/')
        }
    })
})
