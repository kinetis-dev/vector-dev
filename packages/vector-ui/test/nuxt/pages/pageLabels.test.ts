import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import VectorLayout from '#vector/app/layouts/vector.vue'
import CustomPage from '#vector/app/pages/p/[name].vue'
import { endpoint, standaloneApi } from '../endpoints'
import { schema } from '../fixtures'

// A custom page's title and menu section given as functions follow a
// change of language: the menu, the page's heading and its tab title
// call them again. A title computed once with t() at registration — the
// string form — keeps the language it was registered in: the control.

const Panel = defineComponent({ setup: () => () => h('p', { class: 'panel' }, 'Stock panel') })

const messages = {
    en: { pageLabels: { title: 'Stock overview', fixed: 'Fixed stock', group: 'Catalog' } },
    de: { pageLabels: { title: 'Lagerübersicht', fixed: 'Fester Bestand', group: 'Katalog' } },
}

describe('custom page labels across a change of language', () => {
    const cleanups: (() => void)[] = []

    beforeEach(() => {
        standaloneApi()
        useVectorAuthStore().adopt('jwt', 3600, { id: 1, name: 'Vector Admin' })
        cleanups.push(endpoint('/vector/schema', { body: schema([]) }).unregister)
        cleanups.push(endpoint('/vector/badges', { body: { data: {} } }).unregister)

        const vector = window.Vector!
        for (const [code, catalogue] of Object.entries(messages)) vector.defineMessages(code, catalogue)
        vector.definePage('live-stock', { title: () => vector.t('pageLabels.title'), group: () => vector.t('pageLabels.group'), component: Panel })
        // the string form, translated once now, in English
        vector.definePage('fixed-stock', { title: vector.t('pageLabels.fixed'), group: () => vector.t('pageLabels.group'), component: Panel })
    })

    afterEach(() => {
        useVectorLocale().apply('en')
        cleanups.splice(0).forEach(unregister => unregister())
        useVectorAuthStore().clear()
        useVectorExtensions().pages.delete('live-stock')
        useVectorExtensions().pages.delete('fixed-stock')
    })

    it('re-reads function labels in the new language, grouped by the section text in it', async () => {
        const wrapper = await mountSuspended(VectorLayout, { route: '/p/live-stock', slots: { default: () => h(CustomPage) } })
        await flushPromises()

        const nav = () => wrapper.find('[role="navigation"]').text()
        expect(nav()).toContain('Catalog')
        expect(nav()).toContain('Stock overview')
        expect(wrapper.find('h1.text-h4').text()).toBe('Stock overview')

        useVectorLocale().apply('de')
        await nextTick()
        await flushPromises()

        expect(nav()).toContain('Katalog')
        expect(nav()).not.toContain('Catalog')
        expect(nav()).toContain('Lagerübersicht')
        expect(nav()).not.toContain('Stock overview')
        expect(wrapper.find('h1.text-h4').text()).toBe('Lagerübersicht')
        // both pages still share the one section, under its German name
        expect(wrapper.findAll('.v-list-subheader').filter(s => s.text() === 'Katalog')).toHaveLength(1)

        // the control: a string title translated at registration stays in English
        expect(nav()).toContain('Fixed stock')
        expect(nav()).not.toContain('Fester Bestand')
        wrapper.unmount()
    })
})
