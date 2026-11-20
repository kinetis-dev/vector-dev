import { describe, expect, it } from 'vitest'
import { createDataStreamParser, readDataStream, type DataStreamFrame } from '#vector-ai/app/utils/assistantStream'
import { linkRecords } from '#vector-ai/app/utils/recordLinks'

// The data-stream parser, fed the way a network feeds it, and the record
// links an answer's `resource#id` references become.

const STREAM = [
    'data: {"type":"start","messageId":"msg_1"}',
    'data: {"type":"tool-input-start","toolCallId":"call_0_0","toolName":"vector_query"}',
    'data: {"type":"tool-output-available","toolCallId":"call_0_0","output":"Data returned by the admin."}',
    'data: {"type":"text-start","id":"text_1"}',
    'data: {"type":"text-delta","id":"text_1","delta":"Low on stock: "}',
    'data: {"type":"text-delta","id":"text_1","delta":"products#3 — “é”."}',
    'data: {"type":"text-end","id":"text_1"}',
    'data: {"type":"finish"}',
].map(frame => `${frame}\n\n`).join('')

function parse(chunks: string[]): DataStreamFrame[] {
    const frames: DataStreamFrame[] = []
    const parser = createDataStreamParser(frame => frames.push(frame))
    chunks.forEach(chunk => parser.push(chunk))
    parser.end()
    return frames
}

describe('the assistant\'s data stream', () => {
    it('parses every frame of a whole stream', () => {
        const frames = parse([STREAM])

        expect(frames.map(f => f.type)).toEqual(['start', 'tool-input-start', 'tool-output-available', 'text-start', 'text-delta', 'text-delta', 'text-end', 'finish'])
        expect(frames.filter(f => f.type === 'text-delta').map(f => f.delta).join('')).toBe('Low on stock: products#3 — “é”.')
    })

    it('parses the same frames however the chunks cut them', () => {
        const whole = parse([STREAM])

        for (const size of [1, 2, 7, 33]) {
            const chunks: string[] = []
            for (let at = 0; at < STREAM.length; at += size) chunks.push(STREAM.slice(at, at + size))
            expect(parse(chunks)).toEqual(whole)
        }
    })

    it('reads a byte stream cut inside a multi-byte character', async () => {
        const bytes = new TextEncoder().encode(STREAM)
        const cut = bytes.indexOf(0xE2) + 1 // inside the dash's three bytes
        const body = new ReadableStream<Uint8Array>({
            start(controller) {
                controller.enqueue(bytes.slice(0, cut))
                controller.enqueue(bytes.slice(cut))
                controller.close()
            },
        })
        const frames: DataStreamFrame[] = []

        await readDataStream(body, frame => frames.push(frame))

        expect(frames).toEqual(parse([STREAM]))
    })

    it('carries the error frame, CRLF line ends, and a last frame without its blank line', () => {
        const frames = parse(['data: {"type":"text-delta","delta":"Low"}\r', '\n\r\n', 'data: {"type":"error","errorText":"The run failed."}'])

        expect(frames).toEqual([{ type: 'text-delta', delta: 'Low' }, { type: 'error', errorText: 'The run failed.' }])
    })

    it('skips what is not a frame', () => {
        expect(parse([': a comment\n\ndata: [DONE]\n\ndata: not json\n\ndata: ["a"]\n\ndata: {"no":"type"}\n\nevent: x\ndata: {"type":"finish"}\n\n'])).toEqual([{ type: 'finish' }])
    })
})

describe('record links', () => {
    const slugs = new Set(['products', 'order-lines'])

    it('links a reference to a known resource, and leaves an unknown slug as text', () => {
        const html = linkRecords('<p>See products#12, <code>order-lines#7</code> and secrets#1.</p>', slugs, '/admin')
        const holder = document.createElement('div')
        holder.innerHTML = html

        const links = [...holder.querySelectorAll('a[data-record-link]')]
        expect(links.map(a => [a.getAttribute('href'), a.textContent])).toEqual([['/admin/r/products/12', 'products#12'], ['/admin/r/order-lines/7', 'order-lines#7']])
        expect(holder.textContent).toBe('See products#12, order-lines#7 and secrets#1.')
    })

    it('touches nothing inside a link, and needs a whole slug', () => {
        const html = linkRecords('<p><a href="https://example.com">products#1</a> myproducts#2 x-products#3</p>', slugs, '')

        expect(html).not.toContain('data-record-link')
    })
})
