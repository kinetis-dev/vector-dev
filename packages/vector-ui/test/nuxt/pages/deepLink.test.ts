import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import VectorLayout from '#vector/app/layouts/vector.vue'
import CustomPage from '#vector/app/pages/p/[name].vue'
import { descriptorSignals, loadVectorExtensions } from '#vector/app/utils/extensionLoader'
import { endpoint, standaloneApi } from '../endpoints'
import { schema } from '../fixtures'

// A fresh standalone page load straight on a package's custom page: the
// `vector` layout the page declares loads the descriptor (its
// `await load()`), the descriptor lists the package's module, and the
// page is a loading bar until that module has registered it, then the
// page. Wired as the plugin wires it — the real schema composable, the
// real window.Vector — with only the browser's import() replaced.

const MODULE = '/vector/extensions/acme/panels/0123456789abcdef/panel.js'
const Panel = defineComponent({ setup: () => () => h('p', { class: 'panel' }, 'Package panel') })

function deferredImports() {
    const pending = new Map<string, () => void>()
    const importModule = vi.fn((url: string) => new Promise<unknown>((resolve) => {
        // the package's module, as it calls window.Vector once imported
        pending.set(url, () => resolve({ default: (vector: NonNullable<typeof window.Vector>) => vector.definePage('panel-page', { title: 'Package panel page', component: Panel }) }))
    }))
    return { importModule, pending }
}

function startLoader(importModule: (url: string) => Promise<unknown>) {
    return loadVectorExtensions({
        hosted: false,
        initial: [],
        apiBase: useRuntimeConfig().public.vector.apiBase,
        ...descriptorSignals(),
        ready: useVectorExtensions().ready,
        importModule,
        run: module => (module as { default: (vector: typeof window.Vector) => void }).default(window.Vector),
    })
}

describe('a deep link to a package page on a fresh standalone load', () => {
    const cleanups: (() => void)[] = []

    // the plugin's own loader read a descriptor once already at boot, so only this test's loader reacts below
    beforeAll(() => {
        useVectorSchema().schema.value = schema([])
    })

    beforeEach(() => {
        standaloneApi()
        useVectorAuthStore().adopt('jwt', 3600, { id: 1, name: 'Vector Admin' })
        useVectorSchema().clear()
        useVectorExtensions().ready.value = false
        cleanups.push(endpoint('/vector/badges', { body: { data: {} } }).unregister)
    })

    afterEach(() => {
        cleanups.splice(0).forEach(unregister => unregister())
        useVectorAuthStore().clear()
        useVectorExtensions().pages.delete('panel-page')
        useVectorExtensions().ready.value = true
    })

    it('fetches the descriptor through the layout, waits for the module it lists, then shows the page', async () => {
        const schemaEndpoint = endpoint('/vector/schema', { body: schema([], { ui: { expected: null, extensions: [MODULE] } }) })
        cleanups.push(schemaEndpoint.unregister)
        const { importModule, pending } = deferredImports()
        const settled = startLoader(importModule)

        const wrapper = await mountSuspended(VectorLayout, { route: '/p/panel-page', slots: { default: () => h(CustomPage) } })
        await flushPromises()

        expect(schemaEndpoint.requests.map(request => request.path)).toContain('/vector/schema')
        expect(importModule.mock.calls.map(([url]) => url)).toEqual([new URL(MODULE, window.location.origin).href])
        expect(wrapper.find('[aria-label="Loading the page"]').exists()).toBe(true)
        expect(wrapper.text()).not.toContain('Unknown page')

        pending.forEach(resolve => resolve())
        await settled
        await flushPromises()

        expect(wrapper.find('.panel').text()).toBe('Package panel')
        expect(wrapper.text()).toContain('Package panel page')
        wrapper.unmount()
    })

    it('settles ready when the descriptor fails to load, so the page is not a loading bar forever', async () => {
        cleanups.push(endpoint('/vector/schema', { status: 500, body: { error: 'down' } }).unregister)
        const { importModule } = deferredImports()
        const settled = startLoader(importModule)
        const failed = ref<unknown>(null)

        await useVectorSchema().load().catch((error: unknown) => { failed.value = error })
        await settled

        expect(failed.value).not.toBeNull()
        expect(useVectorSchema().failed.value).toBe(true)
        expect(useVectorExtensions().ready.value).toBe(true)
        expect(importModule).not.toHaveBeenCalled()

        const wrapper = await mountSuspended(CustomPage, { route: '/p/panel-page' })
        await flushPromises()
        expect(wrapper.text()).toContain('Unknown page')
        wrapper.unmount()
    })
})
