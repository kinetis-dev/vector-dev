import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import CustomPage from '#vector/app/pages/p/[name].vue'
import { endpoint, standaloneApi } from '../endpoints'
import { schema } from '../fixtures'

// A link straight to a page a package's module declares: until the
// modules settle (in standalone mode, the ones the descriptor lists) the
// page is loading, never "unknown"; the module's page then shows.

const Panel = defineComponent({ setup: () => () => h('p', { class: 'panel' }, 'Package panel') })

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

        expect(wrapper.find('.panel').text()).toBe('Package panel')
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
