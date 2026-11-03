import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { loadVectorExtensions } from '#vector/app/utils/extensionLoader'

// an import the test settles itself, as a slow network would
function deferredImports() {
    const pending = new Map<string, { resolve: (module: unknown) => void, reject: (error: unknown) => void }>()
    const importModule = vi.fn((url: string) => new Promise<unknown>((resolve, reject) => pending.set(url, { resolve, reject })))
    return { importModule, pending }
}

const settle = () => new Promise(resolve => setTimeout(resolve, 0))

function setup(initial: string[]) {
    const { importModule, pending } = deferredImports()
    const ready = ref(false)
    const run = vi.fn()
    const done = loadVectorExtensions({ initial, ready, importModule, run })
    return { importModule, pending, ready, run, done }
}

describe('loadVectorExtensions', () => {
    it('is ready at once when there is no module to load', async () => {
        const { importModule, ready, done } = setup([])
        await done

        expect(importModule).not.toHaveBeenCalled()
        expect(ready.value).toBe(true)
    })

    it('is ready only once every listed module has settled', async () => {
        const { pending, ready, run } = setup(['/vector-extensions/stock-level.js', '/vector-extensions/stock-overview.js'])
        pending.get('/vector-extensions/stock-level.js')!.resolve({})
        await settle()
        expect(ready.value).toBe(false) // the second has not arrived

        pending.get('/vector-extensions/stock-overview.js')!.resolve({})
        await settle()
        expect(ready.value).toBe(true)
        expect(run).toHaveBeenCalledTimes(2)
    })

    it('fetches the modules at once but runs them in the order listed, whichever arrives first', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined)
        const { importModule, pending, ready, run } = setup(['/a.js', '/b.js', '/c.js'])
        expect(importModule).toHaveBeenCalledTimes(3) // none waits for another's download

        pending.get('/c.js')!.resolve({})
        pending.get('/b.js')!.reject(new TypeError('Failed to fetch dynamically imported module'))
        await settle()
        expect(run).not.toHaveBeenCalled() // /a.js, listed first, has not arrived

        pending.get('/a.js')!.resolve({})
        await settle()
        expect(run.mock.calls.map(([, url]) => url)).toEqual(['/a.js', '/c.js']) // the failed one skipped, the order kept
        expect(ready.value).toBe(true)
        error.mockRestore()
    })

    it('does not wait on a module that fails to load: it is logged, and the admin is ready', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined)
        const { pending, ready, run } = setup(['/vector-extensions/broken.js'])
        pending.get('/vector-extensions/broken.js')!.reject(new TypeError('Failed to fetch dynamically imported module'))
        await settle()

        expect(ready.value).toBe(true)
        expect(run).not.toHaveBeenCalled()
        expect(error).toHaveBeenCalledWith('Vector extension /vector-extensions/broken.js failed to load', expect.any(TypeError))
        error.mockRestore()
    })
})
