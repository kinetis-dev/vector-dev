import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import VectorLayout from '#vector/app/layouts/vector.vue'
import CustomPage from '#vector/app/pages/p/[name].vue'
import { loadVectorExtensions } from '#vector/app/utils/extensionLoader'
import { endpoint, standaloneApi } from '../endpoints'
import { schema } from '../fixtures'

// A fresh standalone page load straight on a custom page a runtime
// extension module of the application defines (runtime config's
// `extensions`): the `vector` layout the page declares loads the
// descriptor, and the page is a loading bar until that module has
// registered it, then the page. Wired as the plugin wires it — the real
// window.Vector — with only the browser's import() replaced.

const MODULE = '/vector-extensions/panel.js'
const Panel = defineComponent({ setup: () => () => h('p', { class: 'panel' }, 'Stock panel') })

function deferredImports() {
    const pending = new Map<string, { resolve: () => void, reject: (error: unknown) => void }>()
    const importModule = vi.fn((url: string) => new Promise<unknown>((resolve, reject) => {
        // the application's module, as it calls window.Vector once imported
        pending.set(url, {
            resolve: () => resolve({ default: (vector: NonNullable<typeof window.Vector>) => vector.definePage('panel-page', { title: 'Stock panel page', component: Panel }) }),
            reject,
        })
    }))
    return { importModule, pending }
}

function startLoader(importModule: (url: string) => Promise<unknown>) {
    return loadVectorExtensions({
        initial: [MODULE],
        ready: useVectorExtensions().ready,
        importModule,
        run: module => (module as { default: (vector: typeof window.Vector) => void }).default(window.Vector),
    })
}

describe('a deep link to a runtime extension\'s page on a fresh standalone load', () => {
    const cleanups: (() => void)[] = []

    beforeEach(() => {
        standaloneApi()
        useVectorAuthStore().adopt('jwt', 3600, { id: 1, name: 'Vector Admin' })
        useVectorSchema().clear()
        useVectorExtensions().ready.value = false
        cleanups.push(endpoint('/vector/schema', { body: schema([]) }).unregister)
        cleanups.push(endpoint('/vector/badges', { body: { data: {} } }).unregister)
    })

    afterEach(() => {
        cleanups.splice(0).forEach(unregister => unregister())
        useVectorAuthStore().clear()
        useVectorExtensions().pages.delete('panel-page')
        useVectorExtensions().ready.value = true
    })

    it('waits through the layout for the module, then shows the page', async () => {
        const { importModule, pending } = deferredImports()
        const settled = startLoader(importModule)

        const wrapper = await mountSuspended(VectorLayout, { route: '/p/panel-page', slots: { default: () => h(CustomPage) } })
        await flushPromises()

        expect(importModule.mock.calls.map(([url]) => url)).toEqual([MODULE])
        expect(wrapper.find('[aria-label="Loading the page"]').exists()).toBe(true)
        expect(wrapper.text()).not.toContain('Unknown page')

        pending.get(MODULE)!.resolve()
        await settled
        await flushPromises()

        expect(wrapper.find('.panel').text()).toBe('Stock panel')
        expect(wrapper.text()).toContain('Stock panel page')
        wrapper.unmount()
    })

    it('reads "unknown page" once the module failed, rather than loading forever', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined)
        const { importModule, pending } = deferredImports()
        const settled = startLoader(importModule)

        const wrapper = await mountSuspended(VectorLayout, { route: '/p/panel-page', slots: { default: () => h(CustomPage) } })
        await flushPromises()
        pending.get(MODULE)!.reject(new TypeError('Failed to fetch dynamically imported module'))
        await settled
        await flushPromises()

        expect(useVectorExtensions().ready.value).toBe(true)
        expect(wrapper.text()).toContain('Unknown page')
        wrapper.unmount()
        error.mockRestore()
    })
})
