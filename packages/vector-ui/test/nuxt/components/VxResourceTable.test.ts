import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import VxResourceTable from '#vector/app/components/Vx/Resource/Table.vue'
import VxListViewMenu from '#vector/app/components/Vx/List/ViewMenu.vue'
import type { VectorFilterQuery, VectorResource, VectorSavedView } from '#vector/app/types/vector'
import { endpoint, standaloneApi, type Received } from '../endpoints'
import { resource } from '../fixtures'

// The list's paging when the resource cannot be counted: an uncounted
// (withoutCount) list by page number, a cursor (cursorPaging) list by the
// cursors the API hands out — previous and next only, no total.

const query = (request: Received) => Object.fromEntries(new URL(request.path, 'http://x').searchParams)
const rows = (ids: number[]) => ids.map(id => ({ id, name: `Tag ${id}`, color: '#000000' }))

function tags(table: Partial<VectorResource['table']>): VectorResource {
    const base = resource()

    return resource({ table: { ...base.table, perPage: 2, perPageOptions: [2, 10], ...table } })
}

describe('VxResourceTable, uncounted and by cursor', () => {
    const cleanups: (() => void)[] = []

    beforeEach(() => {
        standaloneApi()
        for (const [url, body] of [['/vector/preferences', { data: {} }], ['/vector/views/tags', { data: [] }]] as const) {
            cleanups.push(endpoint(url, { body }).unregister)
        }
    })
    afterEach(() => cleanups.splice(0).forEach(unregister => unregister()))

    async function mount(table: Partial<VectorResource['table']>, list: ReturnType<typeof endpoint>) {
        cleanups.push(list.unregister)
        const wrapper = await mountSuspended(VxResourceTable, { props: { resource: tags(table) } })
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 1'))
        await flushPromises()

        return wrapper
    }

    const button = (wrapper: Awaited<ReturnType<typeof mountSuspended>>, label: string) => wrapper.get(`button[aria-label="${label}"]`)

    it('pages a cursor list by the cursors handed out, and back through those visited', async () => {
        const list = endpoint('/vector/r/tags', request => ({
            body: query(request).cursor === ''
                ? { data: rows([1, 2]), nextCursor: 'after-2', hasMore: true, perPage: 2 }
                : { data: rows([3]), nextCursor: null, hasMore: false, perPage: 2 },
        }))
        const wrapper = await mount({ cursorPaging: true }, list)

        // the first page by the empty cursor, with nothing a cursor refuses: no page, sort, group or summaries
        expect(query(list.requests[0]!)).toEqual({ cursor: '', perPage: '2', columns: 'id,name,color' })
        expect(wrapper.text()).toContain('Tag 2')
        expect(wrapper.text()).not.toContain('Page')
        expect(wrapper.find('.v-data-table__th--sortable').exists()).toBe(false)
        expect(button(wrapper, 'Previous page').attributes('disabled')).toBeDefined()

        await button(wrapper, 'Next page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 3'))
        await vi.waitFor(() => expect(query(list.requests.at(-1)!).cursor).toBe('after-2'))
        expect(button(wrapper, 'Next page').attributes('disabled')).toBeDefined()

        await button(wrapper, 'Previous page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 1'))
        await vi.waitFor(() => expect(query(list.requests.at(-1)!).cursor).toBe(''))
        expect(button(wrapper, 'Previous page').attributes('disabled')).toBeDefined()
        expect(button(wrapper, 'Next page').attributes('disabled')).toBeUndefined()
    })

    it('starts a cursor list again from the first page when the search changes', async () => {
        const list = endpoint('/vector/r/tags', request => ({
            body: query(request).cursor === ''
                ? { data: rows([1, 2]), nextCursor: 'after-2', hasMore: true, perPage: 2 }
                : { data: rows([3]), nextCursor: null, hasMore: false, perPage: 2 },
        }))
        const wrapper = await mount({ cursorPaging: true }, list)
        await button(wrapper, 'Next page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 3'))

        await wrapper.get('input[aria-label="Search"]').setValue('tag')
        await vi.waitFor(() => expect(query(list.requests.at(-1)!).q).toBe('tag'), { timeout: 2000 })
        expect(query(list.requests.at(-1)!).cursor).toBe('')
    })

    it('pages an uncounted list by number, without a total, next while another page follows', async () => {
        const list = endpoint('/vector/r/tags', (request) => {
            const page = Number(query(request).page)

            return { body: { data: page === 1 ? rows([1, 2]) : rows([3]), currentPage: page, perPage: 2, total: null, lastPage: null, hasMore: page === 1 } }
        })
        const wrapper = await mount({ withoutCount: true }, list)

        expect(query(list.requests[0]!)).toMatchObject({ page: '1', perPage: '2', sort: 'id' })
        expect(wrapper.text()).toContain('Page 1')
        expect(wrapper.text()).not.toMatch(/\d+-\d+ of/)
        expect(button(wrapper, 'Previous page').attributes('disabled')).toBeDefined()

        await button(wrapper, 'Next page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Page 2'))
        await vi.waitFor(() => expect(query(list.requests.at(-1)!).page).toBe('2'))
        expect(wrapper.text()).toContain('Tag 3')
        expect(button(wrapper, 'Next page').attributes('disabled')).toBeDefined()

        await button(wrapper, 'Previous page').trigger('click')
        await vi.waitFor(() => expect(query(list.requests.at(-1)!).page).toBe('1'))
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 1'))
        expect(wrapper.text()).toContain('Page 1')
    })

    it('selects all matching records of an uncounted list without a number', async () => {
        const list = endpoint('/vector/r/tags', () => ({ body: { data: rows([1, 2]), currentPage: 1, perPage: 2, total: null, lastPage: null, hasMore: true } }))
        const wrapper = await mount({ withoutCount: true }, list)

        await wrapper.findAll('tbody input[type="checkbox"]')[0]!.trigger('click')
        await flushPromises()
        const widen = wrapper.findAll('button').find(b => b.text().includes('Select all matching records'))
        expect(widen).toBeDefined()
        await widen!.trigger('click')

        expect(wrapper.text()).toContain('All matching records')
    })

    // a refused answer, which the client does not retry
    const refused = { status: 403, body: { error: 'Not now.' } }
    /** Waits until $label is enabled again: the pager disables both buttons while a request is in flight. */
    const enabled = (wrapper: Awaited<ReturnType<typeof mountSuspended>>, label: string) => vi.waitFor(() => expect(button(wrapper, label).attributes('disabled')).toBeUndefined())

    it('stays on an uncounted page when the next one fails, and asks for that page again', async () => {
        let failures = 1
        const list = endpoint('/vector/r/tags', (request) => {
            const page = Number(query(request).page)
            if (page === 2 && failures-- > 0) return refused

            return { body: { data: page === 1 ? rows([1, 2]) : rows([3]), currentPage: page, perPage: 2, total: null, lastPage: null, hasMore: page === 1 } }
        })
        const wrapper = await mount({ withoutCount: true }, list)

        await button(wrapper, 'Next page').trigger('click')
        await vi.waitFor(() => expect(list.requests).toHaveLength(2))
        await flushPromises()
        await enabled(wrapper, 'Next page')
        expect(query(list.requests[1]!).page).toBe('2')
        expect(wrapper.text()).toContain('Page 1')
        expect(wrapper.text()).toContain('Tag 1')
        expect(button(wrapper, 'Previous page').attributes('disabled')).toBeDefined()
        expect(button(wrapper, 'Next page').attributes('disabled')).toBeUndefined()

        await button(wrapper, 'Next page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Page 2'))
        expect(list.requests).toHaveLength(3)
        expect(query(list.requests[2]!).page).toBe('2')
        expect(wrapper.text()).toContain('Tag 3')
    })

    it('stays on a cursor page when the next one fails, and asks for the same cursor again', async () => {
        let failures = 1
        const list = endpoint('/vector/r/tags', (request) => {
            const cursor = query(request).cursor
            if (cursor === 'after-2' && failures-- > 0) return refused

            return {
                body: cursor === ''
                    ? { data: rows([1, 2]), nextCursor: 'after-2', hasMore: true, perPage: 2 }
                    : { data: rows([3]), nextCursor: null, hasMore: false, perPage: 2 },
            }
        })
        const wrapper = await mount({ cursorPaging: true }, list)

        await button(wrapper, 'Next page').trigger('click')
        await vi.waitFor(() => expect(list.requests).toHaveLength(2))
        await flushPromises()
        await enabled(wrapper, 'Next page')
        expect(query(list.requests[1]!).cursor).toBe('after-2')
        expect(wrapper.text()).toContain('Tag 1')
        expect(button(wrapper, 'Previous page').attributes('disabled')).toBeDefined()
        expect(button(wrapper, 'Next page').attributes('disabled')).toBeUndefined()

        await button(wrapper, 'Next page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 3'))
        expect(list.requests).toHaveLength(3)
        expect(query(list.requests[2]!).cursor).toBe('after-2')
        expect(button(wrapper, 'Previous page').attributes('disabled')).toBeUndefined()
    })

    it('stays on a cursor page when the previous one fails', async () => {
        let failFirst = false
        const list = endpoint('/vector/r/tags', (request) => {
            const cursor = query(request).cursor
            if (cursor === '' && failFirst) {
                failFirst = false

                return refused
            }

            return {
                body: cursor === ''
                    ? { data: rows([1, 2]), nextCursor: 'after-2', hasMore: true, perPage: 2 }
                    : { data: rows([3]), nextCursor: null, hasMore: false, perPage: 2 },
            }
        })
        const wrapper = await mount({ cursorPaging: true }, list)
        await button(wrapper, 'Next page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 3'))
        await enabled(wrapper, 'Previous page')

        failFirst = true
        await button(wrapper, 'Previous page').trigger('click')
        await vi.waitFor(() => expect(list.requests).toHaveLength(3))
        await flushPromises()
        await enabled(wrapper, 'Previous page')
        expect(wrapper.text()).toContain('Tag 3')
        expect(button(wrapper, 'Previous page').attributes('disabled')).toBeUndefined()

        await button(wrapper, 'Previous page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 1'))
        expect(query(list.requests.at(-1)!).cursor).toBe('')
        expect(button(wrapper, 'Previous page').attributes('disabled')).toBeDefined()
    })

    it('stays on an uncounted page when a new search fails, and goes back to the first page once it is answered', async () => {
        let failures = 1
        const list = endpoint('/vector/r/tags', (request) => {
            const { page, q } = query(request)
            if (q === 'tag' && failures-- > 0) return refused

            return { body: { data: q === 'tag' ? rows([9]) : Number(page) === 1 ? rows([1, 2]) : rows([3]), currentPage: Number(page), perPage: 2, total: null, lastPage: null, hasMore: !q && Number(page) === 1 } }
        })
        const wrapper = await mount({ withoutCount: true }, list)
        await button(wrapper, 'Next page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Page 2'))
        await enabled(wrapper, 'Previous page')

        // the search for page 1 is refused: still page 2, its rows, and a way back
        await wrapper.get('input[aria-label="Search"]').setValue('tag')
        await vi.waitFor(() => expect(query(list.requests.at(-1)!).q).toBe('tag'), { timeout: 2000 })
        expect(query(list.requests.at(-1)!).page).toBe('1')
        await flushPromises()
        await enabled(wrapper, 'Previous page')
        expect(wrapper.text()).toContain('Page 2')
        expect(wrapper.text()).toContain('Tag 3')

        // any step now asks for the first page of the search again, and takes it once it arrives
        await button(wrapper, 'Previous page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 9'))
        expect(query(list.requests.at(-1)!)).toMatchObject({ page: '1', q: 'tag' })
        expect(wrapper.text()).toContain('Page 1')
        expect(button(wrapper, 'Previous page').attributes('disabled')).toBeDefined()
    })

    it('stays on a cursor page when a new search fails', async () => {
        let failures = 1
        const list = endpoint('/vector/r/tags', (request) => {
            const { cursor, q } = query(request)
            if (q === 'tag' && failures-- > 0) return refused

            return {
                body: q === 'tag'
                    ? { data: rows([9]), nextCursor: null, hasMore: false, perPage: 2 }
                    : cursor === ''
                        ? { data: rows([1, 2]), nextCursor: 'after-2', hasMore: true, perPage: 2 }
                        : { data: rows([3]), nextCursor: null, hasMore: false, perPage: 2 },
            }
        })
        const wrapper = await mount({ cursorPaging: true }, list)
        await button(wrapper, 'Next page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 3'))
        await enabled(wrapper, 'Previous page')

        await wrapper.get('input[aria-label="Search"]').setValue('tag')
        await vi.waitFor(() => expect(query(list.requests.at(-1)!).q).toBe('tag'), { timeout: 2000 })
        expect(query(list.requests.at(-1)!).cursor).toBe('')
        await flushPromises()
        await enabled(wrapper, 'Previous page')
        expect(wrapper.text()).toContain('Tag 3')

        await button(wrapper, 'Previous page').trigger('click')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 9'))
        expect(query(list.requests.at(-1)!)).toMatchObject({ cursor: '', q: 'tag' })
        expect(button(wrapper, 'Previous page').attributes('disabled')).toBeDefined()
    })

    it('shows no totals row on a cursor list, even for a resource that summarizes', async () => {
        const summarized = { fields: [...resource().fields.slice(0, 2), { ...resource().fields[0]!, name: 'uses', label: 'Uses', type: 'number' as const, summary: 'sum' as const }] }
        const list = endpoint('/vector/r/tags', () => ({ body: { data: rows([1, 2]).map(r => ({ ...r, uses: 3 })), nextCursor: null, hasMore: false, perPage: 2, summaries: { uses: 6 } } }))
        cleanups.push(list.unregister)
        const base = resource(summarized)
        const wrapper = await mountSuspended(VxResourceTable, { props: { resource: { ...base, table: { ...base.table, columns: ['id', 'name', 'uses'], perPage: 2, perPageOptions: [2, 10], summaries: ['uses'], cursorPaging: true } } } })
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 1'))
        await flushPromises()

        expect(query(list.requests[0]!)).not.toHaveProperty('summaries')
        expect(wrapper.findAll('.v-data-table tbody tr')).toHaveLength(2)
        expect(wrapper.text()).not.toContain('Sum')
    })
})

describe('VxResourceTable, applying a saved view', () => {
    const cleanups: (() => void)[] = []

    beforeEach(() => {
        standaloneApi()
        for (const [url, body] of [['/vector/preferences', { data: {} }], ['/vector/views/tags', { data: [] }]] as const) {
            cleanups.push(endpoint(url, { body }).unregister)
        }
    })
    afterEach(() => cleanups.splice(0).forEach(unregister => unregister()))

    const filterOf = (request: Received) => Object.fromEntries(Object.entries(query(request)).filter(([key]) => key.startsWith('filter')))

    async function apply(filter: VectorFilterQuery) {
        const list = endpoint('/vector/r/tags', () => ({ body: { data: rows([1, 2]), total: 2, page: 1, perPage: 2 } }))
        cleanups.push(list.unregister)
        const wrapper = await mountSuspended(VxResourceTable, { props: { resource: { ...tags({}), softDelete: { field: 'deletedAt' } } } })
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 1'))
        await flushPromises()
        const before = list.requests.length

        const view: VectorSavedView = { id: 7, resource: 'tags', ownerId: '1', name: 'Mine', shared: false, isDefault: false, resourceDefault: false, mine: true, state: { filter } }
        wrapper.findComponent(VxListViewMenu).vm.$emit('apply', view)
        await vi.waitFor(() => expect(list.requests.length).toBeGreaterThan(before))
        await flushPromises()

        return filterOf(list.requests.at(-1)!)
    }

    it('keeps the conditions, groups and deeper parts it can, dropping fields this account cannot see', async () => {
        const sent = await apply({
            name: { like: 'a' },
            secret: { eq: 'x' },
            color: '#fff',
            trashed: 'only',
            or: [{ name: { eq: 'b' }, secret: { eq: 'y' } }, { secret: { eq: 'z' } }],
            and: [
                { id: { gt: '1' }, name: { ne: 'd' } },
                { or: [{ secret: { eq: 'w' } }] },
                { or: [{ and: [{ name: { eq: 'c' } }] }] },
            ],
        })

        expect(sent).toEqual({
            'filter[name][like]': 'a',
            'filter[name][ne]': 'd',
            'filter[color][eq]': '#fff',
            'filter[id][gt]': '1',
            'filter[and][0][or][0][name][eq]': 'b',
            'filter[and][1][or][0][and][0][name][eq]': 'c',
            'filter[trashed]': 'only',
        })
    })

    it('reads a trash setting written as an operator', async () => {
        expect(await apply({ trashed: { eq: 'with' } })).toEqual({ 'filter[trashed]': 'with' })
    })

    it('ignores a trash setting it does not know, and sends a lone group as "or"', async () => {
        expect(await apply({ trashed: 'everything', or: [{ name: { eq: 'b' } }] })).toEqual({ 'filter[or][0][name][eq]': 'b' })
    })
})

describe('VxResourceTable, inline editing', () => {
    const cleanups: (() => void)[] = []

    beforeEach(() => {
        standaloneApi()
        for (const [url, body] of [['/vector/preferences', { data: {} }], ['/vector/views/tags', { data: [] }]] as const) {
            cleanups.push(endpoint(url, { body }).unregister)
        }
    })
    afterEach(() => cleanups.splice(0).forEach(unregister => unregister()))

    it('puts an inline change\'s 422 on its cell, ignoring an entry that is not a violation', async () => {
        const base = resource()
        const inline = resource({ fields: base.fields.map(f => f.name === 'name' ? { ...f, inline: true } : f) })
        cleanups.push(endpoint('/vector/r/tags', () => ({ body: { data: rows([1, 2]), total: 2, page: 1, perPage: 2 } })).unregister)
        cleanups.push(endpoint('/vector/r/tags/changes', { status: 422, body: { errors: [null, { path: 'changes' }, { path: ['changes', 0, 'name'], message: 'is already used.' }] } }, 'POST').unregister)
        const wrapper = await mountSuspended(VxResourceTable, { props: { resource: { ...inline, table: { ...inline.table, perPage: 2, perPageOptions: [2, 10] } } } })
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 1'))
        await flushPromises()

        await wrapper.get('button[aria-label="Edit Name"]').trigger('click')
        await vi.waitFor(() => expect(wrapper.find('.vx-inline-editor input').exists()).toBe(true))
        await wrapper.get('.vx-inline-editor input').setValue('Tag 2')
        await wrapper.findAll('button').find(b => b.text() === 'Save changes')!.trigger('click')

        await vi.waitFor(() => expect(wrapper.get('.vx-inline-editor').text()).toContain('is already used.'))
    })

    async function saveRejected(status: number, body: unknown) {
        const base = resource()
        const inline = resource({ fields: base.fields.map(f => f.name === 'name' ? { ...f, inline: true } : f) })
        cleanups.push(endpoint('/vector/r/tags', () => ({ body: { data: rows([1, 2]), total: 2, page: 1, perPage: 2 } })).unregister)
        cleanups.push(endpoint('/vector/r/tags/changes', { status, body }, 'POST').unregister)
        const wrapper = await mountSuspended(VxResourceTable, { props: { resource: { ...inline, table: { ...inline.table, perPage: 2, perPageOptions: [2, 10] } } } })
        await vi.waitFor(() => expect(wrapper.text()).toContain('Tag 1'))
        await flushPromises()
        useVectorNotifications().queue.value = []

        await wrapper.get('button[aria-label="Edit Name"]').trigger('click')
        await vi.waitFor(() => expect(wrapper.find('.vx-inline-editor input').exists()).toBe(true))
        await wrapper.get('.vx-inline-editor input').setValue('Tag 2')
        await wrapper.findAll('button').find(b => b.text() === 'Save changes')!.trigger('click')
        await vi.waitFor(() => expect(useVectorNotifications().queue.value.length).toBeGreaterThan(0))

        return useVectorNotifications().queue.value.map(toast => [toast.text, toast.color])
    }

    it('reports a 422 entry naming no changed cell, with its field when it has one', async () => {
        expect(await saveRejected(422, { errors: [{ path: ['changes', 0, 'color'], message: 'is not a colour.' }, { path: ['changes'], message: 'too many.' }] }))
            .toEqual([['color: is not a colour.', 'error'], ['too many.', 'error']])
    })

    it('reports a conflict', async () => {
        expect(await saveRejected(409, { error: 'Changed meanwhile.' })).toEqual([[useVectorT().t('list.inline.conflict'), 'error']])
    })

    it('reports any other failure as the server words it', async () => {
        expect(await saveRejected(500, { error: 'Store down.' })).toEqual([['Store down.', 'error']])
    })
})
