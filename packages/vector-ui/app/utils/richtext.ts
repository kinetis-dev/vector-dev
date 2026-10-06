import type { AnyExtension, Extensions, JSONContent } from '@tiptap/core'
import { generateHTML, generateJSON, generateText } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { TableKit } from '@tiptap/extension-table'
import Image from '@tiptap/extension-image'
import Placeholder from '@tiptap/extension-placeholder'
import Mention, { type MentionOptions } from '@tiptap/extension-mention'
import DOMPurify from 'dompurify'
import MarkdownIt from 'markdown-it'

/**
 * The rich-text schema on the client: the Tiptap extensions a field's
 * `features` allow — the same node and mark types, with the same
 * attributes, the server's Document validates — and the sanitizer every
 * generated HTML string passes before the one `v-html` that shows it.
 */

/** Every feature a field may allow, as the server names them. */
export const RICH_TEXT_FEATURES = ['bold', 'italic', 'underline', 'strike', 'code', 'heading', 'list', 'blockquote', 'codeBlock', 'horizontalRule', 'link', 'table', 'image', 'mention'] as const

/** Links go to http(s), mailto or a relative place — the server's rule. */
export function allowedHref(href: string): boolean {
    // Control characters and spaces are what hide a scheme, so they are stripped on purpose.
    // eslint-disable-next-line no-control-regex
    const compact = href.replace(/[\u0000- \u007F]+/g, '')
    const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(compact)
    return scheme === null || ['http', 'https', 'mailto'].includes(scheme[1]!.toLowerCase())
}

export interface RichTextOptions {
    features: readonly string[]
    placeholder?: string
    /** Resolves an image's storage key to a URL it can show (a blob URL); editing only. */
    imageSource?: (key: string) => Promise<string | null>
    /** The `@` menu: records of the mentioned relation matching a query. */
    mentionItems?: (query: string) => Promise<{ id: string | number, label: string }[]>
    /** Node and mark types runtime extensions added, by name. */
    custom?: ReadonlyMap<string, AnyExtension>
}

/** The Tiptap extensions for a field: exactly its features, nothing else. */
export function richTextExtensions(options: RichTextOptions): Extensions {
    const on = (feature: string) => options.features.includes(feature)
    const extensions: Extensions = [
        StarterKit.configure({
            bold: on('bold') ? {} : false,
            italic: on('italic') ? {} : false,
            underline: on('underline') ? {} : false,
            strike: on('strike') ? {} : false,
            code: on('code') ? {} : false,
            heading: on('heading') ? { levels: [1, 2, 3, 4] } : false,
            bulletList: on('list') ? {} : false,
            orderedList: on('list') ? {} : false,
            listItem: on('list') ? {} : false,
            listKeymap: on('list') ? {} : false,
            blockquote: on('blockquote') ? {} : false,
            codeBlock: on('codeBlock') ? {} : false,
            horizontalRule: on('horizontalRule') ? {} : false,
            link: on('link')
                ? {
                        openOnClick: false,
                        autolink: true,
                        protocols: [],
                        HTMLAttributes: { target: '_blank', rel: 'noopener noreferrer nofollow' },
                        isAllowedUri: (url: string) => allowedHref(url),
                    }
                : false,
        }),
    ]

    if (on('table')) {
        // not resizable: resizing writes style attributes a strict CSP refuses
        extensions.push(TableKit.configure({ table: { resizable: false } }))
    }

    if (on('image')) {
        extensions.push(vectorImage(options.imageSource))
    }

    if (on('mention')) {
        extensions.push(Mention.configure({
            HTMLAttributes: { class: 'vx-mention' },
            suggestion: options.mentionItems ? mentionSuggestion(options.mentionItems) : undefined,
        }))
    }

    if (options.placeholder) {
        extensions.push(Placeholder.configure({ placeholder: options.placeholder }))
    }

    for (const [name, extension] of options.custom ?? []) {
        if (on(name)) extensions.push(extension)
    }

    return extensions
}

/**
 * An image stores a storage key in `src`, never a URL. Rendered, it
 * carries the key as `data-vector-key` and no `src`: whoever shows it
 * fetches the file with the API's credentials. Editing, a node view does
 * that through `imageSource`.
 */
function vectorImage(imageSource?: (key: string) => Promise<string | null>): AnyExtension {
    return Image.extend({
        renderHTML({ HTMLAttributes }) {
            const { src, ...rest } = HTMLAttributes as Record<string, unknown>
            return ['img', { ...rest, 'data-vector-key': src }]
        },
        parseHTML() {
            return [{ tag: 'img[src]' }, { tag: 'img[data-vector-key]', getAttrs: element => ({ src: (element as HTMLElement).getAttribute('data-vector-key') }) }]
        },
        addNodeView() {
            return ({ node }) => {
                const img = document.createElement('img')
                img.alt = String(node.attrs.alt ?? '')
                img.className = 'vx-richtext-image'
                const key = String(node.attrs.src ?? '')
                img.setAttribute('data-vector-key', key)
                imageSource?.(key).then((url) => {
                    if (url) img.src = url
                }).catch(() => undefined)
                return { dom: img }
            }
        },
    }).configure({ allowBase64: false })
}

/** A minimal `@` menu: a list under the caret, arrow keys and Enter to pick. */
function mentionSuggestion(items: (query: string) => Promise<{ id: string | number, label: string }[]>): NonNullable<MentionOptions['suggestion']> {
    return {
        items: ({ query }) => items(query),
        render: () => {
            let menu: HTMLDivElement | null = null
            let choices: { id: string | number, label: string }[] = []
            let active = 0
            let pick: ((item: { id: string | number, label: string }) => void) | null = null

            const draw = () => {
                if (!menu) return
                menu.replaceChildren(...choices.map((choice, index) => {
                    const button = document.createElement('button')
                    button.type = 'button'
                    button.className = `vx-mention-choice${index === active ? ' vx-mention-choice--active' : ''}`
                    button.textContent = choice.label
                    button.addEventListener('mousedown', (event) => {
                        event.preventDefault()
                        pick?.(choice)
                    })
                    return button
                }))
            }
            const place = (rect: DOMRect | null) => {
                if (!menu || !rect) return
                menu.style.left = `${rect.left + window.scrollX}px`
                menu.style.top = `${rect.bottom + window.scrollY + 4}px`
            }

            return {
                onStart: (props) => {
                    menu = document.createElement('div')
                    menu.className = 'vx-mention-menu'
                    menu.setAttribute('role', 'listbox')
                    document.body.appendChild(menu)
                    choices = props.items as typeof choices
                    pick = item => props.command({ id: String(item.id), label: item.label })
                    active = 0
                    draw()
                    place(props.clientRect?.() ?? null)
                },
                onUpdate: (props) => {
                    choices = props.items as typeof choices
                    pick = item => props.command({ id: String(item.id), label: item.label })
                    active = Math.min(active, Math.max(0, choices.length - 1))
                    draw()
                    place(props.clientRect?.() ?? null)
                },
                onKeyDown: ({ event }) => {
                    if (!choices.length) return false
                    if (event.key === 'ArrowDown') active = (active + 1) % choices.length
                    else if (event.key === 'ArrowUp') active = (active - 1 + choices.length) % choices.length
                    else if (event.key === 'Enter') {
                        pick?.(choices[active]!)
                        return true
                    } else return false
                    draw()
                    return true
                },
                onExit: () => {
                    menu?.remove()
                    menu = null
                },
            }
        },
    }
}

/** A stored value as a document: JSON, or HTML (a field that stores HTML) read through the schema. */
export function toDocument(value: string, extensions: Extensions): JSONContent | null {
    if (value.trim() === '') return null
    try {
        const parsed = JSON.parse(value) as unknown
        if (parsed && typeof parsed === 'object' && (parsed as JSONContent).type === 'doc') return parsed as JSONContent
    } catch {
        // not JSON: HTML
    }
    return generateJSON(value, extensions) as JSONContent
}

/** Whether a document holds no text, image, table or other content. */
export function isEmptyDocument(doc: JSONContent | null): boolean {
    const empty = (node: JSONContent): boolean => {
        if (node.type === 'text') return (node.text ?? '').trim() === ''
        if (!['doc', 'paragraph', 'hardBreak'].includes(node.type ?? '')) return false
        return (node.content ?? []).every(empty)
    }
    return doc === null || empty(doc)
}

let purifier: ReturnType<typeof DOMPurify> | null = null
let purifierWorks: boolean | null = null

const SANITIZE_CONFIG = {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 's', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'hr', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'colgroup', 'col', 'img', 'span'],
    ALLOWED_ATTR: ['href', 'target', 'rel', 'colspan', 'rowspan', 'alt', 'title', 'width', 'height', 'start', 'data-vector-key', 'data-type', 'data-id', 'data-label'],
    ALLOW_DATA_ATTR: false,
    ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto):|[^a-z]|[a-z+.-]+(?:[^a-z+.\-:]|$))/i,
}

/** Text shown as text: what a sanitizer that cannot be trusted leaves. */
function escapeHtml(html: string): string {
    return html.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' })[c]!)
}

/**
 * The allow-list the generated HTML passes before `v-html`: the schema's
 * elements and attributes only. No `style`, no event handler, no
 * `class`, no `src` (images carry their key), links only to http(s),
 * mailto or a relative place, and always opening apart.
 */
export function sanitizeHtml(html: string): string {
    if (!purifier) {
        purifier = DOMPurify(window)
        purifier.addHook('afterSanitizeAttributes', (node) => {
            if (node.tagName === 'A') {
                if (!allowedHref(node.getAttribute('href') ?? '')) node.removeAttribute('href')
                node.setAttribute('target', '_blank')
                node.setAttribute('rel', 'noopener noreferrer nofollow')
            }
        })
    }

    // Fail closed: a sanitizer that does not clean a known-bad canary exactly
    // (a DOM that iterates wrongly, a patched library) is not trusted, and
    // everything is shown as text instead.
    purifierWorks ??= purifier.sanitize('<p onclick="x">a<script>b</script><img src="x" onerror="y"><a href="javascript:z">c</a></p>', SANITIZE_CONFIG)
        === '<p>a<img><a target="_blank" rel="noopener noreferrer nofollow">c</a></p>'

    return purifierWorks ? purifier.sanitize(html, SANITIZE_CONFIG) : escapeHtml(html)
}

/** Whether this DOM's sanitizer passed its canary: false means every rich value is shown as text. */
export function sanitizerWorks(): boolean {
    sanitizeHtml('')
    return purifierWorks === true
}

/** A stored value as sanitized HTML, generated through the schema. */
export function richTextHtml(value: string, extensions: Extensions): string {
    try {
        const doc = toDocument(value, extensions)
        return doc ? sanitizeHtml(generateHTML(doc, extensions)) : ''
    } catch {
        return '' // a node the schema does not know: the server refuses such a document, so it is not shown either
    }
}

/** A stored value's plain text, for a list cell. */
export function richTextPlain(value: string, extensions: Extensions): string {
    try {
        const doc = toDocument(value, extensions)
        return doc ? generateText(doc, extensions, { blockSeparator: ' ' }).trim() : ''
    } catch {
        return ''
    }
}

let markdown: ReturnType<typeof MarkdownIt> | null = null

/**
 * Markdown source as sanitized HTML: markdown-it with HTML in the source
 * disabled (it shows as text) and bare URLs linked, then the same
 * allow-list as rich text.
 */
export function markdownHtml(source: string): string {
    markdown ??= new MarkdownIt({ html: false, linkify: true, breaks: false })
    return sanitizeHtml(markdown.render(source))
}

/** Markdown source as its plain text, for a list cell. */
export function markdownPlain(source: string): string {
    const holder = document.createElement('div')
    holder.innerHTML = markdownHtml(source)
    return (holder.textContent ?? '').replace(/\s+/g, ' ').trim()
}
