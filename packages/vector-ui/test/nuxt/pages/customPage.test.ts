import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import CustomPage from '#vector/app/pages/p/[name].vue'
import { endpoint, standaloneApi } from '../endpoints'
import { schema } from '../fixtures'

// A link straight to a custom page: one the application's own app.config
// declares shows at once; one a runtime extension module defines is
// loading, never "unknown", until the modules settle, and then shows.

const Panel = defineComponent({ setup: () => () => h('p', { class: 'panel' }, 'Stock panel') })

describe('a custom page opened from a link', () => {
    let unregister = () => {}

    // signed in, so the page's auth middleware lets the link through
    beforeEach(() => {
        standaloneApi()
        useVectorAuthStore().adopt('jwt', 3600, { id: 1, name: 'Vector Admin' })
        unregister = endpoint('/vector/schema', { body: schema([]) }).unregister
    })

    // the boot's own value, put back after each test
    afterEach(() => {
        unregister()
        useVectorAuthStore().clear()
        useVectorExtensions().pages.delete('late-panel')
        useVectorExtensions().ready.value = true
        delete (useAppConfig().vector.pages as Record<string, unknown>)['app-panel']
    })

    it('shows a page the application declares in app.config, whatever the modules are doing', async () => {
        useVectorExtensions().ready.value = false
        // a component rather than a registered name, as useVectorPages accepts either
        const declared = useAppConfig().vector.pages as Record<string, unknown>
        declared['app-panel'] = { title: 'App panel', component: Panel }
        const wrapper = await mountSuspended(CustomPage, { route: '/p/app-panel' })
        await flushPromises()

        expect(wrapper.find('.panel').text()).toBe('Stock panel')
        expect(wrapper.text()).toContain('App panel')
        expect(wrapper.find('[aria-label="Loading the page"]').exists()).toBe(false)
        wrapper.unmount()
    })

    it('waits for the extension modules instead of calling the page unknown', async () => {
        const registries = useVectorExtensions()
        registries.ready.value = false
        const wrapper = await mountSuspended(CustomPage, { route: '/p/late-panel' })
        await flushPromises()

        expect(wrapper.find('[aria-label="Loading the page"]').exists()).toBe(true)
        expect(wrapper.text()).not.toContain('Unknown page')

        registries.pages.set('late-panel', { title: 'Late panel', icon: 'mdi-tray-full', component: Panel })
        registries.ready.value = true
        await nextTick()

        expect(wrapper.find('.panel').text()).toBe('Stock panel')
        expect(wrapper.text()).toContain('Late panel')
        wrapper.unmount()
    })

    it('is unknown once the modules settled without it', async () => {
        useVectorExtensions().ready.value = true
        const wrapper = await mountSuspended(CustomPage, { route: '/p/late-panel' })
        await flushPromises()

        expect(wrapper.text()).toContain('Unknown page')
        wrapper.unmount()
    })
})
