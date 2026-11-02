// A smoke test of kinetis/vector-queues' admin UI module, with no browser
// and no bundler: it is loaded as the admin loads it, its default export
// handed a stub window.Vector whose Vue is a tiny stand-in — h() records
// vnodes, refs are plain boxes, lifecycle hooks are collected — so a
// render can be walked and its buttons found by their data-action.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const MODULE = new URL('../packages/vector-queues/resources/ui/queues.js', import.meta.url)

let current = null

const vue = {
    // Vue's own overload: a second argument that is not a plain props object is the children
    h: (type, props, children) => {
        const isChildren = props !== null && props !== undefined && (Array.isArray(props) || typeof props !== 'object' || props.__v_isVNode === true)
        return isChildren ? { __v_isVNode: true, type, props: {}, children: props } : { __v_isVNode: true, type, props: props ?? {}, children }
    },
    ref: value => ({ value }),
    computed: get => ({ get value() { return get() } }),
    onMounted: hook => current.mounted.push(hook),
    onBeforeUnmount: hook => current.unmounted.push(hook),
}

// a Vuetify component is its name, which is all a walk needs
const vuetify = new Proxy({}, { get: (_, name) => String(name) })

function lookup(messages, key) {
    return key.split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), messages)
}

/** Loads the module against stub routes: each `path` answers its value, or throws it when it is an Error. */
async function load(routes) {
    const pages = new Map()
    const widgets = new Map()
    const messages = {}
    const calls = []
    const missing = new Set()
    const api = async (path, options = {}) => {
        calls.push({ path, method: options.method ?? 'GET', options })
        const answer = routes[path]
        if (answer === undefined) throw Object.assign(new Error(`no stub for ${path}`), { status: 500 })
        if (answer instanceof Error) throw answer
        if (typeof answer === 'function') return answer()
        return structuredClone(answer)
    }
    const vector = { api, notify: { success() {}, error() {} }, navigate() {} }
    const t = (key, params = {}) => {
        if (typeof lookup(messages.en, key) !== 'string') missing.add(key)
        return `${key}${Object.keys(params).length ? JSON.stringify(params) : ''}`
    }

    // as a browser takes it: an ES module, whatever the nearest package.json says
    const { default: register } = await import(`data:text/javascript;base64,${readFileSync(MODULE).toString('base64')}`)
    // every member the real loader hands over (packages/vector-ui/app/plugins/vector-extensions.client.ts, `const api`), no more
    register({
        version: 1,
        vue,
        vuetify,
        tiptap: { Node: {}, Mark: {}, Extension: {}, mergeAttributes: () => ({}) },
        defineCell: () => {},
        defineInput: () => {},
        defineWidget: (type, component) => widgets.set(type, component),
        defineRichTextExtension: () => {},
        defineMessages: (locale, added) => Object.assign(messages[locale] ??= {}, added),
        definePage: (name, page) => pages.set(name, page),
        api,
        notify: vector.notify,
        navigate: vector.navigate,
        t,
    })

    return { pages, widgets, calls, missing, vector }
}

/**
 * A mounted component: setup() once, then each walk renders it and
 * reconciles its child components by position and type, as Vue does — a
 * child in the same place is kept, a different one replaces it (the old
 * one unmounted) — and calls every slot.
 */
function mount(component, props) {
    const instance = { component, mounted: [], unmounted: [], children: new Map() }
    current = instance
    instance.render = component.setup(props, { emit: () => {} })
    current = null
    instance.mounted.forEach(hook => hook())

    return instance
}

function unmountAll(instance) {
    for (const child of instance.children.values()) unmountAll(child)
    instance.children.clear()
    instance.unmounted.forEach(hook => hook())
}

function walk(instance) {
    const nodes = []
    const seen = new Set()
    const visit = (node, path) => {
        if (node === null || node === undefined || typeof node === 'boolean') return
        if (Array.isArray(node)) return node.forEach((child, index) => visit(child, `${path}.${index}`))
        if (typeof node !== 'object') return nodes.push({ text: String(node) })
        if (typeof node.type === 'object' && typeof node.type.setup === 'function') {
            const key = `${path}:${node.props.key ?? ''}`
            let child = instance.children.get(key)
            if (child && child.component !== node.type) {
                unmountAll(child)
                child = undefined
            }
            if (!child) {
                child = mount(node.type, node.props)
                instance.children.set(key, child)
            }
            seen.add(key)
            return nodes.push(...walk(child))
        }
        nodes.push(node)
        const { children } = node
        if (typeof children === 'function') visit(children({ props: {} }), `${path}/d`)
        else if (children && typeof children === 'object' && !Array.isArray(children) && children.__v_isVNode !== true) {
            for (const [name, slot] of Object.entries(children)) visit(slot({ props: {} }), `${path}/${name}`)
        } else visit(children, `${path}/c`)
    }
    visit(instance.render(), '')
    for (const [key, child] of instance.children) {
        if (!seen.has(key)) {
            unmountAll(child)
            instance.children.delete(key)
        }
    }

    return nodes
}

const tick = () => new Promise(resolve => setTimeout(resolve, 0))

const actions = nodes => nodes.filter(node => node.props?.['data-action']).map(node => node.props['data-action'])

const forbidden = () => Object.assign(new Error('Forbidden'), { status: 403, data: { error: 'This account may not do that on the queue dashboard.' } })

const FAILED_ID = '0123456789abcdef0123456789abcdef'

function routes(permissions) {
    return {
        '/vector/queues/permissions': permissions,
        '/vector/queues': { data: [{ name: 'mail', size: 3, clearable: true, failed: 1 }, { name: 'reports', size: null, clearable: true, failed: 0 }] },
        '/vector/queues/failed': {
            data: [{ id: FAILED_ID, connection: null, queue: 'mail', class: 'App\\Jobs\\SendInvoice', attempts: 3, maxAttempts: 3, exception: { class: 'RuntimeException', message: '<img src=x onerror=alert(1)>' }, failedAt: '2026-11-02T09:30:00Z' }],
            nextCursor: 'opaque',
            hasMore: true,
            limit: 25,
        },
        [`/vector/queues/failed/${FAILED_ID}`]: { data: { id: FAILED_ID, connection: null, queue: 'mail', class: 'App\\Jobs\\SendInvoice', args: { invoiceId: 7 }, attempts: 3, maxAttempts: 3, exception: { class: 'RuntimeException', message: '<b>boom</b>', trace: null }, failedAt: '2026-11-02T09:30:00Z' } },
        '/vector/queues/metrics': { data: { from: 0, to: 3540, bucketSeconds: 60, buckets: [{ bucket: 0, succeeded: 1, released: 0, failed: 1, averageDurationMs: 2, maxDurationMs: 3 }], queues: [{ queue: 'mail', succeeded: 1, released: 0, failed: 1, failureRate: 0.5, averageDurationMs: 2, maxDurationMs: 3, lastProcessedAt: 60 }], classes: [{ class: 'App\\Jobs\\SendInvoice', succeeded: 1, released: 0, failed: 1, failureRate: 0.5, averageDurationMs: 2, maxDurationMs: 3, lastProcessedAt: 60 }] } },
    }
}

const NONE = { view: true, retry: false, forget: false, prune: false, clear: { mail: false, reports: false } }
const ALL = { view: true, retry: true, forget: true, prune: true, clear: { mail: true, reports: true } }

function stubDocument() {
    const listeners = new Set()
    globalThis.document = { hidden: false, addEventListener: (_, fn) => listeners.add(fn), removeEventListener: (_, fn) => listeners.delete(fn) }
    return listeners
}

/** The page, mounted, on one tab: the nodes of its render once every stub request has settled. */
async function renderTab(loaded, tab) {
    const page = mount(loaded.pages.get('queues').component, { vector: loaded.vector })
    await tick()
    const tabs = walk(page).find(node => node.type === 'VTabs')
    tabs.props['onUpdate:modelValue'](tab)
    walk(page) // mounts the tab, whose requests start now
    await tick()
    await tick()

    return { page, nodes: walk(page) }
}

test('the module registers the queues page and the vector-queues widget, with every message it uses', async () => {
    stubDocument()
    const loaded = await load(routes(ALL))

    assert.deepEqual([...loaded.pages.keys()], ['queues'])
    const page = loaded.pages.get('queues')
    assert.equal(page.title, 'vectorQueues.title')
    assert.ok(page.icon.startsWith('mdi-'))
    assert.equal(typeof page.group, 'string')
    assert.deepEqual([...loaded.widgets.keys()], ['vector-queues'])

    for (const tab of ['overview', 'failed', 'metrics']) unmountAll((await renderTab(loaded, tab)).page)
    const widget = mount(loaded.widgets.get('vector-queues'), { data: {}, widget: {} })
    await tick()
    const texts = walk(widget).filter(node => node.text !== undefined).map(node => node.text)
    assert.ok(texts.includes('3'), 'the pending total, the unknown size left out')
    assert.ok(texts.includes('1'), 'the failed total')

    assert.deepEqual([...loaded.missing], [], 'every t() key has an English message')
})

test('no mutation is offered when the permissions allow none', async () => {
    stubDocument()
    const loaded = await load(routes(NONE))

    for (const tab of ['overview', 'failed']) {
        const { page, nodes } = await renderTab(loaded, tab)
        assert.deepEqual(actions(nodes), [], `no mutation on the ${tab} tab`)
        assert.equal(nodes.filter(node => node.type === 'VCheckbox').length, 0, 'nothing to select for a batch retry')
        unmountAll(page)
    }
})

test('every mutation is offered when the permissions allow it, and the failed list pages by its cursor', async () => {
    stubDocument()
    const loaded = await load(routes(ALL))

    const overview = await renderTab(loaded, 'overview')
    assert.deepEqual(actions(overview.nodes), ['clear', 'clear'])
    unmountAll(overview.page)

    const failed = await renderTab(loaded, 'failed')
    assert.deepEqual(actions(failed.nodes).sort(), ['forget', 'prune', 'retry', 'retry-selected'])
    const more = failed.nodes.find(node => node.type === 'VBtn' && typeof node.children === 'function' && node.children() === 'vectorQueues.failed.loadMore')
    assert.ok(more, 'a load-more button for the server\'s cursor')
    more.props.onClick()
    await tick()
    const last = loaded.calls.at(-1)
    assert.equal(last.path, '/vector/queues/failed')
    assert.deepEqual(last.options.query, { cursor: 'opaque' })
    unmountAll(failed.page)
})

/** The failed tab with a row's detail opened through its Details button: the nodes once the detail has loaded. */
async function renderDetail(loaded) {
    const failed = await renderTab(loaded, 'failed')
    const details = failed.nodes.find(node => node.type === 'VBtn' && typeof node.children === 'function' && node.children() === 'vectorQueues.failed.open')
    assert.ok(details, 'a Details button on the row')
    details.props.onClick()
    walk(failed.page) // mounts the detail, whose request starts now
    await tick()
    await tick()
    const nodes = walk(failed.page)
    assert.ok(nodes.some(node => node.text === `vectorQueues.failed.detail.title{"id":"${FAILED_ID}"}`), 'the detail is shown')
    assert.ok(loaded.calls.some(call => call.path === `/vector/queues/failed/${FAILED_ID}`))

    return { page: failed.page, nodes }
}

test('the failed-job detail offers no mutation when the permissions allow none', async () => {
    stubDocument()
    const detail = await renderDetail(await load(routes(NONE)))

    assert.deepEqual(actions(detail.nodes), [])
    unmountAll(detail.page)
})

test('the failed-job detail offers retry and forget when the permissions allow them', async () => {
    stubDocument()
    const detail = await renderDetail(await load(routes(ALL)))

    assert.deepEqual(actions(detail.nodes).sort(), ['forget', 'retry'])
    unmountAll(detail.page)
})

test('a 403 from the permissions is a no-access state', async () => {
    stubDocument()
    const loaded = await load({ ...routes(ALL), '/vector/queues/permissions': forbidden() })
    const page = mount(loaded.pages.get('queues').component, { vector: loaded.vector })
    await tick()

    assert.equal(walk(page)[0].props['data-state'], 'no-access')
})

test('the overview polls one request at a time and stops when unmounted', async () => {
    const listeners = stubDocument()
    const loaded = await load(routes(ALL))
    const { page } = await renderTab(loaded, 'overview')
    const polls = () => loaded.calls.filter(call => call.path === '/vector/queues').length

    assert.equal(polls(), 1)
    assert.equal(listeners.size, 1, 'it watches the page\'s visibility')
    unmountAll(page)
    assert.equal(listeners.size, 0)
})

test('a poll never overlaps one in flight, and a hidden page schedules none', async () => {
    const listeners = stubDocument()
    let release = null
    const loaded = await load({ ...routes(ALL), '/vector/queues': () => new Promise(resolve => { release = resolve }) })
    const page = mount(loaded.pages.get('queues').component, { vector: loaded.vector })
    await tick()
    walk(page) // mounts the overview, whose first request now waits
    const polls = () => loaded.calls.filter(call => call.path === '/vector/queues').length
    assert.equal(polls(), 1)

    const [onVisibility] = listeners
    onVisibility() // visible again while the request is in flight
    onVisibility()
    assert.equal(polls(), 1, 'no second request while one is in flight')

    globalThis.document.hidden = true
    release({ data: [] })
    await tick()
    await new Promise(resolve => setTimeout(resolve, 20))
    assert.equal(polls(), 1, 'a hidden page schedules no poll')

    unmountAll(page)
    assert.equal(listeners.size, 0)
})

test('server text is never handed over as HTML', () => {
    const source = readFileSync(MODULE, 'utf8')
    assert.doesNotMatch(source, /innerHTML|v-html|outerHTML|insertAdjacentHTML/)
})
