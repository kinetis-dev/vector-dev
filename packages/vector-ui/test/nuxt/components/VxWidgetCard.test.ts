import { afterEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import VxWidgetCard from '#vector/app/components/Vx/Widget/Card.vue'
import type { VectorWidget, VectorWidgetData } from '#vector/app/types/vector'

const widget = (type: string): VectorWidget => ({ name: 'card', label: 'Card', type, icon: null, ranges: {}, width: 4, cacheSeconds: 0 })
const load = (type: string, extra: Record<string, unknown> = {}) => async (): Promise<VectorWidgetData> => ({ type, range: 'all', cached: false, ...extra })

// a renderer as a runtime extension registers it through window.Vector.defineWidget
const Sparkline = defineComponent({
    props: { data: { type: Object, required: true }, widget: { type: Object, required: true } },
    setup: props => () => h('p', { class: 'sparkline' }, `samples ${(props.data.samples as number[]).join(',')} for ${props.widget.name}`),
})

describe('VxWidgetCard', () => {
    afterEach(() => {
        useVectorExtensions().widgets.delete('sparkline-chart')
        useVectorExtensions().widgets.delete('sparkline-late')
    })

    it('says no renderer is registered for a type with no built-in card, instead of drawing a stat', async () => {
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('sparkline-chart'), load: load('sparkline-chart', { value: 7 }) } })
        await flushPromises()

        expect(wrapper.text()).toContain('No renderer is registered for the widget type sparkline-chart.')
        expect(wrapper.find('.v-alert').exists()).toBe(true)
        expect(wrapper.text()).not.toContain('7')
        wrapper.unmount()
    })

    it('renders an extension type with the renderer registered for it, handing it the data as the server answered', async () => {
        useVectorExtensions().widgets.set('sparkline-chart', Sparkline)
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('sparkline-chart'), load: load('sparkline-chart', { samples: [3, 1, 4] }) } })
        await flushPromises()

        expect(wrapper.find('.sparkline').text()).toBe('samples 3,1,4 for card')
        expect(wrapper.text()).not.toContain('No renderer')
        wrapper.unmount()
    })

    it('swaps the notice for a renderer registered after the card mounted, as a slow extension module does', async () => {
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('sparkline-late'), load: load('sparkline-late', { samples: [2, 7] }) } })
        await flushPromises()
        expect(wrapper.text()).toContain('No renderer is registered for the widget type sparkline-late.')
        expect(wrapper.find('.sparkline').exists()).toBe(false)

        useVectorExtensions().widgets.set('sparkline-late', Sparkline)
        await nextTick()

        expect(wrapper.find('.sparkline').text()).toBe('samples 2,7 for card')
        expect(wrapper.text()).not.toContain('No renderer')
        wrapper.unmount()
    })

    it('still draws a built-in type with its own card', async () => {
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('stat'), load: load('stat', { value: 1234, previous: null, format: 'number', caption: null }) } })
        await flushPromises()
        await new Promise(resolve => setTimeout(resolve, 20))
        await flushPromises()

        expect(wrapper.text()).toContain('1,234')
        expect(wrapper.text()).not.toContain('No renderer')
        wrapper.unmount()
    })
})
