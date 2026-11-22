// The Vercel AI SDK data-stream protocol as POST /vector/ai/assistant
// writes it: Server-Sent Events, each `data: {"type": ..., ...}` and a
// blank line. Chunks arrive cut anywhere, a frame across two of them
// included; a frame that is not a JSON object with a string `type` is
// skipped.

/** One frame of the stream: `type` and the fields that type carries. */
export interface DataStreamFrame {
    type: string
    [field: string]: unknown
}

export interface DataStreamParser {
    /** Takes the next chunk of text, emitting every frame it completes. */
    push: (chunk: string) => void
    /** Emits a last frame the stream ended without a blank line after. */
    end: () => void
}

export function createDataStreamParser(onFrame: (frame: DataStreamFrame) => void): DataStreamParser {
    let buffer = ''

    function emit(event: string) {
        for (const line of event.split('\n')) {
            if (!line.startsWith('data:')) continue
            const payload = line.slice(5).trimStart()
            if (payload === '' || payload === '[DONE]') continue

            let frame: unknown
            try {
                frame = JSON.parse(payload)
            } catch {
                continue
            }

            if (frame !== null && typeof frame === 'object' && !Array.isArray(frame) && typeof (frame as { type?: unknown }).type === 'string') {
                onFrame(frame as DataStreamFrame)
            }
        }
    }

    return {
        push(chunk) {
            // CRLF is normalized on the whole buffer, so a pair split across two chunks is too
            buffer = (buffer + chunk).replaceAll('\r\n', '\n')
            let end = buffer.indexOf('\n\n')
            while (end !== -1) {
                emit(buffer.slice(0, end))
                buffer = buffer.slice(end + 2)
                end = buffer.indexOf('\n\n')
            }
        },
        end() {
            if (buffer.trim() !== '') emit(buffer)
            buffer = ''
        },
    }
}

/** Reads a response body to its end, handing each frame to onFrame as it completes. */
export async function readDataStream(body: ReadableStream<Uint8Array>, onFrame: (frame: DataStreamFrame) => void): Promise<void> {
    const parser = createDataStreamParser(onFrame)
    const reader = body.getReader()
    const decoder = new TextDecoder()

    try {
        for (;;) {
            const { done, value } = await reader.read()
            if (done) break
            parser.push(decoder.decode(value, { stream: true }))
        }
        parser.push(decoder.decode())
        parser.end()
    } finally {
        reader.releaseLock()
    }
}

/**
 * A change the assistant proposed, as the server announces it; a
 * `data-approval` frame of the stream (the protocol's custom data part,
 * its `data`), or an entry of the JSON answer's `approvals`. Everything
 * the admin is shown of the change comes from here, never from the
 * model's prose.
 */
export interface ApprovalFrame {
    type: 'approval'
    /** `apr_` and 26 base32 characters. */
    id: string
    tool: string
    arguments: Record<string, unknown>
    resource: string | null
    recordId: string | null
    /** ISO 8601, UTC. */
    expiresAt: string
}

const APPROVAL_ID = /^apr_[a-z2-7]{26}$/

function isRecord(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/** The approval a value describes (a frame's `data`, or a JSON answer's entry) or null when it is not one. */
export function approvalOf(value: unknown): ApprovalFrame | null {
    if (!isRecord(value) || value.type !== 'approval') return null
    const { id, tool, arguments: args, resource, recordId, expiresAt } = value
    if (typeof id !== 'string' || !APPROVAL_ID.test(id) || typeof tool !== 'string' || !isRecord(args) || typeof expiresAt !== 'string') return null
    if (resource !== null && typeof resource !== 'string') return null
    if (recordId !== null && typeof recordId !== 'string') return null
    return { type: 'approval', id, tool, arguments: args, resource, recordId, expiresAt }
}

/** The approval a stream frame announces: a `data-approval` frame's data, or null for any other frame. */
export function approvalFrame(frame: DataStreamFrame): ApprovalFrame | null {
    return frame.type === 'data-approval' ? approvalOf(frame.data) : null
}

/**
 * The record a `vector_get` tool result carries: the server sends the
 * result after a one-line preamble, then `{data, permissions}` as JSON.
 * Null for anything else.
 */
export function recordOfToolOutput(output: unknown): Record<string, unknown> | null {
    if (typeof output !== 'string') return null
    const json = output.slice(output.indexOf('\n') + 1)
    try {
        const parsed: unknown = JSON.parse(json)
        return isRecord(parsed) && isRecord(parsed.data) ? parsed.data : null
    } catch {
        return null
    }
}
