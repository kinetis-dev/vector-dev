// kinetis/vector-queues' admin UI ships its text in every language the
// admin UI ships: this loads the module with stubs, records what it hands
// to defineMessages, and holds each catalogue to the English one's keys
// and {placeholders}. The language list is read from the vector-ui
// catalogues on disk, so a new UI language fails here until it is added.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readdirSync } from 'node:fs'

const MODULE = new URL('../packages/vector-queues/resources/ui/queues.js', import.meta.url)
const I18N = new URL('../packages/vector-ui/app/i18n/', import.meta.url)

const vue = {
    h: () => ({}),
    ref: value => ({ value }),
    computed: get => ({ get value() { return get() } }),
    onMounted: () => {},
    onBeforeUnmount: () => {},
}
const vuetify = new Proxy({}, { get: (_, name) => String(name) })

async function recordedMessages() {
    const recorded = new Map()
    const { default: register } = await import(MODULE)
    register({
        vue,
        vuetify,
        definePage: () => {},
        defineWidget: () => {},
        defineMessages: (locale, messages) => {
            assert.ok(!recorded.has(locale), `defineMessages is called twice for ${locale}`)
            recorded.set(locale, messages.vectorQueues)
        },
        t: key => key,
        api: async () => ({}),
        navigate: () => {},
    })
    return recorded
}

/** The dotted path of every leaf of a nested catalogue, with the leaf. */
function flatten(node, prefix = '') {
    return Object.entries(node).flatMap(([key, value]) => (
        value !== null && typeof value === 'object' ? flatten(value, `${prefix}${key}.`) : [[`${prefix}${key}`, value]]
    ))
}

const placeholders = text => [...new Set(text.match(/\{\w+\}/g) ?? [])].sort()

test('the module registers English and exactly the languages the admin UI ships', async () => {
    const recorded = await recordedMessages()
    const shipped = readdirSync(I18N, { withFileTypes: true }).filter(entry => entry.isDirectory() && !entry.name.startsWith('.')).map(entry => entry.name)
    assert.ok(shipped.includes('en') && shipped.length > 1)
    assert.deepEqual([...recorded.keys()].sort(), shipped.sort())
})

test('every catalogue has the English keys, non-empty strings and the English placeholders', async () => {
    const recorded = await recordedMessages()
    const english = flatten(recorded.get('en'))
    assert.ok(english.length > 50)
    const englishKeys = english.map(([key]) => key).sort()

    for (const [locale, catalogue] of recorded) {
        const entries = flatten(catalogue)
        assert.deepEqual(entries.map(([key]) => key).sort(), englishKeys, `${locale}: the keys differ from English`)
        const own = new Map(entries)
        for (const [key, text] of english) {
            const value = own.get(key)
            assert.ok(typeof value === 'string' && value.trim() !== '', `${locale}: ${key} is not a non-empty string`)
            assert.deepEqual(placeholders(value), placeholders(text), `${locale}: ${key} has other {placeholders} than English`)
        }
    }
})
