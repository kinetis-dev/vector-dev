import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { VApp } from 'vuetify/components'
import VxAiAssistant from '#vector-ai/app/components/VxAi/Assistant.vue'
import { DESCRIPTOR_VERSION } from '#vector/app/composables/useVectorSchema'
import { endpoint, standaloneApi, type Received } from './endpoints'

// The assistant's drawer, opened from the app bar: a turn streams in, its
// record references link to the records the admin knows, a failure says
// why and offers Retry, a spent budget says when it renews, a runtime
// that cannot stream falls back to the whole answer, and "New
// conversation" starts afresh. The tools an answer called show as one chip
// a tool, with how many times, leaving out the calls that failed. ⌘J or
// Ctrl+J opens and closes it; the command palette has no entry of its own.

const ON = { filters: false, assistant: true, assistantWrites: false, writing: false, excluded: {} }

function descriptor(ai?: Record<string, unknown>) {
    return {
        version: DESCRIPTOR_VERSION, menu: [], settings: [], dashboards: [], branding: null, ui: { expected: null },
        resources: { products: { slug: 'products', label: 'Products', labelSingular: 'Product', fields: [] } },
        capabilities: { impersonation: false, queue: false, ...(ai ? { ai } : {}) },
    }
}

function sse(...frames: Record<string, unknown>[]): string {
    return frames.map(frame => `data: ${JSON.stringify(frame)}\n\n`).join('')
}

const ANSWER = sse(
    { type: 'start', messageId: 'msg_1' },
    { type: 'tool-input-start', toolCallId: 'call_0_0', toolName: 'vector_query' },
    { type: 'tool-output-available', toolCallId: 'call_0_0', output: 'Data returned by the admin.' },
    { type: 'text-start', id: 't' },
    { type: 'text-delta', id: 't', delta: 'Lowest: products#3, ' },
    { type: 'text-delta', id: 't', delta: 'not secrets#4.' },
    { type: 'text-end', id: 't' },
    { type: 'finish' },
)

const STREAMED = { headers: { 'Content-Type': 'text/event-stream' }, body: ANSWER }

/** The drawer in an application shell, as the layout renders a shell action. */
const Shell = defineComponent({ render: () => h(VApp, () => h(VxAiAssistant)) })

async function mount(ai: Record<string, unknown> | null = ON, attachTo?: Element) {
    useState('vector.schema').value = descriptor(ai ?? undefined)
    const wrapper = await mountSuspended(Shell, attachTo ? { attachTo } : {})
    await flushPromises()
    return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mount>>

async function ask(wrapper: Wrapper, text: string) {
    if (!useVectorAssistant().open.value) await wrapper.find('[data-ai-assistant-toggle]').trigger('click')
    await wrapper.find<HTMLTextAreaElement>('[data-ai-assistant-input] textarea').setValue(text)
    await wrapper.find('[data-ai-assistant-send]').trigger('click')
    await vi.waitFor(() => expect(useVectorAssistant().busy.value).toBe(false))
    await flushPromises()
}

describe('the assistant', () => {
    const cleanups: (() => void)[] = []

    afterEach(() => {
        cleanups.splice(0).forEach(cleanup => cleanup())
        const assistant = useVectorAssistant()
        assistant.reset()
        assistant.open.value = false
        useState('vector.schema').value = null
    })

    it('registers its app-bar action through the admin\'s extension points', () => {
        expect(useVectorExtensions().shellActions.get('ai-assistant')).toBeDefined()
    })

    it.each([
        ['without capabilities.ai', null],
        ['with the assistant off', { ...ON, assistant: false }],
    ])('shows nothing %s', async (_, ai) => {
        const wrapper = await mount(ai)

        expect(wrapper.find('[data-ai-assistant-toggle]').exists()).toBe(false)
        expect(wrapper.find('[data-ai-assistant]').exists()).toBe(false)
        wrapper.unmount()
    })

    it('streams an answer, and links the records the admin knows', async () => {
        standaloneApi()
        const turn = endpoint('/vector/ai/assistant', STREAMED, 'POST')
        cleanups.push(turn.unregister)
        const wrapper = await mount()

        await ask(wrapper, 'Which products are low on stock?')

        const body = turn.requests[0]!.body as { conversationId: string, messages: unknown[], context: Record<string, unknown> }
        expect(turn.requests[0]!.headers.get('accept')).toBe('text/event-stream')
        expect(body.conversationId).toMatch(/^c_[0-9a-f]{32}$/)
        expect(body.messages).toEqual([{ role: 'user', content: 'Which products are low on stock?' }])
        expect(Object.keys(body.context)).toEqual(['path', 'resource', 'recordId'])

        const answer = wrapper.find('[data-ai-assistant-answer]')
        expect(answer.text()).toContain('Lowest: products#3, not secrets#4.')
        const links = answer.findAll('a[data-record-link]')
        expect(links).toHaveLength(1)
        expect(links[0]!.attributes('href')).toMatch(/\/r\/products\/3$/)
        expect(links[0]!.text()).toBe('products#3')
        expect(wrapper.find('[data-ai-assistant-tools]').text()).toContain('vector_query')
        wrapper.unmount()
    })

    it('replays the conversation, and starts a new one on New conversation', async () => {
        standaloneApi()
        const turn = endpoint('/vector/ai/assistant', STREAMED, 'POST')
        cleanups.push(turn.unregister)
        const wrapper = await mount()

        await ask(wrapper, 'First question')
        await ask(wrapper, 'Second question')
        const second = turn.requests[1]!.body as { conversationId: string, messages: { role: string, content: string }[] }
        expect(second.messages).toEqual([
            { role: 'user', content: 'First question' },
            { role: 'assistant', content: 'Lowest: products#3, not secrets#4.' },
            { role: 'user', content: 'Second question' },
        ])

        await wrapper.find('[data-ai-assistant-new]').trigger('click')
        expect(wrapper.find('[data-ai-assistant-empty]').exists()).toBe(true)
        await ask(wrapper, 'Afresh')

        const third = turn.requests[2]!.body as { conversationId: string, messages: unknown[] }
        expect(third.conversationId).not.toBe(second.conversationId)
        expect(third.messages).toEqual([{ role: 'user', content: 'Afresh' }])
        wrapper.unmount()
    })

    /** A settled conversation of $pairs questions and answers, each $text. */
    function seed(pairs: number, text: (i: number) => string) {
        useVectorAssistant().turns.value = Array.from({ length: pairs * 2 }, (_, i) => ({
            id: `t${i}`, role: i % 2 === 0 ? 'user' as const : 'assistant' as const, content: text(i), tools: [], approvals: [], failed: null, streaming: false,
        }))
    }

    it('keeps a long multi-byte conversation within the server\'s body limit, in UTF-8 bytes', async () => {
        standaloneApi()
        const turn = endpoint('/vector/ai/assistant', STREAMED, 'POST')
        cleanups.push(turn.unregister)
        const wrapper = await mount()
        // Hebrew: two bytes a character in UTF-8, one unit in JavaScript's length
        seed(15, i => `${i}: ${'שלום עולם, מה שלומך היום? '.repeat(110)}`)

        await ask(wrapper, 'ומה עכשיו?')
        const sent = turn.requests[0]!.body as { messages: { role: string, content: string }[] }
        expect(new TextEncoder().encode(JSON.stringify(sent)).length).toBeLessThanOrEqual(ASSISTANT_MAX_BODY_BYTES)
        expect(sent.messages.at(-1)).toEqual({ role: 'user', content: 'ומה עכשיו?' })
        expect(sent.messages.length).toBeGreaterThan(1)
        wrapper.unmount()
    })

    it('keeps every replayed message within the server\'s per-message limit after a long answer', async () => {
        standaloneApi()
        const turn = endpoint('/vector/ai/assistant', STREAMED, 'POST')
        cleanups.push(turn.unregister)
        const wrapper = await mount()
        // an answer near the model's output cap: longer than AssistantController::MAX_MESSAGE
        seed(1, i => i === 0 ? 'Write me a long report' : 'Report line. '.repeat(700))

        await ask(wrapper, 'Shorter, please')
        const sent = turn.requests[0]!.body as { messages: { role: string, content: string }[] }
        expect(sent.messages.map(message => message.role)).toEqual(['user', 'assistant', 'user'])
        for (const message of sent.messages) expect(Array.from(message.content).length).toBeLessThanOrEqual(ASSISTANT_MAX_MESSAGE_CHARS)
        expect(sent.messages.at(-1)).toEqual({ role: 'user', content: 'Shorter, please' })
        wrapper.unmount()
    })

    it('refuses a message longer than the server takes, in code points, without sending it or keeping it', async () => {
        standaloneApi()
        const turn = endpoint('/vector/ai/assistant', STREAMED, 'POST')
        cleanups.push(turn.unregister)
        const wrapper = await mount()
        const assistant = useVectorAssistant()
        assistant.open.value = true
        await flushPromises()
        // astral characters: one code point each as the server counts them, two UTF-16 units
        const long = '😀'.repeat(ASSISTANT_MAX_MESSAGE_CHARS + 1)

        await assistant.send(long)
        await flushPromises()

        expect(turn.requests).toHaveLength(0)
        expect(assistant.turns.value).toEqual([])
        expect(assistant.draft.value).toBe(long)
        expect(wrapper.find('[data-ai-assistant-refused]').text()).toContain('8,000 characters')

        // exactly at the limit is sent, and the refusal goes
        await assistant.send('😀'.repeat(ASSISTANT_MAX_MESSAGE_CHARS))
        await vi.waitFor(() => expect(assistant.busy.value).toBe(false))
        await flushPromises()
        expect(turn.requests).toHaveLength(1)
        expect(wrapper.find('[data-ai-assistant-refused]').exists()).toBe(false)

        // and the next turn replays nothing that was refused
        await ask(wrapper, 'And now?')
        const messages = (turn.requests[1]!.body as { messages: { role: string, content: string }[] }).messages
        for (const message of messages) expect(Array.from(message.content).length).toBeLessThanOrEqual(ASSISTANT_MAX_MESSAGE_CHARS)
        wrapper.unmount()
    })

    it('sends a conversation trimmed to its last 40 messages opening with the user\'s', async () => {
        standaloneApi()
        const turn = endpoint('/vector/ai/assistant', STREAMED, 'POST')
        cleanups.push(turn.unregister)
        const wrapper = await mount()
        // 40 settled messages and the new question: the last 40 would open with an answer
        seed(20, i => `message ${i}`)

        await ask(wrapper, 'One more question')
        const sent = turn.requests[0]!.body as { messages: { role: string, content: string }[] }
        expect(sent.messages[0]).toEqual({ role: 'user', content: 'message 2' })
        expect(sent.messages).toHaveLength(39)
        wrapper.unmount()
    })

    it('forgets the conversation when another user is signed in, and on sign-out', async () => {
        standaloneApi()
        const turn = endpoint('/vector/ai/assistant', STREAMED, 'POST')
        cleanups.push(turn.unregister)
        const auth = useVectorAuthStore()
        auth.user = { id: 1, name: 'Ada' }
        cleanups.push(() => { auth.user = null })
        const wrapper = await mount()
        const assistant = useVectorAssistant()

        await ask(wrapper, 'First question')
        const first = assistant.conversationId.value
        expect(assistant.turns.value).toHaveLength(2)

        // another account in the same tab: an impersonation, or a sign-in after the session ended
        auth.user = { id: 2, name: 'Bob' }
        await nextTick()
        expect(assistant.turns.value).toEqual([])
        expect(assistant.open.value).toBe(false)
        expect(assistant.conversationId.value).not.toBe(first)

        await ask(wrapper, 'Second question')
        expect((turn.requests[1]!.body as { messages: unknown[] }).messages).toEqual([{ role: 'user', content: 'Second question' }])
        expect(assistant.turns.value).toHaveLength(2)

        // signing out clears the descriptor before the login page
        useState('vector.schema').value = null
        await nextTick()
        expect(assistant.turns.value).toEqual([])
        wrapper.unmount()
    })

    it('shows a failure in the server\'s words, and Retry asks again', async () => {
        standaloneApi()
        let calls = 0
        const turn = endpoint('/vector/ai/assistant', () => ++calls === 1 ? { status: 503, body: { error: 'The AI provider is not available.' } } : STREAMED, 'POST')
        cleanups.push(turn.unregister)
        const wrapper = await mount()

        await ask(wrapper, 'Which products are low on stock?')
        expect(wrapper.find('[data-ai-assistant-failure]').text()).toContain('The AI provider is not available.')

        await wrapper.find('[data-ai-assistant-retry]').trigger('click')
        await vi.waitFor(() => expect(useVectorAssistant().busy.value).toBe(false))
        await flushPromises()

        expect(wrapper.find('[data-ai-assistant-failure]').exists()).toBe(false)
        expect(wrapper.find('[data-ai-assistant-answer]').text()).toContain('products#3')
        expect((turn.requests[1]!.body as { messages: unknown[] }).messages).toEqual([{ role: 'user', content: 'Which products are low on stock?' }])
        wrapper.unmount()
    })

    it('shows the error frame of a run that failed mid-stream, with Retry', async () => {
        standaloneApi()
        cleanups.push(endpoint('/vector/ai/assistant', { headers: { 'Content-Type': 'text/event-stream' }, body: sse({ type: 'start' }, { type: 'text-delta', id: 't', delta: 'Low' }, { type: 'error', errorText: 'The run failed.' }) }, 'POST').unregister)
        const wrapper = await mount()

        await ask(wrapper, 'Which products are low on stock?')

        expect(wrapper.find('[data-ai-assistant-failure]').text()).toContain('The run failed.')
        expect(wrapper.find('[data-ai-assistant-retry]').exists()).toBe(true)
        wrapper.unmount()
    })

    it('says when a spent budget renews, in the filters\' words', async () => {
        standaloneApi()
        cleanups.push(endpoint('/vector/ai/assistant', { status: 429, headers: { 'Retry-After': '3600' }, body: { error: 'Today\'s AI budget is spent; it renews at midnight UTC.' } }, 'POST').unregister)
        const wrapper = await mount()

        await ask(wrapper, 'Which products are low on stock?')

        const renews = new Date(Date.now() + 3600 * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        expect(wrapper.find('[data-ai-assistant-failure]').text()).toContain(`Today's AI budget is spent; try again after ${renews}.`)
        wrapper.unmount()
    })

    it('says a spent budget in the server\'s words when Retry-After cannot be read', async () => {
        standaloneApi()
        cleanups.push(endpoint('/vector/ai/assistant', { status: 429, body: { error: 'Today\'s AI budget is spent; it renews at midnight UTC.' } }, 'POST').unregister)
        const wrapper = await mount()

        await ask(wrapper, 'Which products are low on stock?')

        expect(wrapper.find('[data-ai-assistant-failure]').text()).toContain('Today\'s AI budget is spent; it renews at midnight UTC.')
        wrapper.unmount()
    })

    it('falls back to the whole answer as JSON where the runtime cannot stream (501)', async () => {
        standaloneApi()
        const turn = endpoint('/vector/ai/assistant', (request: Received) => request.headers.get('accept') === 'application/json'
            ? { body: { text: 'Whole: products#3.', toolCalls: ['vector_query'] } }
            : { status: 501, body: { error: 'Streamed responses are not supported by this runtime.' } }, 'POST')
        cleanups.push(turn.unregister)
        const wrapper = await mount()

        await ask(wrapper, 'Which products are low on stock?')

        expect(turn.requests.map(r => r.headers.get('accept'))).toEqual(['text/event-stream', 'application/json'])
        expect(turn.requests[1]!.body).toEqual(turn.requests[0]!.body)
        expect(wrapper.find('[data-ai-assistant-answer]').text()).toContain('Whole: products#3.')
        expect(wrapper.find('[data-ai-assistant-answer] a[data-record-link]').exists()).toBe(true)
        expect(wrapper.find('[data-ai-assistant-failure]').exists()).toBe(false)
        wrapper.unmount()
    })

    /** The chips above the answer, as they read. */
    function chips(wrapper: Wrapper): string[] {
        return wrapper.findAll('[data-ai-assistant-tool]').map(chip => chip.text())
    }

    it('shows one chip a tool, with how many times, and none for a call that failed', async () => {
        standaloneApi()
        cleanups.push(endpoint('/vector/ai/assistant', { headers: { 'Content-Type': 'text/event-stream' }, body: sse(
            { type: 'start', messageId: 'msg_1' },
            { type: 'tool-input-start', toolCallId: 'call_0_0', toolName: 'vector_query' },
            { type: 'tool-output-error', toolCallId: 'call_0_0', errorText: '"price" is not a sortable field of products.' },
            { type: 'tool-input-start', toolCallId: 'call_1_0', toolName: 'vector_describe' },
            { type: 'tool-output-available', toolCallId: 'call_1_0', output: 'Data returned by the admin.' },
            { type: 'tool-input-start', toolCallId: 'call_2_0', toolName: 'vector_query' },
            { type: 'tool-output-error', toolCallId: 'call_2_0', errorText: 'page: must be greater than 0' },
            { type: 'tool-input-start', toolCallId: 'call_3_0', toolName: 'vector_query' },
            { type: 'tool-output-available', toolCallId: 'call_3_0', output: 'Data returned by the admin.' },
            { type: 'tool-input-start', toolCallId: 'call_4_0', toolName: 'vector_query' },
            { type: 'tool-output-available', toolCallId: 'call_4_0', output: 'Data returned by the admin.' },
            { type: 'tool-input-start', toolCallId: 'call_5_0', toolName: 'vector_get' },
            { type: 'tool-output-error', toolCallId: 'call_5_0', errorText: 'Not found.' },
            { type: 'text-delta', id: 't', delta: 'Lowest: products#3.' },
            { type: 'finish' },
        ) }, 'POST').unregister)
        const wrapper = await mount()

        await ask(wrapper, 'Which products are low on stock?')

        // in the order the chips appeared: the first vector_query failed, and its chip went
        expect(chips(wrapper)).toEqual(['Read with vector_describe', 'Read with vector_query ×2'])
        wrapper.unmount()
    })

    it('shows the whole answer\'s tool calls the same way, also a server\'s that names them only', async () => {
        standaloneApi()
        let toolCalls: unknown = []
        cleanups.push(endpoint('/vector/ai/assistant', (request: Received) => request.headers.get('accept') === 'application/json'
            ? { body: { text: 'Whole: products#3.', toolCalls } }
            : { status: 501, body: { error: 'Streamed responses are not supported by this runtime.' } }, 'POST').unregister)
        const wrapper = await mount()

        toolCalls = [{ name: 'vector_query', ok: false }, { name: 'vector_query', ok: true }, { name: 'vector_update', ok: true }, { name: 'vector_query', ok: true }, { name: 'vector_get', ok: false }]
        await ask(wrapper, 'Which products are low on stock?')
        expect(chips(wrapper)).toEqual(['Read with vector_query ×2', 'Proposed with vector_update'])

        // the names alone, as before outcomes were sent: each one answered
        toolCalls = ['vector_query', 'vector_describe', 'vector_query', 'vector_query']
        await ask(wrapper, 'And the next ones?')
        const answers = wrapper.findAll('[data-ai-assistant-turn="assistant"]')
        expect(answers[1]!.findAll('[data-ai-assistant-tool]').map(chip => chip.text())).toEqual(['Read with vector_query ×3', 'Read with vector_describe'])
        wrapper.unmount()
    })

    it('counts a call whose outcome has not arrived yet, until it fails', () => {
        expect(toolChips([
            { id: 'a', name: 'vector_query', ok: true },
            { id: 'b', name: 'vector_query', ok: null },
            { id: 'c', name: 'vector_get', ok: null },
        ])).toEqual([{ name: 'vector_query', count: 2 }, { name: 'vector_get', count: 1 }])
        expect(toolChips([
            { id: 'a', name: 'vector_query', ok: true },
            { id: 'b', name: 'vector_query', ok: false },
            { id: 'c', name: 'vector_get', ok: false },
        ])).toEqual([{ name: 'vector_query', count: 1 }])
        expect(toolCallsOf([{ name: 'vector_query' }, 'vector_get', { name: 'vector_describe', ok: false }, 7, null])).toEqual([
            { id: null, name: 'vector_query', ok: true },
            { id: null, name: 'vector_get', ok: true },
            { id: null, name: 'vector_describe', ok: false },
        ])
    })

    /** Presses ⌘J (or Ctrl+J with $ctrl) on $target, as a keyboard would, and answers the event. */
    function press(target: EventTarget, init: KeyboardEventInit = { metaKey: true }): KeyboardEvent {
        const event = new KeyboardEvent('keydown', { key: 'j', bubbles: true, cancelable: true, ...init })
        target.dispatchEvent(event)
        return event
    }

    it('opens and closes the drawer on ⌘J, and on Ctrl+J, also from its own input', async () => {
        // in the document, as the admin is: a key pressed in the drawer reaches the window
        const wrapper = await mount(ON, document.body)
        const assistant = useVectorAssistant()

        const opening = press(document.body)
        await nextTick()
        expect(assistant.open.value).toBe(true)
        expect(opening.defaultPrevented).toBe(true)

        press(document.body)
        await nextTick()
        expect(assistant.open.value).toBe(false)

        press(document.body, { ctrlKey: true })
        await nextTick()
        expect(assistant.open.value).toBe(true)

        // focus in the drawer's input: the same keys close it
        const input = wrapper.find<HTMLTextAreaElement>('[data-ai-assistant-input] textarea')
        expect(input.exists()).toBe(true)
        press(input.element, { ctrlKey: true, key: 'J' })
        await nextTick()
        expect(assistant.open.value).toBe(false)
        wrapper.unmount()
    })

    it('leaves ⌘J alone with shift or alt, when something else handled it, and once it is unmounted', async () => {
        const wrapper = await mount()
        const assistant = useVectorAssistant()

        for (const init of [{ metaKey: true, shiftKey: true }, { ctrlKey: true, altKey: true }, {}] as KeyboardEventInit[]) {
            expect(press(document.body, init).defaultPrevented).toBe(false)
        }
        const handled = (event: Event) => event.preventDefault()
        window.addEventListener('keydown', handled, { capture: true })
        press(document.body)
        window.removeEventListener('keydown', handled, { capture: true })
        await nextTick()
        expect(assistant.open.value).toBe(false)

        wrapper.unmount()
        expect(press(document.body).defaultPrevented).toBe(false)
        expect(assistant.open.value).toBe(false)
    })

    it.each([
        ['without capabilities.ai', null],
        ['with the assistant off', { ...ON, assistant: false }],
    ])('ignores ⌘J %s', async (_, ai) => {
        const wrapper = await mount(ai)

        const event = press(document.body)
        await nextTick()

        expect(event.defaultPrevented).toBe(false)
        expect(useVectorAssistant().open.value).toBe(false)
        wrapper.unmount()
    })

    it('adds nothing to the command palette', async () => {
        const wrapper = await mount()
        await nextTick()

        expect(useVectorExtensions().paletteCommands.get('ai-ask')).toBeUndefined()
        expect([...useVectorExtensions().paletteCommands.keys()].filter(name => name.startsWith('ai'))).toEqual([])
        wrapper.unmount()
    })
})
