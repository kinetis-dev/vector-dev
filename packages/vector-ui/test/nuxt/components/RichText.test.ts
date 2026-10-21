import { describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import type { Editor, JSONContent } from '@tiptap/core'
import { EditorContent } from '@tiptap/vue-3'
import VxInputRichText from '#vector/app/components/Vx/Input/RichText.vue'
import VxInputMarkdown from '#vector/app/components/Vx/Input/Markdown.vue'
import VxCell from '#vector/app/components/Vx/Cell.vue'
import { markdownHtml, richTextExtensions, richTextHtml, sanitizeHtml, sanitizerWorks } from '#vector/app/utils/richtext'
import { field, resource } from '../fixtures'

const DEFAULT_FEATURES = ['bold', 'italic', 'underline', 'strike', 'code', 'heading', 'list', 'blockquote', 'codeBlock', 'horizontalRule', 'link', 'table']

// What the server's Document accepts: every node and mark type, with its attributes.
const SERVER_SCHEMA: Record<string, string[]> = {
    doc: [], paragraph: [], text: [], hardBreak: [],
    bold: [], italic: [], underline: [], strike: [], code: [],
    heading: ['level'], bulletList: [], orderedList: ['start', 'type'], listItem: [],
    blockquote: [], codeBlock: ['language'], horizontalRule: [],
    link: ['href', 'target', 'rel', 'class', 'title'],
    table: [], tableRow: [], tableHeader: ['colspan', 'rowspan', 'colwidth', 'align'], tableCell: ['colspan', 'rowspan', 'colwidth', 'align'],
}

/** Every node and mark in a document, as `type` and its attribute names. */
function inventory(node: JSONContent, found: [string, string[]][] = []): [string, string[]][] {
    found.push([node.type!, Object.keys(node.attrs ?? {})])
    for (const mark of node.marks ?? []) found.push([mark.type, Object.keys(mark.attrs ?? {})])
    for (const child of node.content ?? []) inventory(child, found)
    return found
}

const description = field({ name: 'description', type: 'richtext', richtext: { features: DEFAULT_FEATURES, imageField: null, mentions: null, storesHtml: false, maxBytes: 262_144 } })

async function mountRichText(modelValue: string | null = null) {
    const wrapper = await mountSuspended(VxInputRichText, { props: { field: description, rules: [], errorMessages: [], modelValue, resource: 'products' } })
    await flushPromises()
    const editor = () => wrapper.findComponent(EditorContent).props('editor') as Editor
    await vi.waitFor(() => expect(editor()).toBeTruthy())
    return { wrapper, editor }
}

const emitted = (wrapper: VueWrapper) => (wrapper.emitted('update:modelValue') ?? []).at(-1)?.[0] as string | null | undefined

/**
 * The safety property, whatever DOM runs the test: parsed, the output
 * holds nothing that runs, loads or styles. (happy-dom breaks DOMPurify,
 * so there the layer's fail-closed guard shows everything as text — still
 * inert; the markup itself is checked where the sanitizer works, and in
 * Chromium by the end-to-end suite.)
 */
function expectInert(html: string) {
    const holder = document.createElement('div')
    holder.innerHTML = html
    expect(holder.querySelectorAll('script, iframe, object, embed, svg, style, link, meta, form')).toHaveLength(0)
    for (const element of holder.querySelectorAll('*')) {
        for (const { name, value } of [...element.attributes]) {
            expect(name.startsWith('on'), `${element.tagName} ${name}`).toBe(false)
            expect(['style', 'src', 'srcdoc', 'class'], `${element.tagName} ${name}`).not.toContain(name)
            if (name === 'href') expect(value).not.toMatch(/^\s*(javascript|data|vbscript):/i)
        }
    }
    return holder
}

describe('the sanitizer', () => {
    const dirty = '<p style="color:red" onclick="alert(1)" class="x">Hi <strong>there</strong><script>alert(1)</script>'
        + '<a href="javascript:alert(1)">bad</a> <a href="https://example.com" target="_self" onmouseover="x()">good</a>'
        + '<img src="https://evil.example/x.png" onerror="alert(1)" data-vector-key="vector/p/f/a.png" alt="A">'
        + '<iframe src="https://evil.example"></iframe><svg><circle/></svg><style>p{}</style></p>'

    it('leaves nothing that runs, loads or styles', () => {
        const holder = expectInert(sanitizeHtml(dirty))
        expect(holder.textContent).toContain('there')
        expect(holder.textContent).toContain('good')
    })

    it.runIf(sanitizerWorks())('keeps the schema\'s markup', () => {
        const clean = sanitizeHtml(dirty)
        expect(clean).toContain('<strong>there</strong>')
        expect(clean).toContain('<a href="https://example.com" target="_blank" rel="noopener noreferrer nofollow">good</a>')
        expect(clean).toContain('<a target="_blank" rel="noopener noreferrer nofollow">bad</a>')
        expect(clean).toContain('data-vector-key="vector/p/f/a.png"')
    })

    it('renders a document through the schema before sanitizing it', () => {
        const extensions = richTextExtensions({ features: DEFAULT_FEATURES })
        const doc = { type: 'doc', content: [
            { type: 'paragraph', content: [{ type: 'text', text: '<b>not markup</b>' }] },
            { type: 'paragraph', content: [{ type: 'text', text: 'x', marks: [{ type: 'link', attrs: { href: 'javascript:alert(1)' } }] }] },
        ] }

        const holder = expectInert(richTextHtml(JSON.stringify(doc), extensions))
        expect(holder.textContent).toContain('not markup')
        expect(holder.querySelector('b')).toBeNull()
        // a node the schema lacks is not shown at all
        expect(richTextHtml(JSON.stringify({ type: 'doc', content: [{ type: 'iframe' }] }), extensions)).toBe('')
    })

    it('renders Markdown with HTML in the source turned off', () => {
        const holder = expectInert(markdownHtml('# Title\n\n**bold** <script>alert(1)</script> [x](javascript:alert(1)) https://example.com'))
        expect(holder.textContent).toContain('Title')
        expect(holder.textContent).toContain('alert(1)')

        if (sanitizerWorks()) {
            expect(holder.querySelector('h1')?.textContent).toBe('Title')
            expect(holder.querySelector('a[href="https://example.com"]')).not.toBeNull()
        }
    })
})

describe('the rich-text input', () => {
    it('emits the document as JSON text, and null when empty', async () => {
        const { wrapper, editor } = await mountRichText()

        editor().commands.insertContent('Hello')
        await flushPromises()
        expect(JSON.parse(emitted(wrapper)!)).toEqual({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Hello' }] }] })

        editor().commands.clearContent(true)
        await flushPromises()
        expect(emitted(wrapper)).toBeNull()
    })

    it('drops pasted content outside the schema', async () => {
        const { wrapper, editor } = await mountRichText()

        editor().commands.insertContent('<p style="color:red" onclick="alert(1)">Kept <script>alert(1)</script><a href="javascript:alert(1)">link</a>'
            + ' <iframe src="https://evil.example"></iframe><span style="font-size:40px">sized</span></p><img src="https://evil.example/a.png">')
        await flushPromises()

        const json = emitted(wrapper)!
        expect(json).not.toMatch(/script|onclick|iframe|style|javascript|evil/)
        const doc = JSON.parse(json) as JSONContent
        for (const [type, attrs] of inventory(doc)) {
            expect(Object.keys(SERVER_SCHEMA), `the server accepts ${type}`).toContain(type)
            for (const attr of attrs) expect(SERVER_SCHEMA[type], `the server accepts ${type}.${attr}`).toContain(attr)
        }
        expect(JSON.stringify(doc)).toContain('Kept')
    })

    it('produces only what the server accepts, for every feature', async () => {
        const { wrapper, editor } = await mountRichText()

        editor().chain()
            .insertContent('<h2>Title</h2><p><strong>b</strong><em>i</em><u>u</u><s>s</s><code>c</code><a href="https://example.com">l</a></p>'
                + '<ul><li><p>one</p></li></ul><ol><li><p>two</p></li></ol><blockquote><p>q</p></blockquote><pre><code>x = 1</code></pre><hr><p>end</p>')
            .insertTable({ rows: 2, cols: 2, withHeaderRow: true })
            .run()
        await flushPromises()

        const found = inventory(JSON.parse(emitted(wrapper)!) as JSONContent)
        expect(new Set(found.map(([type]) => type))).toEqual(new Set(Object.keys(SERVER_SCHEMA).filter(t => t !== 'hardBreak')))
        for (const [type, attrs] of found) {
            for (const attr of attrs) expect(SERVER_SCHEMA[type], `${type}.${attr}`).toContain(attr)
        }
    })

    it('produces images and mentions the server accepts too', async () => {
        const full = field({ name: 'description', type: 'richtext', richtext: { features: [...DEFAULT_FEATURES, 'image', 'mention'], imageField: 'imageKey', mentions: 'category', storesHtml: false, maxBytes: 262_144 } })
        const wrapper = await mountSuspended(VxInputRichText, { props: { field: full, rules: [], errorMessages: [], modelValue: null, resource: 'products' } })
        await flushPromises()
        const editor = () => wrapper.findComponent(EditorContent).props('editor') as Editor
        await vi.waitFor(() => expect(editor()).toBeTruthy())

        editor().commands.insertContent([
            { type: 'paragraph', content: [{ type: 'mention', attrs: { id: '7', label: 'Kitchen' } }] },
            { type: 'image', attrs: { src: 'vector/products/imageKey/0123.png', alt: 'Photo' } },
        ])
        await flushPromises()

        const schema: Record<string, string[]> = { ...SERVER_SCHEMA, image: ['src', 'alt', 'title', 'width', 'height'], mention: ['id', 'label', 'mentionSuggestionChar'] }
        const found = inventory(JSON.parse(emitted(wrapper)!) as JSONContent)
        expect(found.map(([type]) => type)).toEqual(expect.arrayContaining(['image', 'mention']))
        for (const [type, attrs] of found) {
            for (const attr of attrs) expect(schema[type], `${type}.${attr}`).toContain(attr)
        }
    })

    it('loads a stored document, and a stored HTML value through the schema', async () => {
        const stored = JSON.stringify({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Stored' }] }] })
        const { editor } = await mountRichText(stored)
        expect(editor().getText()).toBe('Stored')

        const html = await mountRichText('<p>From <b>HTML</b><script>alert(1)</script></p>')
        expect(html.editor().getText()).toBe('From HTML')
        expect(JSON.stringify(html.editor().getJSON())).not.toContain('script')
    })
})

describe('the rich-text cell', () => {
    it('shows the document as sanitized HTML on the record page and as text in a list', async () => {
        const value = JSON.stringify({ type: 'doc', content: [
            { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Title' }] },
            { type: 'paragraph', content: [{ type: 'text', text: 'Body <img src=x onerror=alert(1)>' }] },
        ] })
        const props = (context: 'index' | 'show') => ({ field: description, value, record: { id: 1 }, resource: resource({ slug: 'products' }), context })

        const show = await mountSuspended(VxCell, { props: props('show') })
        await vi.waitFor(() => expect(show.find('.vx-richtext-view').exists()).toBe(true))
        const view = expectInert(show.find('.vx-richtext-view').element.innerHTML)
        expect(view.textContent).toContain('Title')
        expect(view.querySelector('img')).toBeNull()
        if (sanitizerWorks()) expect(show.find('.vx-richtext-view h2').text()).toBe('Title')

        const index = await mountSuspended(VxCell, { props: props('index') })
        await vi.waitFor(() => expect(index.text()).toBe('Title Body <img src=x onerror=alert(1)>'))
    })
})

describe('the Markdown input', () => {
    // every construct the toolbar offers, in the form @tiptap/markdown writes it
    const fixture = [
        '# Heading one',
        '',
        '## Heading two',
        '',
        '**bold** *italic* ~~struck~~ `code` [link](https://example.com)',
        '',
        '- one',
        '- two',
        '',
        '1. first',
        '2. second',
        '',
        '> quoted',
        '',
        '```',
        'echo 1;',
        '```',
        '',
        '---',
        '',
        'The end.',
    ].join('\n')

    it('round-trips its toolbar\'s constructs without loss', async () => {
        const reply = field({ name: 'body', type: 'markdown', markdown: { toolbar: ['bold', 'italic', 'strike', 'code', 'heading', 'list', 'blockquote', 'codeBlock', 'horizontalRule', 'link'] } })
        const wrapper = await mountSuspended(VxInputMarkdown, { props: { field: reply, rules: [], errorMessages: [], modelValue: fixture } })
        await flushPromises()
        const editor = () => wrapper.findComponent(EditorContent).props('editor') as Editor & { getMarkdown(): string }
        await vi.waitFor(() => expect(editor()?.getText()).toContain('Heading one'))

        expect(editor().getMarkdown().trim()).toBe(fixture)
    })

    it('keeps the source as it is typed in the source view', async () => {
        const reply = field({ name: 'body', type: 'markdown', markdown: { toolbar: ['bold'] } })
        const wrapper = await mountSuspended(VxInputMarkdown, { props: { field: reply, rules: [], errorMessages: [], modelValue: 'plain' } })
        await flushPromises()

        await wrapper.findAll('button').find(b => b.text().includes('Source'))!.trigger('click')
        await wrapper.find('textarea').setValue('**now bold**')

        expect(emitted(wrapper)).toBe('**now bold**')
    })
})
