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

const settle = async () => {
    await flushPromises()
    await new Promise(resolve => setTimeout(resolve, 20))
    await flushPromises()
}

describe('VxWidgetCard', () => {
    afterEach(() => {
        useVectorExtensions().widgets.delete('stat')
        useVectorExtensions().widgets.delete('progress')
    })

    it('draws a built-in type with its own card', async () => {
        const wrapper = await mountSuspended(VxWidgetCard, { props: { widget: widget('stat'), load: load('stat', { value: 1234, previous: null, format: 'number', caption: null }) } })
        await settle()

        expect(wrapper.text()).toContain('1,234')
        expect(wrapper.find('.gauge').exists()).toBe(false)
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
