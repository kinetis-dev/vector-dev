import { describe, expect, it } from 'vitest'
import { Extension } from '@tiptap/core'
import { RICH_TEXT_FEATURES, richTextExtensions } from '#vector/app/utils/richtext'

// A field's Tiptap extensions: StarterKit with exactly its features' parts
// turned on, then one extension per further feature, the placeholder and
// the runtime extensions its features name.

const STARTER_KIT_PARTS = ['bold', 'italic', 'underline', 'strike', 'code', 'heading', 'bulletList', 'orderedList', 'listItem', 'listKeymap', 'blockquote', 'codeBlock', 'horizontalRule', 'link'] as const

function starterKit(features: readonly string[]): Record<string, unknown> {
    const [kit] = richTextExtensions({ features })
    expect(kit!.name).toBe('starterKit')
    const options = kit!.options as Record<string, unknown>

    return Object.fromEntries(STARTER_KIT_PARTS.map(part => [part, options[part]]))
}

describe('the rich-text extensions', () => {
    it('turns every StarterKit part off when no feature allows it', () => {
        expect(starterKit([])).toEqual(Object.fromEntries(STARTER_KIT_PARTS.map(part => [part, false])))
    })

    it('turns on the StarterKit parts each allowed feature names', () => {
        expect(starterKit(['bold', 'underline', 'code', 'heading', 'list', 'codeBlock'])).toEqual({
            bold: {},
            italic: false,
            underline: {},
            strike: false,
            code: {},
            heading: { levels: [1, 2, 3, 4] },
            bulletList: {},
            orderedList: {},
            listItem: {},
            listKeymap: {},
            blockquote: false,
            codeBlock: {},
            horizontalRule: false,
            link: false,
        })

        expect(starterKit(['italic', 'strike', 'blockquote', 'horizontalRule'])).toMatchObject({
            bold: false, italic: {}, strike: {}, heading: false, bulletList: false, blockquote: {}, horizontalRule: {},
        })
    })

    it('links only to the places a link may go, opening them apart', () => {
        const link = starterKit(['link']).link as { openOnClick: boolean, autolink: boolean, protocols: unknown[], HTMLAttributes: Record<string, string>, isAllowedUri: (url: string) => boolean }

        expect(link).toMatchObject({ openOnClick: false, autolink: true, protocols: [], HTMLAttributes: { target: '_blank', rel: 'noopener noreferrer nofollow' } })
        expect(link.isAllowedUri('https://kinetis.dev')).toBe(true)
        expect(link.isAllowedUri('mailto:a@b.c')).toBe(true)
        expect(link.isAllowedUri('/relative')).toBe(true)
        expect(link.isAllowedUri('javascript:alert(1)')).toBe(false)
        expect(link.isAllowedUri('java\tscript:alert(1)')).toBe(false)
        expect(link.isAllowedUri('data:text/html,x')).toBe(false)
    })

    it('adds nothing beyond StarterKit for a field with no further feature', () => {
        expect(richTextExtensions({ features: ['bold'] }).map(e => e.name)).toEqual(['starterKit'])
    })

    it('adds the table, image, mention, placeholder and the runtime extensions its features name, in that order', () => {
        const custom = new Map([
            ['callout', Extension.create({ name: 'callout' })],
            ['unused', Extension.create({ name: 'unused' })],
        ])

        expect(richTextExtensions({ features: [...RICH_TEXT_FEATURES, 'callout'], placeholder: 'Write…', custom }).map(e => e.name))
            .toEqual(['starterKit', 'tableKit', 'image', 'mention', 'placeholder', 'callout'])
        expect(richTextExtensions({ features: ['table'], placeholder: '' }).map(e => e.name)).toEqual(['starterKit', 'tableKit'])
    })
})
