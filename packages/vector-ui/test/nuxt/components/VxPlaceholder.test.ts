import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import type { Component } from 'vue'
import TextInput from '#vector/app/components/Vx/Input/Text.vue'
import TextareaInput from '#vector/app/components/Vx/Input/Textarea.vue'
import NumberInput from '#vector/app/components/Vx/Input/Number.vue'
import MoneyInput from '#vector/app/components/Vx/Input/Money.vue'
import SelectInput from '#vector/app/components/Vx/Input/Select.vue'
import TagInput from '#vector/app/components/Vx/Input/Tag.vue'
import RelationInput from '#vector/app/components/Vx/Input/Relation.vue'
import RichTextInput from '#vector/app/components/Vx/Input/RichText.vue'
import MarkdownInput from '#vector/app/components/Vx/Input/Markdown.vue'
import type { VectorField } from '#vector/app/types/vector'
import { endpoint, standaloneApi } from '../endpoints'
import { field } from '../fixtures'

// placeholder(): the hint inside an empty text box, wherever the input has one.

const mounted: VueWrapper[] = []
const cleanups: (() => void)[] = []

beforeEach(() => standaloneApi())
afterEach(() => {
    mounted.splice(0).forEach(wrapper => wrapper.unmount())
    cleanups.splice(0).forEach(unregister => unregister())
})

async function mountInput(component: Component, input: VectorField, extra: Record<string, unknown> = {}) {
    const wrapper = await mountSuspended(component, { props: { field: input, rules: [], errorMessages: [], modelValue: null, record: {}, resource: 'products', ...extra } })
    mounted.push(wrapper)
    await flushPromises()
    return wrapper
}

const options = [{ value: 'a', label: 'A', color: null }]

describe('placeholder()', () => {
    it('shows in every text box of a plain input', async () => {
        const cases: [string, Component, VectorField][] = [
            ['string', TextInput, field({ name: 'sku', placeholder: 'Such as KB-0042' })],
            ['email', TextInput, field({ name: 'email', type: 'email', placeholder: 'Such as KB-0042' })],
            ['text', TextareaInput, field({ name: 'notes', type: 'text', placeholder: 'Such as KB-0042' })],
            ['number', NumberInput, field({ name: 'stock', type: 'number', placeholder: 'Such as KB-0042' })],
            ['money', MoneyInput, field({ name: 'price', type: 'money', props: { currency: 'EUR' }, placeholder: 'Such as KB-0042' })],
            ['searchable select', SelectInput, field({ name: 'size', type: 'select', options, autocomplete: true, placeholder: 'Such as KB-0042' })],
            ['tag', TagInput, field({ name: 'keywords', type: 'tag', placeholder: 'Such as KB-0042' })],
        ]

        for (const [name, component, input] of cases) {
            const wrapper = await mountInput(component, input)
            expect(wrapper.find('input, textarea').attributes('placeholder'), name).toBe('Such as KB-0042')
        }
    })

    it('leaves a plain input without one when none is set', async () => {
        const wrapper = await mountInput(TextInput, field({ name: 'sku' }))
        expect(wrapper.find('input').attributes('placeholder')).toBeUndefined()
    })

    it('replaces a relation\'s own hint, which stays when none is set', async () => {
        const options = endpoint('/vector/r/products/fields/category/options', { body: { data: [] } })
        cleanups.push(options.unregister)
        const relation = (placeholder?: string) => field({ name: 'category', type: 'belongsTo', relation: { kind: 'belongsTo', resource: 'categories', titleField: 'name' }, ...(placeholder ? { placeholder } : {}) })

        const own = await mountInput(RelationInput, relation('Pick the shelf'))
        expect(own.find('input').attributes('placeholder')).toBe('Pick the shelf')

        const builtIn = await mountInput(RelationInput, relation())
        expect(builtIn.find('input').attributes('placeholder')).toBe('Type to search')
    })

    it('shows in an empty rich-text or Markdown editor, never the help text', async () => {
        for (const [name, component, input] of [
            ['richtext', RichTextInput, field({ name: 'body', type: 'richtext', help: 'Shown below', placeholder: 'Write the story', richtext: { features: ['bold'], imageField: null, mentions: null, storesHtml: false, maxBytes: 1000 } })],
            ['markdown', MarkdownInput, field({ name: 'notes', type: 'markdown', help: 'Shown below', placeholder: 'Write the story' })],
        ] as [string, Component, VectorField][]) {
            const wrapper = await mountInput(component, input)
            await vi.waitFor(() => expect(wrapper.find('[data-placeholder]').exists(), name).toBe(true))
            expect(wrapper.find('[data-placeholder]').attributes('data-placeholder'), name).toBe('Write the story')
        }
    })
})
