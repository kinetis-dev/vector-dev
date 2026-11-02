import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { apiModuleUrl, loadVectorExtensions } from '#vector/app/utils/extensionLoader'

const MODULE = '/vector/extensions/acme/panels/0123456789abcdef/panel.js'
const BASE = 'http://localhost:3000/admin/'

// an import the test settles itself, as a slow network would
function deferredImports() {
    const pending = new Map<string, { resolve: (module: unknown) => void, reject: (error: unknown) => void }>()
    const importModule = vi.fn((url: string) => new Promise<unknown>((resolve, reject) => pending.set(url, { resolve, reject })))
    return { importModule, pending }
}

const settle = () => new Promise(resolve => setTimeout(resolve, 0))

function setup(hosted: boolean, initial: string[] = []) {
    const { importModule, pending } = deferredImports()
    const descriptorModules = ref<string[] | null>(null)
    const ready = ref(false)
    const run = vi.fn()
    const done = loadVectorExtensions({ hosted, initial, apiBase: 'http://localhost:8080', descriptorModules, ready, importModule, run, base: BASE })
    return { importModule, pending, descriptorModules, ready, run, done }
}

describe('loadVectorExtensions', () => {
    it('joins a descriptor path to the API origin as the API client joins a request', () => {
        expect(apiModuleUrl(MODULE, 'http://localhost:8080', BASE)).toBe(`http://localhost:8080${MODULE}`)
        expect(apiModuleUrl(MODULE, 'https://api.example.test/admin-api/', BASE)).toBe(`https://api.example.test/admin-api${MODULE}`)
        expect(apiModuleUrl(MODULE, '', BASE)).toBe(`http://localhost:3000${MODULE}`)
    })

    it('in standalone mode, imports the modules the descriptor lists from the API origin, and is ready only once they settled', async () => {
        const { importModule, pending, descriptorModules, ready, run } = setup(false, ['/ext/runtime.js'])
        pending.get('/ext/runtime.js')!.resolve({})
        await settle()
        expect(ready.value).toBe(false) // signed out: the descriptor is not loaded yet

        descriptorModules.value = [MODULE]
        await settle()
        expect(importModule.mock.calls.map(([url]) => url)).toEqual(['/ext/runtime.js', `http://localhost:8080${MODULE}`])
        expect(ready.value).toBe(false) // a deep link to the package's page waits here

        const module = { default: vi.fn() }
        pending.get(`http://localhost:8080${MODULE}`)!.resolve(module)
        await settle()
        expect(run).toHaveBeenCalledWith(module, `http://localhost:8080${MODULE}`)
        expect(ready.value).toBe(true)
    })

    it('in hosted mode, imports each of the shell\'s modules once and never the descriptor\'s copy of them', async () => {
        const { importModule, pending, descriptorModules, ready } = setup(true, [MODULE, '/vector-extensions/stock-level.js'])
        pending.forEach(({ resolve }) => resolve({}))
        await settle()
        expect(ready.value).toBe(true) // the shell's list is the whole of it

        descriptorModules.value = [MODULE]
        await settle()
        expect(importModule.mock.calls.map(([url]) => url)).toEqual([MODULE, '/vector-extensions/stock-level.js'])
    })

    it('does not wait on a module that fails to load: it is logged, and the admin is ready', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined)
        const { pending, descriptorModules, ready, run } = setup(false)
        descriptorModules.value = [MODULE]
        await settle()
        pending.get(`http://localhost:8080${MODULE}`)!.reject(new TypeError('Failed to fetch dynamically imported module'))
        await settle()

        expect(ready.value).toBe(true)
        expect(run).not.toHaveBeenCalled()
        expect(error).toHaveBeenCalledWith(`Vector extension http://localhost:8080${MODULE} failed to load`, expect.any(TypeError))
        error.mockRestore()
    })

    it('loads the descriptor\'s modules once a page: signing out and in again, or as someone else, imports nothing more', async () => {
        const { importModule, pending, descriptorModules } = setup(false)
        descriptorModules.value = [MODULE]
        await settle()
        pending.forEach(({ resolve }) => resolve({}))

        descriptorModules.value = null // signed out
        await settle()
        descriptorModules.value = [MODULE, '/vector/extensions/acme/other/fedcba9876543210/other.js'] // another user's descriptor
        await settle()

        expect(importModule).toHaveBeenCalledTimes(1)
    })

    it('skips a descriptor module runtime config already imported from the same URL', async () => {
        const { importModule, pending, descriptorModules, ready } = setup(false, [`http://localhost:8080${MODULE}`])
        descriptorModules.value = [MODULE]
        await settle()
        pending.forEach(({ resolve }) => resolve({}))
        await settle()

        expect(importModule).toHaveBeenCalledTimes(1)
        expect(ready.value).toBe(true)
    })
})
