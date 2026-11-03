import { afterEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import VxWidgetCard from '#vector/app/components/Vx/Widget/Card.vue'
import type { VectorWidget, VectorWidgetData, VectorWidgetType } from '#vector/app/types/vector'

const widget = (type: VectorWidgetType): VectorWidget => ({ name: 'card', label: 'Card', type, icon: null, ranges: {}, width: 4, cacheSeconds: 0 })
const load = (type: VectorWidgetType, extra: Record<string, unknown> = {}) => async (): Promise<VectorWidgetData> => ({ type, range: 'all', cached: false, ...extra })

// the application's own card for a type, as window.Vector.defineWidget or app.config vector.widgets registers it
const Gauge = defineComponent({
    props: { data: { type: Object, required: true }, widget: { type: Object, required: true } },
    setup: props => () => h('p', { class: 'gauge' }, `gauge ${String(props.data.value)} for ${props.widget.name}`),
})

// the application's card for its own extension type, as defineWidget or app.config vector.widgets registers it
const Sparkline = defineComponent({
    props: { data: { type: Object, required: true }, widget: { type: Object, required: true } },
    setup: props => () => h('p', { class: 'sparkline' }, `samples ${(props.data.samples as number[]).join(',')} for ${props.widget.name}`),
})

const settle = async () => {
    await flushPromises()
    await new Promise(resolve => setTimeout(resolve, 20))
    await flushPromises()
}

describe('VxWidgetCard', () => {
    afterEach(() => {
        useVectorExtensions().widgets.delete('stat')
        useVectorExtensions().widgets.delete('progress')
        useVectorExtensions().widgets.delete('sparkline-chart')
        useVectorExtensions().widgets.delete('sparkline-late')
        delete (useAppConfig().vector.widgets as Record<string, unknown>)['sparkline-chart']
    })

    it('draws a built-in type with its own card', async () => {
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('stat'), load: load('stat', { value: 1234, previous: null, format: 'number', caption: null }) } })
        await settle()

        expect(wrapper.text()).toContain('1,234')
        expect(wrapper.find('.gauge').exists()).toBe(false)
        wrapper.unmount()
    })

    // an extension type the application registered no card for: the card says so rather than
    // guessing at a shape — no stat figure, and none of the payload, markup or not, is drawn
    it('says no renderer is registered for an extension type with no card, instead of drawing a stat', async () => {
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('sparkline-chart'), load: load('sparkline-chart', { value: 7, caption: '<img src=x onerror=alert(1)>' }) } })
        await settle()

        expect(wrapper.text()).toContain('No renderer is registered for the widget type sparkline-chart.')
        expect(wrapper.find('.v-alert').exists()).toBe(true)
        expect(wrapper.find('.text-h4').exists()).toBe(false) // the stat card's figure
        expect(wrapper.text()).not.toContain('7')
        expect(wrapper.find('img').exists()).toBe(false)
        wrapper.unmount()
    })

    it('renders an extension type with the card a runtime extension registered through defineWidget, handing it the data as the server answered', async () => {
        useVectorExtensions().widgets.set('sparkline-chart', Sparkline)
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('sparkline-chart'), load: load('sparkline-chart', { samples: [3, 1, 4] }) } })
        await settle()

        expect(wrapper.find('.sparkline').text()).toBe('samples 3,1,4 for card')
        expect(wrapper.text()).not.toContain('No renderer')
        wrapper.unmount()
    })

    it('renders an extension type with the card app.config vector.widgets maps it to', async () => {
        // a component rather than a registered name, as the card accepts either
        const declared = useAppConfig().vector.widgets as Record<string, unknown>
        declared['sparkline-chart'] = Sparkline
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('sparkline-chart'), load: load('sparkline-chart', { samples: [2, 7] }) } })
        await settle()

        expect(wrapper.find('.sparkline').text()).toBe('samples 2,7 for card')
        expect(wrapper.text()).not.toContain('No renderer')
        wrapper.unmount()
    })

    it('swaps the notice for a card registered after it mounted, as a slow runtime extension does', async () => {
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('sparkline-late'), load: load('sparkline-late', { samples: [2, 7] }) } })
        await settle()
        expect(wrapper.text()).toContain('No renderer is registered for the widget type sparkline-late.')

        useVectorExtensions().widgets.set('sparkline-late', Sparkline)
        await nextTick()

        expect(wrapper.find('.sparkline').text()).toBe('samples 2,7 for card')
        expect(wrapper.text()).not.toContain('No renderer')
        wrapper.unmount()
    })

    it('draws a type with the card the application registered for it instead, handing it the data as the server answered', async () => {
        useVectorExtensions().widgets.set('stat', Gauge)
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('stat'), load: load('stat', { value: 7, previous: null, format: 'number', caption: null }) } })
        await settle()

        expect(wrapper.find('.gauge').text()).toBe('gauge 7 for card')
        wrapper.unmount()
    })

    it('swaps in a card registered after it mounted, as a slow extension module does', async () => {
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('progress'), load: load('progress', { value: 3, target: 10, format: 'number', caption: null }) } })
        await settle()
        expect(wrapper.find('.gauge').exists()).toBe(false)

        useVectorExtensions().widgets.set('progress', Gauge)
        await nextTick()

        expect(wrapper.find('.gauge').text()).toBe('gauge 3 for card')
        wrapper.unmount()
    })
})
