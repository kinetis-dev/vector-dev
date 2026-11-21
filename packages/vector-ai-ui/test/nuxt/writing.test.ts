import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { VApp } from 'vuetify/components'
import VxAiWritingAddon from '#vector-ai/app/components/VxAi/WritingAddon.vue'
import VxAiWritingToolbarItem from '#vector-ai/app/components/VxAi/WritingToolbarItem.vue'
import { DESCRIPTOR_VERSION } from '#vector/app/composables/useVectorSchema'
import { endpoint, standaloneApi } from './endpoints'

// Writing help: the menu under a text input and in a rich-text or
// Markdown editor's toolbar, shown only where capabilities.ai.writing is
// on, neither the resource nor the field is kept from AI and the field is
// not one writing help is off for (noWriting). The answer shows before and
// after; Replace puts it in the input (or in place of the selection, or
// of the whole document when nothing is selected), Discard drops it, and
// a failure leaves the text alone. The toolbar item is disabled only
// while the editor holds no text.

const ON = { filters: false, assistant: false, assistantWrites: false, writing: true, excluded: {}, excludedFields: {} }

function descriptor(ai?: Record<string, unknown>) {
    // products have the rich-text description the toolbar item is tried on
    const resources = { products: { slug: 'products', label: 'Products', labelSingular: 'Product', fields: [field('description', 'richtext')] } }
    return { version: DESCRIPTOR_VERSION, resources, menu: [], settings: [], dashboards: [], capabilities: { impersonation: false, queue: false, ...(ai ? { ai } : {}) }, branding: null, ui: { expected: null } }
}

function field(name: string, type: string) {
    return { name, label: name, type, operators: [], sortable: false, searchable: false, filterable: false, views: ['create', 'update'], props: {} }
}

const NOTES = 'Please make sure to call the customer before the delivery.'
const ANSWER = { text: 'Call the customer before delivery.', operation: 'shorten', model: 'fake', truncated: false }

/** Mounts $component inside an application shell, so its menus and dialogs have an overlay container. */
async function shell(component: unknown, props: Record<string, unknown>, route?: string) {
    const Shell = defineComponent({ render: () => h(VApp, () => h(component as never, props)) })
    const wrapper = await mountSuspended(Shell, route === undefined ? {} : { route })
    await flushPromises()
    return wrapper
}

/** Opens the writing menu and picks $operation (menus are teleported to the body), then waits for the call to settle. */
async function pick(wrapper: Awaited<ReturnType<typeof shell>>, operation: string, settled = '[data-ai-writing-result], [data-ai-writing-failure]') {
    await wrapper.find('[data-ai-writing-menu]').trigger('click')
    await flushPromises()
    const item = document.body.querySelector<HTMLElement>(`[data-ai-writing-operation="${operation}"]`)
    expect(item).not.toBeNull()
    item!.click()
    // the addon's answer is in the input's own markup; the toolbar item's, in a dialog on the body
    if (settled !== '') await vi.waitFor(() => expect(wrapper.find(settled).exists() || document.body.querySelector(settled) !== null).toBe(true))
    await flushPromises()
}

/** A stand-in for the Tiptap editor: a document of one paragraph, its selection set by select(). */
function fakeEditor(text: string) {
    const handlers = new Set<() => void>()
    const inserted: { range: { from: number, to: number }, content: unknown }[] = []
    let doc = text
    const selection = { from: 1, to: 1, empty: true, $from: { parent: { isTextblock: true } }, $to: { parent: { isTextblock: true } }, content: () => ({ content: { toJSON: () => [] } }) }
    const chain = {
        focus: () => chain,
        insertContentAt: (range: { from: number, to: number }, content: unknown) => {
            inserted.push({ range, content })
            return chain
        },
        run: () => true,
    }
    const editor = {
        // one paragraph: its text runs from 1 to its length + 1, and the document's content is 2 longer than the text;
        // a range past the document's end throws, as ProseMirror's textBetween does
        state: { selection, doc: { get content() { return { size: doc.length + 2 } }, textBetween: (from: number, to: number) => {
            if (from < 0 || to > doc.length + 2) throw new RangeError(`Position ${to} out of range`)
            return doc.slice(Math.max(from - 1, 0), Math.min(to - 1, doc.length))
        }, toJSON: () => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: doc }] }] }) } },
        schema: { nodes: { paragraph: {} } },
        on: (_: string, handler: () => void) => handlers.add(handler),
        off: (_: string, handler: () => void) => handlers.delete(handler),
        chain: () => chain,
    }
    return {
        editor,
        inserted,
        select(from: number, to: number) {
            Object.assign(selection, { from, to, empty: from === to })
            handlers.forEach(handler => handler())
        },
        type(next: string) {
            doc = next
            handlers.forEach(handler => handler())
        },
    }
}

describe('writing help', () => {
    const cleanups: (() => void)[] = []

    // signed in, so the admin's pages are reachable and say which resource and record they are about
    beforeEach(() => {
        const auth = useVectorAuthStore()
        auth.token = 'test'
        auth.expiresAt = Date.now() + 3_600_000
        auth.user = { id: 1, name: 'Ada' }
    })

    afterEach(() => {
        cleanups.splice(0).forEach(cleanup => cleanup())
        useState('vector.schema').value = null
        useVectorAuthStore().$reset()
        document.body.querySelectorAll('.v-overlay-container').forEach(container => container.replaceChildren())
    })

    it('registers under text inputs and in the editors\' toolbars, through the admin\'s extension points', () => {
        const { inputAddons, editorToolbar } = useVectorExtensions()
        expect(inputAddons.get('ai-writing')?.types).toEqual(['string', 'text'])
        expect(editorToolbar.get('ai-writing')).toBeDefined()
    })

    describe('under a text input', () => {
        const EDIT = '/r/orders/7/edit'
        const CREATE = '/r/orders/create'
        const props = (extra: Record<string, unknown> = {}) => ({ field: field('notes', 'text'), resource: 'orders', record: { id: 7 }, modelValue: NOTES, ...extra })

        it.each([
            ['without capabilities.ai', undefined, {}, EDIT],
            ['with writing off', { ...ON, writing: false }, {}, EDIT],
            ['on a resource kept from AI', { ...ON, excluded: { orders: true } }, {}, EDIT],
            ['on a field kept from AI', { ...ON, excludedFields: { orders: ['notes'] } }, {}, EDIT],
            ['on a field writing help is off for', { ...ON, noWriting: { orders: ['notes'] } }, {}, EDIT],
            ['in a settings form', ON, { resource: '' }, '/settings'],
            // the forms the addon is also mounted under, with a record that has no id
            ['in the list\'s bulk edit', ON, { record: {} }, '/r/orders'],
            ['in an action\'s dialog on a record\'s page', ON, { record: {} }, '/r/orders/7'],
            ['in a form for another resource than the page\'s', ON, { record: {} }, '/r/customers/3/edit'],
            ['in a form for another resource than the create page\'s', ON, { record: {} }, '/r/customers/create'],
        ])('shows nothing %s', async (_, ai, extra, route) => {
            useState('vector.schema').value = descriptor(ai)
            const wrapper = await shell(VxAiWritingAddon, props(extra), route)
            expect(wrapper.find('[data-ai-writing]').exists()).toBe(false)
            wrapper.unmount()
        })

        it('shows on a field another field of the resource is kept back beside', async () => {
            useState('vector.schema').value = descriptor({ ...ON, excludedFields: { orders: ['number'] } })
            const wrapper = await shell(VxAiWritingAddon, props(), EDIT)
            expect(wrapper.find('[data-ai-writing]').exists()).toBe(true)
            wrapper.unmount()
        })

        it('Replace puts the answer in the input, after showing it beside the text; the record is the edit page\'s', async () => {
            standaloneApi()
            const call = endpoint('/vector/ai/writing/orders/notes', { body: ANSWER }, 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const changed = vi.fn()
            // the record the form holds says nothing about which record it is: the page does
            const wrapper = await shell(VxAiWritingAddon, props({ 'record': {}, 'onUpdate:modelValue': changed }), EDIT)

            await pick(wrapper, 'shorten')

            expect(call.requests[0]!.body).toEqual({ operation: 'shorten', text: NOTES, format: 'plain', targetLocale: null, recordId: '7' })
            expect(wrapper.find('[data-ai-writing-before]').text()).toBe(NOTES)
            expect(wrapper.find('[data-ai-writing-after]').text()).toBe(ANSWER.text)
            expect(changed).not.toHaveBeenCalled()

            await wrapper.find('[data-ai-writing-replace]').trigger('click')
            expect(changed).toHaveBeenCalledWith(ANSWER.text)
            expect(wrapper.find('[data-ai-writing-result]').exists()).toBe(false)
            wrapper.unmount()
        })

        it('Discard drops the answer and leaves the input as it was; on the create page there is no record', async () => {
            standaloneApi()
            const call = endpoint('/vector/ai/writing/orders/notes', { body: { ...ANSWER, truncated: true } }, 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const changed = vi.fn()
            const wrapper = await shell(VxAiWritingAddon, props({ 'record': {}, 'onUpdate:modelValue': changed }), CREATE)

            expect(wrapper.find('[data-ai-writing]').exists()).toBe(true)
            await pick(wrapper, 'rewrite')
            expect((call.requests[0]!.body as { recordId: unknown }).recordId).toBeNull()
            expect(wrapper.find('[data-ai-writing-truncated]').exists()).toBe(true)

            await wrapper.find('[data-ai-writing-discard]').trigger('click')
            expect(wrapper.find('[data-ai-writing-result]').exists()).toBe(false)
            expect(changed).not.toHaveBeenCalled()
            wrapper.unmount()
        })

        it('a failure says why and leaves the input as it was', async () => {
            standaloneApi()
            const call = endpoint('/vector/ai/writing/orders/notes', { status: 502, body: { error: 'The AI provider refused the request.' } }, 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const changed = vi.fn()
            const wrapper = await shell(VxAiWritingAddon, props({ 'onUpdate:modelValue': changed }), EDIT)

            await pick(wrapper, 'grammar')

            expect(wrapper.find('[data-ai-writing-failure]').text()).toBe('The AI provider refused the request.')
            expect(wrapper.find('[data-ai-writing-result]').exists()).toBe(false)
            expect(changed).not.toHaveBeenCalled()
            wrapper.unmount()
        })

        it('Translate to… asks for the language picked', async () => {
            standaloneApi()
            const call = endpoint('/vector/ai/writing/orders/notes', { body: { ...ANSWER, operation: 'translate' } }, 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const wrapper = await shell(VxAiWritingAddon, props(), EDIT)

            await pick(wrapper, 'translate', '')
            const german = document.body.querySelector<HTMLElement>('[data-ai-writing-language="de"]')
            expect(german).not.toBeNull()
            german!.click()
            await vi.waitFor(() => expect(call.requests).toHaveLength(1))

            expect(call.requests[0]!.body).toMatchObject({ operation: 'translate', targetLocale: 'de' })
            wrapper.unmount()
        })
    })

    describe('in an editor\'s toolbar', () => {
        const ROUTE = '/r/products/5/edit'

        it('shows on the editor\'s page where writing help is offered', async () => {
            useState('vector.schema').value = descriptor(ON)
            const wrapper = await shell(VxAiWritingToolbarItem, { editor: fakeEditor('Hello').editor, field: field('description', 'richtext') }, ROUTE)
            expect(wrapper.find('[data-ai-writing]').exists()).toBe(true)
            wrapper.unmount()
        })

        it.each([
            ['with writing off', { ...ON, writing: false }],
            ['on a resource kept from AI', { ...ON, excluded: { products: true } }],
            ['on a field kept from AI', { ...ON, excludedFields: { products: ['description'] } }],
            ['on a field writing help is off for', { ...ON, noWriting: { products: ['description'] } }],
        ])('shows nothing %s', async (_, ai) => {
            useState('vector.schema').value = descriptor(ai)
            const wrapper = await shell(VxAiWritingToolbarItem, { editor: fakeEditor('Hello').editor, field: field('description', 'richtext') }, ROUTE)
            expect(wrapper.find('[data-ai-writing]').exists()).toBe(false)
            wrapper.unmount()
        })

        // an editor is also mounted in forms that are no record's own: they would send no record, or the wrong one
        it.each([
            ['in a bulk edit, on the list', '/r/products'],
            ['in an action\'s dialog, on a record\'s page', '/r/products/5'],
            ['on another resource\'s page', '/r/orders/5/edit'],
        ])('shows nothing %s', async (_, route) => {
            useState('vector.schema').value = descriptor(ON)
            const wrapper = await shell(VxAiWritingToolbarItem, { editor: fakeEditor('Hello').editor, field: field('description', 'richtext') }, route)
            expect(wrapper.find('[data-ai-writing]').exists()).toBe(false)
            wrapper.unmount()
        })

        it('shows on the resource\'s create page, and not for a field the resource does not have', async () => {
            useState('vector.schema').value = descriptor(ON)
            const create = await shell(VxAiWritingToolbarItem, { editor: fakeEditor('Hello').editor, field: field('description', 'richtext') }, '/r/products/create')
            expect(create.find('[data-ai-writing]').exists()).toBe(true)
            create.unmount()

            const related = await shell(VxAiWritingToolbarItem, { editor: fakeEditor('Hello').editor, field: field('body', 'richtext') }, ROUTE)
            expect(related.find('[data-ai-writing]').exists()).toBe(false)
            related.unmount()
        })

        it('is enabled with nothing selected while there is text, and disabled only on an empty editor', async () => {
            useState('vector.schema').value = descriptor(ON)
            const fake = fakeEditor(NOTES)
            const wrapper = await shell(VxAiWritingToolbarItem, { editor: fake.editor, field: field('description', 'richtext') }, ROUTE)
            const menu = () => wrapper.find('[data-ai-writing-menu]')

            // nothing selected: the whole text, and the title says so
            expect(menu().attributes('disabled')).toBeUndefined()
            expect(menu().attributes('title')).toBe('Writing help: works on the whole text, or on the part you select')
            fake.select(1, NOTES.length + 1)
            await nextTick()
            expect(menu().attributes('disabled')).toBeUndefined()
            expect(menu().attributes('title')).toBe('Writing help')
            fake.select(4, 4)
            await nextTick()
            expect(menu().attributes('disabled')).toBeUndefined()

            fake.type('   ')
            await nextTick()
            expect(menu().attributes('disabled')).toBeDefined()
            expect(menu().attributes('title')).toBe('Write some text first')
            fake.type(NOTES)
            await nextTick()
            expect(menu().attributes('disabled')).toBeUndefined()
            wrapper.unmount()

            const empty = await shell(VxAiWritingToolbarItem, { editor: fakeEditor('').editor, field: field('description', 'richtext') }, ROUTE)
            expect(empty.find('[data-ai-writing-menu]').attributes('disabled')).toBeDefined()
            empty.unmount()
        })

        it('with nothing selected, sends the whole text and Replace puts the answer in place of all of it', async () => {
            standaloneApi()
            const call = endpoint('/vector/ai/writing/products/description', { body: ANSWER }, 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const fake = fakeEditor(NOTES)
            const wrapper = await shell(VxAiWritingToolbarItem, { editor: fake.editor, field: field('description', 'richtext') }, ROUTE)
            fake.select(4, 4)
            await nextTick()

            await pick(wrapper, 'shorten')

            expect(call.requests[0]!.body).toEqual({ operation: 'shorten', text: NOTES, format: 'plain', targetLocale: null, recordId: '5' })
            document.body.querySelector<HTMLElement>('[data-ai-writing-replace]')!.click()
            await flushPromises()

            // the whole document, as paragraphs: not the cursor's position
            expect(fake.inserted).toEqual([{ range: { from: 0, to: NOTES.length + 2 }, content: [{ type: 'paragraph', content: [{ type: 'text', text: ANSWER.text }] }] }])
            wrapper.unmount()
        })

        it('with nothing selected in a Markdown field, sends the whole document\'s Markdown source', async () => {
            standaloneApi()
            const call = endpoint('/vector/ai/writing/products/description', { body: { ...ANSWER, text: '**Call** the customer.' } }, 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const fake = fakeEditor(NOTES)
            const PARSED = [{ type: 'paragraph', content: [{ type: 'text', text: 'Call', marks: [{ type: 'bold' }] }, { type: 'text', text: ' the customer.' }] }]
            const serialize = vi.fn((_: unknown) => `${NOTES}\n`)
            const parse = vi.fn((_: string) => ({ type: 'doc', content: PARSED }))
            const editor = { ...fake.editor, markdown: { serialize, parse } }
            const wrapper = await shell(VxAiWritingToolbarItem, { editor, field: field('description', 'markdown') }, ROUTE)

            await pick(wrapper, 'shorten')

            expect(serialize).toHaveBeenCalledWith(fake.editor.state.doc.toJSON())
            expect(call.requests[0]!.body).toMatchObject({ text: NOTES, format: 'markdown' })
            document.body.querySelector<HTMLElement>('[data-ai-writing-replace]')!.click()
            await flushPromises()

            expect(fake.inserted).toEqual([{ range: { from: 0, to: NOTES.length + 2 }, content: PARSED }])
            wrapper.unmount()
        })

        it.each([
            ['shorter', 'Call first.'],
            ['the same length', NOTES.toUpperCase()],
        ])('with nothing selected, an edit made while the answer is on its way, %s, is left as it is', async (_, edited) => {
            standaloneApi()
            let answer: (value: { body: unknown }) => void = () => {}
            const call = endpoint('/vector/ai/writing/products/description', () => new Promise<{ body: unknown }>((resolve) => {
                answer = resolve
            }), 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const fake = fakeEditor(NOTES)
            const wrapper = await shell(VxAiWritingToolbarItem, { editor: fake.editor, field: field('description', 'richtext') }, ROUTE)

            await pick(wrapper, 'rewrite', '')
            await vi.waitFor(() => expect(call.requests).toHaveLength(1))
            // typed while the request is pending, before the answer arrives
            fake.type(edited)
            answer({ body: ANSWER })
            await vi.waitFor(() => expect(document.body.querySelector('[data-ai-writing-result]')).not.toBeNull())
            document.body.querySelector<HTMLElement>('[data-ai-writing-replace]')!.click()
            await flushPromises()

            expect(fake.inserted).toEqual([])
            expect(wrapper.find('[data-ai-writing-failure]').attributes('aria-label')).toBe('The text changed meanwhile; ask again.')
            wrapper.unmount()
        })

        it('with nothing selected, Replace leaves a document that changed meanwhile as it is', async () => {
            standaloneApi()
            const call = endpoint('/vector/ai/writing/products/description', { body: ANSWER }, 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const fake = fakeEditor(NOTES)
            const wrapper = await shell(VxAiWritingToolbarItem, { editor: fake.editor, field: field('description', 'richtext') }, ROUTE)

            await pick(wrapper, 'rewrite')
            fake.type(`${NOTES} And one more thing.`)
            document.body.querySelector<HTMLElement>('[data-ai-writing-replace]')!.click()
            await flushPromises()

            expect(fake.inserted).toEqual([])
            expect(wrapper.find('[data-ai-writing-failure]').attributes('aria-label')).toBe('The text changed meanwhile; ask again.')
            wrapper.unmount()
        })

        it('Replace leaves a document that shrank below the selection meanwhile as it is', async () => {
            standaloneApi()
            const call = endpoint('/vector/ai/writing/products/description', { body: ANSWER }, 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const fake = fakeEditor(NOTES)
            const wrapper = await shell(VxAiWritingToolbarItem, { editor: fake.editor, field: field('description', 'richtext') }, ROUTE)
            fake.select(1, NOTES.length + 1)
            await nextTick()

            await pick(wrapper, 'rewrite')
            // shorter than the selection: its old end is past the document's
            fake.type('Call.')
            document.body.querySelector<HTMLElement>('[data-ai-writing-replace]')!.click()
            await flushPromises()

            expect(fake.inserted).toEqual([])
            expect(wrapper.find('[data-ai-writing-failure]').attributes('aria-label')).toBe('The selected text changed; select it and ask again.')
            wrapper.unmount()
        })

        it('an answer to a selection that the document shrank below while it was on its way is offered, and Replace leaves the document as it is', async () => {
            standaloneApi()
            let answer: (value: { body: unknown }) => void = () => {}
            const call = endpoint('/vector/ai/writing/products/description', () => new Promise<{ body: unknown }>((resolve) => {
                answer = resolve
            }), 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const fake = fakeEditor(NOTES)
            const wrapper = await shell(VxAiWritingToolbarItem, { editor: fake.editor, field: field('description', 'richtext') }, ROUTE)
            fake.select(1, NOTES.length + 1)
            await nextTick()

            await pick(wrapper, 'rewrite', '')
            await vi.waitFor(() => expect(call.requests).toHaveLength(1))
            fake.type('Call.')
            answer({ body: ANSWER })
            await vi.waitFor(() => expect(document.body.querySelector('[data-ai-writing-result]')).not.toBeNull())
            document.body.querySelector<HTMLElement>('[data-ai-writing-replace]')!.click()
            await flushPromises()

            expect(fake.inserted).toEqual([])
            expect(wrapper.find('[data-ai-writing-failure]').attributes('aria-label')).toBe('The selected text changed; select it and ask again.')
            wrapper.unmount()
        })

        it('Replace puts the answer in place of the selection, as text', async () => {
            standaloneApi()
            const call = endpoint('/vector/ai/writing/products/description', { body: ANSWER }, 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const fake = fakeEditor(NOTES)
            const wrapper = await shell(VxAiWritingToolbarItem, { editor: fake.editor, field: field('description', 'richtext') }, ROUTE)
            fake.select(1, NOTES.length + 1)
            await nextTick()

            await pick(wrapper, 'shorten')

            expect(call.requests[0]!.body).toEqual({ operation: 'shorten', text: NOTES, format: 'plain', targetLocale: null, recordId: '5' })
            const replace = document.body.querySelector<HTMLElement>('[data-ai-writing-replace]')
            expect(replace).not.toBeNull()
            expect(fake.inserted).toEqual([])
            replace!.click()
            await flushPromises()

            expect(fake.inserted).toEqual([{ range: { from: 1, to: NOTES.length + 1 }, content: { type: 'text', text: ANSWER.text } }])
            wrapper.unmount()
        })

        it('in a Markdown field, sends the selection\'s Markdown source and puts in what the editor\'s Markdown reader makes of the answer', async () => {
            standaloneApi()
            const call = endpoint('/vector/ai/writing/products/description', { body: { ...ANSWER, text: '**Call** the customer.' } }, 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const fake = fakeEditor(NOTES)
            const SOURCE = '**Please** make sure to call the customer.'
            const PARSED = [{ type: 'paragraph', content: [{ type: 'text', text: 'Call', marks: [{ type: 'bold' }] }, { type: 'text', text: ' the customer.' }] }]
            const serialize = vi.fn((_: unknown) => `${SOURCE}\n`)
            const parse = vi.fn((_: string) => ({ type: 'doc', content: PARSED }))
            const editor = { ...fake.editor, markdown: { serialize, parse } }
            const wrapper = await shell(VxAiWritingToolbarItem, { editor, field: field('description', 'markdown') }, ROUTE)
            fake.select(1, NOTES.length + 1)
            await nextTick()

            await pick(wrapper, 'shorten')

            expect(serialize).toHaveBeenCalledWith({ type: 'doc', content: [] })
            expect(call.requests[0]!.body).toEqual({ operation: 'shorten', text: SOURCE, format: 'markdown', targetLocale: null, recordId: '5' })
            document.body.querySelector<HTMLElement>('[data-ai-writing-replace]')!.click()
            await flushPromises()

            expect(parse).toHaveBeenCalledWith('**Call** the customer.')
            expect(fake.inserted).toEqual([{ range: { from: 1, to: NOTES.length + 1 }, content: PARSED }])
            wrapper.unmount()
        })

        it('Discard leaves the document as it was, and so does a selection that changed meanwhile', async () => {
            standaloneApi()
            const call = endpoint('/vector/ai/writing/products/description', { body: ANSWER }, 'POST')
            cleanups.push(call.unregister)
            useState('vector.schema').value = descriptor(ON)
            const fake = fakeEditor(NOTES)
            const wrapper = await shell(VxAiWritingToolbarItem, { editor: fake.editor, field: field('description', 'richtext') }, ROUTE)
            fake.select(1, NOTES.length + 1)
            await nextTick()

            await pick(wrapper, 'rewrite')
            document.body.querySelector<HTMLElement>('[data-ai-writing-discard]')!.click()
            await flushPromises()
            expect(fake.inserted).toEqual([])

            await pick(wrapper, 'rewrite')
            // no shorter than the selection, so the old range is still inside the document
            fake.type('Something else entirely, typed while waiting for the answer to come back.')
            document.body.querySelector<HTMLElement>('[data-ai-writing-replace]')!.click()
            await flushPromises()
            expect(fake.inserted).toEqual([])
            expect(wrapper.find('[data-ai-writing-failure]').exists()).toBe(true)
            wrapper.unmount()
        })
    })
})
