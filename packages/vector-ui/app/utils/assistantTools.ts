// The tools an assistant answer called, as its chips show them: only the
// calls that answered; a call that was a tool error (a `tool-output-error`
// frame, or `ok: false` in the whole answer's `toolCalls`) is the model's
// own detour, not something it read; each tool once, in the order first
// called, with how many times.

/** One tool call of an answer: its tool, and whether it answered; null while its outcome has not arrived. */
export interface AssistantToolCall {
    /** The call's `toolCallId` in the stream; null in a whole answer. */
    id: string | null
    name: string
    ok: boolean | null
}

/** One chip: a tool, and how many of its calls answered (or have not failed yet). */
export interface AssistantToolChip {
    name: string
    count: number
}

/**
 * The whole answer's `toolCalls` as calls: each `{name, ok}`, or a bare
 * name, taken as one that answered.
 */
export function toolCallsOf(toolCalls: unknown): AssistantToolCall[] {
    if (!Array.isArray(toolCalls)) return []
    return toolCalls.flatMap((call: unknown): AssistantToolCall[] => {
        if (typeof call === 'string') return [{ id: null, name: call, ok: true }]
        if (call === null || typeof call !== 'object' || typeof (call as { name?: unknown }).name !== 'string') return []
        const { name, ok } = call as { name: string, ok?: unknown }
        return [{ id: null, name, ok: ok !== false }]
    })
}

/** The chips of $calls: those that did not fail, by tool, in the order first called. */
export function toolChips(calls: readonly AssistantToolCall[]): AssistantToolChip[] {
    const chips = new Map<string, AssistantToolChip>()
    for (const call of calls) {
        if (call.ok === false) continue
        const chip = chips.get(call.name)
        if (chip) chip.count++
        else chips.set(call.name, { name: call.name, count: 1 })
    }
    return [...chips.values()]
}
