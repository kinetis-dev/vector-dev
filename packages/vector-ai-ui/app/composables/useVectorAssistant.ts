import { approvalFrame, approvalOf, readDataStream, recordOfToolOutput, type ApprovalFrame, type DataStreamFrame } from '../utils/assistantStream'
import { ASSISTANT_MAX_MESSAGE_CHARS, fitAssistantMessages, messageChars, type AssistantMessage } from '../utils/assistantBody'
import { toolCallsOf, type AssistantToolCall } from '../utils/assistantTools'

/**
 * Where a proposed change stands, as the drawer shows it: the server's
 * own statuses, `deciding` while a decision is on its way, and `gone`
 * when the server knows no such proposal for this admin (404).
 */
export type ApprovalState = 'pending' | 'deciding' | 'executed' | 'failed' | 'rejected' | 'expired' | 'gone'

/** A change the assistant proposed, as its card shows it. */
export interface ApprovalCard {
    frame: ApprovalFrame
    /** The record as a `vector_get` of the same run read it, to show each field's value before the change; null when the run did not read it. */
    before: Record<string, unknown> | null
    state: ApprovalState
    /** What the approval executed answered, or why it failed. */
    result: unknown
    /** Why a decision could not be made, to show on the card; null when it was. */
    error: string | null
}

/** One message of the conversation, as the drawer shows it. */
export interface AssistantTurn {
    id: string
    /** `approval` is the admin's decision on a proposal: not shown, but sent so the model hears the outcome. */
    role: 'user' | 'assistant' | 'approval'
    content: string
    /** The tools the assistant called for this answer, in order, each with whether it answered. */
    tools: AssistantToolCall[]
    /** The changes this answer proposed, in order. */
    approvals: ApprovalCard[]
    /** For an `approval` turn, the proposal decided on. */
    approvalId?: string
    /** Why the answer failed, to show beside a Retry; null when it did not. */
    failed: string | null
    streaming: boolean
}

/** What the server answers with `Accept: application/json`: `toolCalls` as `{name, ok}`, or as bare names from an older server. */
interface WholeAnswer { text: string, toolCalls: unknown, approvals?: unknown[] }

/** What a decision answers: `{status, result}`, or for a 409 `{error, status, result?}`. */
interface Decision { status?: unknown, result?: unknown, error?: unknown }

/** The tool calls of one run as its frames tell them: each call's tool, its input as it streamed, and the records `vector_get` read, by `resource#id`. */
interface RunReads {
    calls: Map<string, { tool: string, input: string }>
    records: Map<string, Record<string, unknown>>
}

/** What an answer that proposed changes without saying anything is sent as. */
export const PROPOSED_ONLY = '(Proposed the changes shown for approval.)'

function newConversation(): string {
    return `c_${crypto.randomUUID().replaceAll('-', '')}`
}

function newId(): string {
    return crypto.randomUUID()
}

/**
 * The assistant's conversation: the browser's own, replayed to POST
 * /vector/ai/assistant on every turn (the user's and the assistant's text
 * only), and kept in shared state so the drawer survives navigation —
 * for one signed-in user: the layer
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
    /** Why the last message was not sent, to show above the input; null when it was. */
    const refused = useState<string | null>('vector-ai.assistant.refused', () => null)
    const api = useVectorApi()
    const host = useVectorHost()
    const { t } = useVectorT()

    /**
     * The conversation as the server takes it: settled text, and the
     * proposals decided on — by id only: the server reads the outcome
     * from its own row — newest last. An answer that only
     * proposed changes, with no text, is kept with PROPOSED_ONLY in its
     * place, so its proposals' outcomes do not follow the question directly.
     */
    function history(): AssistantMessage[] {
        return turns.value
            .filter(turn => turn.role === 'approval' || (turn.failed === null && !turn.streaming && (turn.content.trim() !== '' || turn.approvals.length > 0)))
            .map(turn => turn.role === 'approval'
                ? { role: 'approval' as const, id: turn.approvalId ?? '' }
                : { role: turn.role, content: turn.content.trim() === '' ? PROPOSED_ONLY : turn.content })
    }

    function card(frame: ApprovalFrame, reads: RunReads | null): ApprovalCard {
        const before = frame.resource !== null && frame.recordId !== null ? reads?.records.get(`${frame.resource}#${frame.recordId}`) ?? null : null
        return { frame, before, state: 'pending', result: null, error: null }
    }

    function apply(reply: AssistantTurn, frame: DataStreamFrame, reads: RunReads) {
        const callId = typeof frame.toolCallId === 'string' ? frame.toolCallId : null
        if (frame.type === 'text-delta' && typeof frame.delta === 'string') reply.content += frame.delta
        else if (frame.type === 'tool-input-start' && typeof frame.toolName === 'string') {
            reply.tools.push({ id: callId, name: frame.toolName, ok: null })
            if (callId !== null) reads.calls.set(callId, { tool: frame.toolName, input: '' })
        } else if (frame.type === 'tool-input-delta' && callId !== null && typeof frame.inputTextDelta === 'string') {
            const call = reads.calls.get(callId)
            if (call) call.input += frame.inputTextDelta
        } else if (frame.type === 'tool-output-available' && callId !== null) {
            settle(reply, callId, true)
            remember(reads, callId, frame.output)
        } else if (frame.type === 'tool-output-error' && callId !== null) settle(reply, callId, false)
        else if (frame.type === 'error') reply.failed = typeof frame.errorText === 'string' ? frame.errorText : t('ai.assistant.failed')
        else {
            const approval = approvalFrame(frame)
            if (approval !== null) reply.approvals.push(card(approval, reads))
        }
    }

    /** Whether call $callId of the reply answered ($ok), or was a tool error. */
    function settle(reply: AssistantTurn, callId: string, ok: boolean) {
        const call = reply.tools.find(tool => tool.id === callId)
        if (call) call.ok = ok
    }

    /** What a `vector_get` of the run read, by `resource#id`, for a later proposal's "before". */
    function remember(reads: RunReads, callId: string, output: unknown) {
        const call = reads.calls.get(callId)
        if (call?.tool !== 'vector_get') return
        let input: { resource?: unknown, id?: unknown }
        try {
            input = JSON.parse(call.input) as { resource?: unknown, id?: unknown }
        } catch {
            return
        }
        const record = recordOfToolOutput(output)
        if (record !== null && typeof input.resource === 'string' && (typeof input.id === 'string' || typeof input.id === 'number')) {
            reads.records.set(`${input.resource}#${input.id}`, record)
        }
    }

    async function run() {
        const ctx = host.context()
        const build = (messages: AssistantMessage[]) => ({
            conversationId: conversationId.value,
            messages,
            context: { path: ctx.path, resource: ctx.resource ?? null, recordId: ctx.recordId ?? null },
        })
        // within the server's caps, measured as the body it is sent as, and opening with the user
        const body = build(fitAssistantMessages(history(), build))
        turns.value.push({ id: newId(), role: 'assistant', content: '', tools: [], approvals: [], failed: null, streaming: true })
        // the reactive copy in the list, so every change shows as it streams
        const reply = turns.value[turns.value.length - 1]!
        const reads: RunReads = { calls: new Map(), records: new Map() }
        busy.value = true

        try {
            const stream = await api<ReadableStream<Uint8Array>>('/vector/ai/assistant', { method: 'POST', body, responseType: 'stream', headers: { Accept: 'text/event-stream' } })
            await readDataStream(stream, frame => apply(reply, frame, reads))
            if (reply.content === '' && reply.approvals.length === 0 && reply.failed === null) reply.failed = t('ai.assistant.failed')
        } catch (error) {
            if (aiStatusOf(error) === 501) {
                // the runtime cannot stream: the same turn, answered whole
                try {
                    const answer = await api<WholeAnswer>('/vector/ai/assistant', { method: 'POST', body, headers: { Accept: 'application/json' } })
                    reply.content = answer.text
                    reply.tools = toolCallsOf(answer.toolCalls)
                    reply.approvals = (answer.approvals ?? []).map(approvalOf).filter(frame => frame !== null).map(frame => card(frame, null))
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

    /**
     * Sends $text as the user's next message. One longer than the server
     * takes (ASSISTANT_MAX_MESSAGE_CHARS, counted in code points as the
     * server counts it) is not sent and not added to the conversation —
     * every later turn would replay it and be refused — but left in the
     * input to shorten, saying why.
     */
    async function send(text: string) {
        const content = text.trim()
        if (content === '' || busy.value) return
        if (messageChars(content) > ASSISTANT_MAX_MESSAGE_CHARS) {
            draft.value = content
            refused.value = t('ai.assistant.tooLong', { max: ASSISTANT_MAX_MESSAGE_CHARS.toLocaleString() })
            return
        }
        refused.value = null
        turns.value.push({ id: newId(), role: 'user', content, tools: [], approvals: [], failed: null, streaming: false })
        draft.value = ''
        await run()
    }

    /**
     * Approves or rejects a proposal: POST /vector/ai/approvals/{id}/{verb},
     * with no body — the server executes its own stored arguments. A
     * decision that stands (executed, failed or rejected, also when the
     * server answers 409 with a decision made before) is appended to the
     * conversation, once, and the next turn is sent so the assistant says
     * what happened; an expired (410) or unknown (404) proposal only says
     * so on its card.
     */
    async function decide(approval: ApprovalCard, verb: 'approve' | 'reject') {
        if (busy.value || approval.state !== 'pending') return
        approval.state = 'deciding'
        approval.error = null
        let decided: Decision | null = null

        try {
            decided = await api<Decision>(`/vector/ai/approvals/${encodeURIComponent(approval.frame.id)}/${verb}`, { method: 'POST' })
        } catch (error) {
            const status = aiStatusOf(error)
            const data = (error as { data?: unknown } | null)?.data
            if (status === 409 && data !== null && typeof data === 'object') decided = data as Decision
            else if (status === 410) approval.state = 'expired'
            else if (status === 404) approval.state = 'gone'
            else {
                approval.state = 'pending'
                approval.error = await describe(error)
            }
        }

        if (decided === null) return
        const status = decided.status
        // another request is executing it right now: its outcome is that request's to tell,
        // so the card goes back to waiting, saying so, and asking again later shows the outcome
        if (status === 'executing') {
            approval.state = 'pending'
            approval.error = t('ai.assistant.approval.inProgress')
            return
        }
        approval.state = status === 'executed' || status === 'failed' || status === 'rejected' || status === 'expired' ? status : 'gone'
        approval.result = decided.result ?? null
        if (approval.state === 'expired' || approval.state === 'gone') return

        if (turns.value.some(turn => turn.role === 'approval' && turn.approvalId === approval.frame.id)) return
        turns.value.push({ id: newId(), role: 'approval', content: '', tools: [], approvals: [], approvalId: approval.frame.id, failed: null, streaming: false })
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
        refused.value = null
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
        refused.value = null
        conversationId.value = newConversation()
        open.value = false
    }

    /** A failure in words: a spent budget says when it renews, when Retry-After can be read; otherwise the server's reason. */
    async function describe(error: unknown): Promise<string> {
        // a streamed request's error body is still a stream: read it for the reason
        let data = (error as { data?: unknown } | null)?.data
        if (data instanceof ReadableStream) {
            try {
                data = JSON.parse(await new Response(data).text())
            } catch {
                data = null
            }
        }

        return aiFailureMessage(error, t('ai.assistant.failed'), data)
    }

    return { open, draft, turns, busy, refused, conversationId, send, retry, reset, forget, decide }
}
