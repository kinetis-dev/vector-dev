import { afterEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { EditorView } from '@codemirror/view'
import type { Component } from 'vue'
import CodeInput from '#vector/app/components/Vx/Input/Code.vue'
import JsonInput from '#vector/app/components/Vx/Input/Json.vue'
import CodeCell from '#vector/app/components/Vx/Cell/Code.vue'
import type { VectorField } from '#vector/app/types/vector'
import { field } from '../fixtures'

// The code editor behind the Code and Json inputs: the text round-trips,
// JSON keeps its parse-as-you-type contract, and the record view is a
// read-only editor.

const mounted: VueWrapper[] = []

afterEach(() => {
    mounted.splice(0).forEach(wrapper => wrapper.unmount())
})

const routing = field({ name: 'routingRules', label: 'Routing rules', type: 'code', code: { language: 'yaml', lineNumbers: true, rows: 6 } })
const metadata = field({ name: 'metadata', type: 'json' })

async function mountInput(input: VectorField, modelValue: unknown, extra: Record<string, unknown> = {}) {
    const component: Component = input.type === 'json' ? JsonInput : CodeInput
    const wrapper = await mountSuspended(component, { props: { field: input, rules: [], errorMessages: [], modelValue, ...extra } })
    mounted.push(wrapper)
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('.cm-content').exists()).toBe(true))
    return wrapper
}

/** The editor inside a wrapper, to type into as a user would. */
function editorOf(wrapper: VueWrapper): EditorView {
    const view = EditorView.findFromDOM(wrapper.find('.cm-editor').element as HTMLElement)
    expect(view).not.toBeNull()
    return view!
}

function type(view: EditorView, text: string) {
    view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text } })
}

describe('VxInputCode', () => {
    it('edits the text as typed, labelled by the field and left to right', async () => {
        const wrapper = await mountInput(routing, 'assign: round-robin\n')
        const view = editorOf(wrapper)

        expect(view.state.doc.toString()).toBe('assign: round-robin\n')
        const content = wrapper.find('.cm-content')
        expect(wrapper.find(`#${content.attributes('aria-labelledby')}`).text()).toBe('Routing rules')
        expect(wrapper.find('.vx-code').attributes('dir')).toBe('ltr')
        expect(wrapper.find('.cm-lineNumbers').exists()).toBe(true)

        // the label focuses the editor, as a label focuses its input
        const focus = vi.spyOn(view, 'focus')
        await wrapper.find('label').trigger('click')
        expect(focus).toHaveBeenCalledOnce()

        type(view, 'assign: least-busy\n')
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual(['assign: least-busy\n'])

        // emptied, a nullable field holds no value
        type(view, '')
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([null])

        // a value set from outside replaces the text
        await wrapper.setProps({ modelValue: 'match: {}' })
        expect(view.state.doc.toString()).toBe('match: {}')
    })

    it('applies text typed over a selection itself, leaving no line behind', async () => {
        const wrapper = await mountInput(routing, 'match:\n  labels: [refund]\nassign: round-robin')
        const view = editorOf(wrapper)
        view.dispatch({ selection: { anchor: 0, head: view.state.doc.length } })

        const typed = new InputEvent('beforeinput', { inputType: 'insertText', data: 'x', bubbles: true, cancelable: true })
        view.contentDOM.dispatchEvent(typed)

        expect(typed.defaultPrevented).toBe(true)
        expect(view.state.doc.toString()).toBe('x')
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual(['x'])
    })

    it('is read-only for a read-only field and when disabled', async () => {
        const readonly = await mountInput(field({ ...routing, readonly: true }), 'a: 1')
        expect(editorOf(readonly).state.readOnly).toBe(true)

        const disabled = await mountInput(routing, 'a: 1', { disabled: true })
        expect(editorOf(disabled).state.readOnly).toBe(true)
        expect(disabled.find('.cm-content').attributes('contenteditable')).toBe('false')
    })

    it('shows the placeholder while it is empty', async () => {
        const wrapper = await mountInput(field({ ...routing, placeholder: 'assign: round-robin' }), null)

        expect(wrapper.find('.cm-placeholder').text()).toBe('assign: round-robin')
    })

    it('shows a violation on its frame, like the other inputs', async () => {
        const wrapper = await mountInput(routing, 'a: 1', { errorMessages: ['Must be valid YAML'] })

        expect(wrapper.text()).toContain('Must be valid YAML')
        expect(wrapper.find('.vx-code-frame').classes()).toContain('vx-code-frame--error')
    })
})

describe('VxInputJson on the code editor', () => {
    it('round-trips a document and keeps the invalid-JSON message', async () => {
        const wrapper = await mountInput(metadata, { channel: 'email', tries: 2 })
        const view = editorOf(wrapper)

        expect(JSON.parse(view.state.doc.toString())).toEqual({ channel: 'email', tries: 2 })

        type(view, '{"channel": "web", "tags": ["a"]}')
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([{ channel: 'web', tags: ['a'] }])
        expect(wrapper.text()).not.toContain('Enter a valid JSON object or array.')

        // not JSON, or a bare scalar: sent as the text, which the server refuses, and flagged meanwhile
        type(view, '{"channel": ')
        await flushPromises()
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual(['{"channel": '])
        expect(wrapper.text()).toContain('Enter a valid JSON object or array.')
        expect(view.state.doc.toString(), 'the text stays as typed').toBe('{"channel": ')

        type(view, '42')
        await flushPromises()
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual(['42'])

        type(view, '')
        await flushPromises()
        expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([null])
        expect(wrapper.text()).not.toContain('Enter a valid JSON object or array.')
    })
})

describe('VxCellCode', () => {
    it('shows the first line in a list and a read-only editor on the record', async () => {
        const index = await mountSuspended(CodeCell, { props: { field: routing, value: 'match:\n  labels: [refund]\n', context: 'index' } })
        mounted.push(index)
        expect(index.find('code').text()).toBe('match: …')

        const show = await mountSuspended(CodeCell, { props: { field: routing, value: 'match:\n  labels: [refund]\n', context: 'show' } })
        mounted.push(show)
        await vi.waitFor(() => expect(show.find('.cm-content').exists()).toBe(true))
        expect(editorOf(show).state.readOnly).toBe(true)
        expect(editorOf(show).state.doc.toString()).toBe('match:\n  labels: [refund]\n')
    })
})
