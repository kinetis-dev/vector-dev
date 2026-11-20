/**
 * The largest body POST /vector/ai/assistant takes, in bytes: the
 * server's `AssistantController::MAX_BODY`, which refuses a larger one
 * with a 422 `too_large`. The two must match; a test reads the PHP
 * constant to keep them so.
 */
export const ASSISTANT_MAX_BODY_BYTES = 65_536

/** The most messages one turn sends: the server's `AssistantController::MAX_MESSAGES`. */
export const ASSISTANT_MAX_MESSAGES = 40

/**
 * The longest single message, in characters — Unicode code points, as
 * the server's `mb_strlen` counts them, not UTF-16 units: the server's
 * `AssistantController::MAX_MESSAGE`, which refuses a longer one with a
 * 422. A test reads the PHP constant to keep the two equal.
 */
export const ASSISTANT_MAX_MESSAGE_CHARS = 8000

/** What ends an answer cut to ASSISTANT_MAX_MESSAGE_CHARS. */
export const TRUNCATED_MARK = '…'

/** What a turn leaves unused under ASSISTANT_MAX_BODY_BYTES. */
export const ASSISTANT_BODY_HEADROOM = 1024

/** One message of the conversation as the server takes it. */
export type AssistantMessage = { role: 'user' | 'assistant', content: string } | { role: 'approval', id: string }

/** The bytes $value takes as the request body: its JSON, as UTF-8 — what the fetch sends, not JavaScript's UTF-16 length. */
export function jsonBytes(value: unknown): number {
    return new TextEncoder().encode(JSON.stringify(value)).length
}

/**
 * $message within ASSISTANT_MAX_MESSAGE_CHARS: an assistant's answer
 * longer than that — one near the model's output cap can be — is cut,
 * ending with TRUNCATED_MARK, rather than dropped, so the conversation
 * still alternates between the user and the assistant. Counted in code
 * points, as the server counts them, and never cut inside a surrogate pair.
 */
export function withinMessageLimit(message: AssistantMessage): AssistantMessage {
    if (message.role !== 'assistant') return message
    const chars = Array.from(message.content)
    if (chars.length <= ASSISTANT_MAX_MESSAGE_CHARS) return message
    return { role: 'assistant', content: chars.slice(0, ASSISTANT_MAX_MESSAGE_CHARS - 1).join('') + TRUNCATED_MARK }
}

/**
 * $messages, each assistant answer cut to ASSISTANT_MAX_MESSAGE_CHARS
 * (withinMessageLimit), trimmed from the start until the body $body builds from them
 * fits ASSISTANT_MAX_BODY_BYTES less ASSISTANT_BODY_HEADROOM, measured as
 * the UTF-8 bytes sent — a Hebrew, Arabic or CJK character takes two or
 * three — and then to a user's message: a conversation that opens with
 * the assistant, or with an approval whose proposal was trimmed away, is
 * refused by a provider such as Bedrock. The last message is always
 * kept.
 */
export function fitAssistantMessages<T>(messages: AssistantMessage[], body: (messages: AssistantMessage[]) => T): AssistantMessage[] {
    const limit = ASSISTANT_MAX_BODY_BYTES - ASSISTANT_BODY_HEADROOM
    let fitted = messages.slice(-ASSISTANT_MAX_MESSAGES).map(withinMessageLimit)
    while (fitted.length > 1 && jsonBytes(body(fitted)) > limit) fitted = fitted.slice(1)
    while (fitted.length > 1 && fitted[0]!.role !== 'user') fitted = fitted.slice(1)
    return fitted
}
