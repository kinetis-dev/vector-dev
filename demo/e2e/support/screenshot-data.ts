import { expect, type Api } from './fixtures'

/**
 * The data the docs screenshots show that `demo:seed --fresh` does not
 * make: a product's history, saved views, and finished jobs with their
 * notifications. All of it goes through the admin API as the suite's
 * admin, so it is what the admin itself writes — real audit entries, real
 * jobs run by the worker.
 *
 * Each step looks first and writes only what is missing, so running it
 * again adds nothing: no second view, no longer history, no more jobs.
 * `demo:seed --fresh` takes it all away again.
 */
export async function prepareScreenshotData(api: Api): Promise<void> {
    await history(api)
    await views(api)
    await jobs(api)
}

/** The product whose History the docs show: SKU-00014, a draft in the seed. */
export const HISTORY_PRODUCT = 14

/** Three changes, oldest first: a price edit, publishing it, and an action run. */
async function history(api: Api) {
    const product = await api.show('products', HISTORY_PRODUCT)
    expect(product.sku, 'the seeded catalogue').toBe('SKU-00014')
    const changed = (field: string, audit: Awaited<ReturnType<Api['audit']>>) => audit.some(entry => field in entry.changes)

    if (!changed('priceCents', await api.audit('products', HISTORY_PRODUCT))) {
        await api.update('products', HISTORY_PRODUCT, { priceCents: 69900 })
    }
    if (product.status === 'draft') {
        await api.update('products', HISTORY_PRODUCT, { status: 'active', releasedAt: '2026-11-02T08:00:00.000Z' })
    }
    if (!(await api.audit('products', HISTORY_PRODUCT)).some(entry => entry.event.startsWith('action:'))) {
        await api.action('products', 'adjust-stock', [HISTORY_PRODUCT], { delta: 24, note: 'Restocked from the November delivery' })
    }
}

/**
 * Two views of the products list: one shared, one the admin's own. Neither
 * is a default: a default would open the list filtered for every test that
 * reads it.
 */
const VIEWS = [
    { name: 'Low stock', shared: true, isDefault: false, state: { filter: { status: { eq: 'active' }, stock: { lte: '10' } }, sort: 'stock' } },
    { name: 'Drafts', shared: false, isDefault: false, state: { filter: { status: { eq: 'draft' } }, sort: 'name' } },
]

async function views(api: Api) {
    const saved = await api.views('products')
    for (const view of VIEWS) {
        await api.saveView('products', view, saved.find(v => v.mine && v.name === view.name)?.id)
    }
}

/**
 * A finished Excel export of the products, and a tags import with one
 * refused row. The import rewrites two tags with the values they already
 * have, so no tag changes; the third row's colour is not one.
 */
async function jobs(api: Api) {
    const existing = await api.jobs()

    if (!existing.some(job => job.kind === 'export' && job.resource === 'products' && job.status === 'done')) {
        expect(await api.finished(await api.exportJob('products', { format: 'xlsx', sort: 'sku' }))).toBe('done')
    }

    if (!existing.some(job => job.kind === 'import' && job.resource === 'tags')) {
        const tags = (await api.search('tags', '')).sort((a, b) => Number(a.id) - Number(b.id)).slice(0, 3)
        const csv = ['name,color', `${tags[0]!.name},${tags[0]!.color}`, `${tags[1]!.name},${tags[1]!.color}`, `${tags[2]!.name},green`].join('\n') + '\n'
        expect(await api.finished(await api.importCsv('tags', csv, { name: 'name', color: 'color' }, 'upsert', 'name'))).toBe('done')
    }
}
