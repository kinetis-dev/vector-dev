import { readDataStream, type DataStreamFrame } from '../utils/assistantStream'

/** One message of the conversation, as the drawer shows it. */
export interface AssistantTurn {
    id: string
    role: 'user' | 'assistant'
    content: string
    /** The tools the assistant called for this answer, in order. */
    tools: string[]
    /** Why the answer failed, to show beside a Retry; null when it did not. */
    failed: string | null
    streaming: boolean
}

/** What the server answers with `Accept: application/json`. */
interface WholeAnswer { text: string, toolCalls: string[] }

/** The most messages, and the largest body, one turn sends (the server refuses more). */
const MAX_MESSAGES = 40
const MAX_BODY = 60_000

function newConversation(): string {
    return `c_${crypto.randomUUID().replaceAll('-', '')}`
}

function newId(): string {
    return crypto.randomUUID()
}

/**
 * The assistant's conversation: the browser's own, replayed to POST
 * /vector/ai/assistant on every turn (the user's and the assistant's text
 * only), and kept in shared state so the drawer survives navigation and
 * the command palette can open it — for one signed-in user: the layer
 * forgets it when the user changes or signs out. A turn streams in the Vercel AI SDK
 * data-stream protocol; where the runtime cannot stream (501) the same
 * turn is asked again for the whole answer as JSON.
 */
export function useVectorAssistant() {
    const open = useState('vector-ai.assistant.open', () => false)
    const draft = useState('vector-ai.assistant.draft', () => '')
    const turns = useState<AssistantTurn[]>('vector-ai.assistant.turns', () => [])
    const conversationId = useState('vector-ai.assistant.conversation', newConversation)
    const busy = useState('vector-ai.assistant.busy', () => false)
    const api = useVectorApi()
    const host = useVectorHost()
    const { t } = useVectorT()

    /** The conversation as the server takes it: settled text only, newest last, within its caps. */
    function history() {
        let messages = turns.value
            .filter(turn => turn.failed === null && !turn.streaming && turn.content.trim() !== '')
            .map(turn => ({ role: turn.role, content: turn.content }))
            .slice(-MAX_MESSAGES)
        while (messages.length > 1 && JSON.stringify(messages).length > MAX_BODY) messages = messages.slice(1)
        return messages
    }

    function apply(reply: AssistantTurn, frame: DataStreamFrame) {
        if (frame.type === 'text-delta' && typeof frame.delta === 'string') reply.content += frame.delta
        else if (frame.type === 'tool-input-start' && typeof frame.toolName === 'string') reply.tools.push(frame.toolName)
        else if (frame.type === 'error') reply.failed = typeof frame.errorText === 'string' ? frame.errorText : t('ai.assistant.failed')
    }

    async function run() {
        const ctx = host.context()
        const body = {
            conversationId: conversationId.value,
            messages: history(),
            context: { path: ctx.path, resource: ctx.resource ?? null, recordId: ctx.recordId ?? null },
        }
        turns.value.push({ id: newId(), role: 'assistant', content: '', tools: [], failed: null, streaming: true })
        // the reactive copy in the list, so every change shows as it streams
        const reply = turns.value[turns.value.length - 1]!
        busy.value = true

        try {
            const stream = await api<ReadableStream<Uint8Array>>('/vector/ai/assistant', { method: 'POST', body, responseType: 'stream', headers: { Accept: 'text/event-stream' } })
            await readDataStream(stream, frame => apply(reply, frame))
            if (reply.content === '' && reply.failed === null) reply.failed = t('ai.assistant.failed')
        } catch (error) {
            if (statusOf(error) === 501) {
                // the runtime cannot stream: the same turn, answered whole
                try {
                    const answer = await api<WholeAnswer>('/vector/ai/assistant', { method: 'POST', body, headers: { Accept: 'application/json' } })
                    reply.content = answer.text
                    reply.tools = [...answer.toolCalls]
                } catch (whole) {
                    reply.failed = await describe(whole)
                }
            } else {
                reply.failed = await describe(error)
            }
        } finally {
            reply.streaming = false
            busy.value = false
        }
    }

    /** Sends $text as the user's next message. */
    async function send(text: string) {
        const content = text.trim()
        if (content === '' || busy.value) return
        turns.value.push({ id: newId(), role: 'user', content, tools: [], failed: null, streaming: false })
        draft.value = ''
        await run()
    }

    /** Asks again for the answer that failed: the last one. */
    async function retry() {
        const last = turns.value[turns.value.length - 1]
        if (busy.value || last === undefined || last.role !== 'assistant' || last.failed === null) return
        turns.value.pop()
        await run()
    }

    /** A new conversation: nothing of the last one is sent again. */
    function reset() {
        if (busy.value) return
        turns.value = []
        draft.value = ''
        conversationId.value = newConversation()
    }

    /**
     * Forgets the conversation and closes the drawer, even mid-answer:
     * someone else, or nobody, is signed in now, and nothing of the last
     * person's conversation may be shown or sent again. A turn still
     * streaming finishes into a reply no longer shown.
     */
    function forget() {
        turns.value = []
        draft.value = ''
        conversationId.value = newConversation()
        open.value = false
    }

    /** Opens the drawer, and asks $text when there is one. */
    async function ask(text = '') {
        open.value = true
        if (text.trim() !== '') await send(text)
    }

    /** A failure in words: a spent budget says when it renews, when Retry-After can be read; otherwise the server's reason. */
    async function describe(error: unknown): Promise<string> {
        const failed = error as { data?: unknown, response?: { headers?: Headers } } | null

        if (statusOf(error) === 429) {
            const seconds = Number(failed?.response?.headers?.get('Retry-After') ?? Number.NaN)
            if (Number.isFinite(seconds) && seconds > 0) {
                const at = new Date(Date.now() + seconds * 1000)
                return t('ai.filters.budget', { time: at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) })
            }
        }

        // a streamed request's error body is still a stream: read it for the reason
        let data = failed?.data
        if (data instanceof ReadableStream) {
            try {
                data = JSON.parse(await new Response(data).text())
            } catch {
                data = null
            }
        }

        const said = data !== null && typeof data === 'object' ? (data as { error?: unknown }).error : undefined
        if (typeof said === 'string' && said !== '') return said
        return statusOf(error) === 429 ? t('ai.filters.budgetSpent') : t('ai.assistant.failed')
    }

    return { open, draft, turns, busy, conversationId, send, retry, reset, forget, ask }
}

function statusOf(error: unknown): number | undefined {
    const failed = error as { status?: number, statusCode?: number, response?: { status?: number } } | null
    return failed?.status ?? failed?.statusCode ?? failed?.response?.status
}
