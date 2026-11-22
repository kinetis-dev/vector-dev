import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { ASSISTANT_BODY_HEADROOM, ASSISTANT_MAX_BODY_BYTES, ASSISTANT_MAX_MESSAGE_CHARS, ASSISTANT_MAX_MESSAGES, TRUNCATED_MARK, fitAssistantMessages, jsonBytes, type AssistantMessage } from '#vector/app/utils/assistantBody'

// What a turn of the assistant sends: within the server's caps, measured
// in the UTF-8 bytes the fetch sends, and opening with the user.

const body = (messages: AssistantMessage[]) => ({ conversationId: 'c_1', messages, context: { path: '/', resource: null, recordId: null } })

describe('the assistant\'s request body', () => {
    it('has the caps AssistantController enforces', () => {
        // the suite runs from this package's own directory, beside kinetis/vector-ai's
        const controller = readFileSync(resolve(process.cwd(), '../vector-ai/src/Http/AssistantController.php'), 'utf8')

        expect(controller).toContain(`public const int MAX_BODY = ${ASSISTANT_MAX_BODY_BYTES};`)
        expect(controller).toContain(`public const int MAX_MESSAGES = ${ASSISTANT_MAX_MESSAGES};`)
        expect(controller).toContain(`public const int MAX_MESSAGE = ${ASSISTANT_MAX_MESSAGE_CHARS};`)
        // the server counts a message in characters (code points), as withinMessageLimit does
        expect(controller).toContain('mb_strlen($content) > self::MAX_MESSAGE')
    })

    it('counts UTF-8 bytes, not UTF-16 units', () => {
        expect(jsonBytes('שלום')).toBe(10)
        expect(jsonBytes('漢字')).toBe(8)
    })

    it('trims from the start until the body fits, with headroom', () => {
        const messages: AssistantMessage[] = Array.from({ length: 20 }, (_, i) => ({ role: i % 2 === 0 ? 'user' : 'assistant', content: '漢'.repeat(7000) }))
        messages.push({ role: 'user', content: 'last' })

        const fitted = fitAssistantMessages(messages, body)
        expect(jsonBytes(body(fitted))).toBeLessThanOrEqual(ASSISTANT_MAX_BODY_BYTES - ASSISTANT_BODY_HEADROOM)
        expect(fitted.at(-1)).toEqual({ role: 'user', content: 'last' })
        expect(fitted[0]!.role).toBe('user')
    })

    it('drops what opens it before the first user message, an approval included', () => {
        const fitted = fitAssistantMessages([
            { role: 'assistant', content: 'An answer' },
            { role: 'approval', id: 'apr_01' },
            { role: 'user', content: 'A question' },
        ], body)

        expect(fitted).toEqual([{ role: 'user', content: 'A question' }])
    })

    it('cuts an answer longer than the server\'s per-message limit, keeping the turns alternating', () => {
        // astral characters: two UTF-16 units, one code point as mb_strlen counts it
        const long = '😀'.repeat(ASSISTANT_MAX_MESSAGE_CHARS + 500)
        const fitted = fitAssistantMessages([
            { role: 'user', content: 'Write a lot' },
            { role: 'assistant', content: long },
            { role: 'user', content: 'Thanks' },
        ], body)

        expect(fitted.map(message => message.role)).toEqual(['user', 'assistant', 'user'])
        const cut = (fitted[1] as { content: string }).content
        expect(Array.from(cut)).toHaveLength(ASSISTANT_MAX_MESSAGE_CHARS)
        expect(cut.endsWith(`😀${TRUNCATED_MARK}`)).toBe(true)
    })

    it('always keeps the last message', () => {
        expect(fitAssistantMessages([{ role: 'assistant', content: 'An answer' }, { role: 'approval', id: 'apr_01' }], body)).toEqual([{ role: 'approval', id: 'apr_01' }])
    })
})
