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
            buffer = (buffer + chunk).replace(/\r\n/g, '\n')
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
