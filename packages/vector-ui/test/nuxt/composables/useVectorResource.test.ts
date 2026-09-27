import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { filterQuery } from '#vector/app/composables/useVectorResource'
import { endpoint, standaloneApi } from '../endpoints'

describe('filterQuery', () => {
    it('writes each condition as filter[field][op]', () => {
        expect(filterQuery({ status: { eq: 'active' }, stock: { lte: '10', gte: '1' } })).toEqual({
            'filter[status][eq]': 'active',
            'filter[stock][lte]': '10',
            'filter[stock][gte]': '1',
        })
    })

    it('writes a bare string as it is: trashed=only', () => {
        expect(filterQuery({ trashed: 'only' })).toEqual({ 'filter[trashed]': 'only' })
    })

    it('nests or/and groups by position', () => {
        expect(filterQuery({ stock: { gt: '0' }, or: [{ status: { eq: 'active' } }, { isFeatured: { eq: 'true' } }] })).toEqual({
            'filter[stock][gt]': '0',
            'filter[or][0][status][eq]': 'active',
            'filter[or][1][isFeatured][eq]': 'true',
        })
    })

    it('skips undefined values', () => {
        expect(filterQuery({ status: undefined, stock: { lt: undefined, gt: '3' } })).toEqual({ 'filter[stock][gt]': '3' })
        expect(filterQuery(undefined)).toEqual({})
    })
})

describe('useVectorResource', () => {
    const cleanups: (() => void)[] = []

    beforeEach(() => standaloneApi())
    afterEach(() => cleanups.splice(0).forEach(unregister => unregister()))

    it('lists with the whole list grammar in the query string', async () => {
        const list = endpoint('/vector/r/products', { body: { data: [], currentPage: 2, perPage: 10, total: 0, lastPage: 1 } })
        cleanups.push(list.unregister)

        await useVectorResource('products').list({
            page: 2,
            perPage: 10,
            sort: [{ field: 'stock', order: 'desc' }, { field: 'name', order: 'asc' }],
            q: 'mug',
            summaries: true,
            columns: ['name', 'stock'],
            filter: { status: { eq: 'active' } },
        })

        const url = new URL(list.requests[0]!.path, 'http://x')
        expect(Object.fromEntries(url.searchParams)).toEqual({
            'page': '2',
            'perPage': '10',
            'sort': '-stock,name',
            'q': 'mug',
            'summaries': '1',
            'columns': 'name,stock',
            'filter[status][eq]': 'active',
        })
    })

    it('updates with the record version as an If-Match precondition', async () => {
        const update = endpoint('/vector/r/tags/7', { body: { data: { id: 7 }, permissions: {} } }, 'PATCH')
        cleanups.push(update.unregister)

        await useVectorResource('tags').update(7, { name: 'Sale' }, 3)

        expect(update.requests[0]!.headers.get('if-match')).toBe('"3"')
        expect(update.requests[0]!.body).toEqual({ name: 'Sale' })
    })

    it('encodes ids and slugs into the path', async () => {
        const show = endpoint('/vector/r/tags/a%2Fb', { body: { data: { id: 'a/b' }, permissions: {} } })
        cleanups.push(show.unregister)

        const response = await useVectorResource('tags').show('a/b')

        expect(response.data.id).toBe('a/b')
    })
})
