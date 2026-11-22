import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { DESCRIPTOR_VERSION } from '#vector/app/composables/useVectorSchema'

// The AI parts register through the admin's own extension points, the
// same ones window.Vector v2 hands a runtime extension, and only while
// the descriptor carries capabilities.ai: a plain install registers none.

function descriptor(ai?: Record<string, unknown>) {
    return { version: DESCRIPTOR_VERSION, resources: {}, menu: [], settings: [], dashboards: [], capabilities: { impersonation: false, queue: false, ...(ai === undefined ? {} : { ai }) }, branding: null, ui: { expected: null } }
}

function registered() {
    const { settingsAddons, listToolbar, inputAddons, editorToolbar, shellActions } = useVectorExtensions()
    return {
        settings: settingsAddons.has('ai'),
        filters: listToolbar.has('ai-filters'),
        input: inputAddons.has('ai-writing'),
        editor: editorToolbar.has('ai-writing'),
        assistant: shellActions.has('ai-assistant'),
    }
}

const NONE = { settings: false, filters: false, input: false, editor: false, assistant: false }
const ALL = { settings: true, filters: true, input: true, editor: true, assistant: true }

describe('the AI plugin', () => {
    afterEach(async () => {
        useState('vector.schema').value = null
        await nextTick()
    })

    it('registers nothing before the descriptor, or without capabilities.ai', async () => {
        expect(registered()).toEqual(NONE)

        useState('vector.schema').value = descriptor()
        await nextTick()
        expect(registered()).toEqual(NONE)
    })

    it('registers every part through the admin\'s extension points with capabilities.ai', async () => {
        useState('vector.schema').value = descriptor({ filters: false, assistant: false, writing: false })
        await nextTick()

        expect(registered()).toEqual(ALL)
        const addon = useVectorExtensions().inputAddons.get('ai-writing')
        expect(addon?.types).toEqual(['string', 'text'])
        expect(addon?.placement).toBe('inner')
        // the same points window.Vector v2 hands a runtime extension
        expect(window.Vector?.version).toBe(2)
        expect(typeof window.Vector?.defineListToolbarItem).toBe('function')
    })

    it('removes them when the descriptor loses capabilities.ai or is cleared', async () => {
        useState('vector.schema').value = descriptor({ writing: true })
        await nextTick()
        expect(registered()).toEqual(ALL)

        useState('vector.schema').value = descriptor()
        await nextTick()
        expect(registered()).toEqual(NONE)

        useState('vector.schema').value = descriptor({ writing: true })
        await nextTick()
        useState('vector.schema').value = null
        await nextTick()
        expect(registered()).toEqual(NONE)
    })
})
