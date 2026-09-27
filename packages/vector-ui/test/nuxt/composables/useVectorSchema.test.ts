import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { adoptMinorUnits, minorDigits } from '#vector/app/utils/format'
import { endpoint, standaloneApi } from '../endpoints'
import { resource, schema } from '../fixtures'

const descriptor = schema(
    [
        resource({ slug: 'products', label: 'Products', group: 'Catalog' }),
        resource({ slug: 'tags', label: 'Tags', group: 'Catalog' }),
        resource({ slug: 'tickets', label: 'Tickets', group: 'Support' }),
    ],
    { minorUnits: { JPY: 0 } },
)
// a group may name a resource the identity may not see: it is left out of the menu
descriptor.menu[0]!.resources.push('secrets')

let cleanup = () => {}

beforeEach(() => {
    standaloneApi()
    useVectorSchema().clear()
})

afterEach(() => {
    cleanup()
    adoptMinorUnits({})
})

describe('useVectorSchema', () => {
    it('loads the descriptor once and shares it', async () => {
        const get = endpoint('/vector/schema', { body: descriptor })
        cleanup = get.unregister

        await useVectorSchema().load()
        await useVectorSchema().load()

        expect(get.requests).toHaveLength(1)
        expect(useVectorSchema().resource('tags')?.label).toBe('Tags')
        expect(useVectorSchema().resource('nope')).toBeUndefined()
    })

    it('reloads when forced: after login the permission overlay differs', async () => {
        const get = endpoint('/vector/schema', { body: descriptor })
        cleanup = get.unregister

        await useVectorSchema().load()
        await useVectorSchema().load(true)

        expect(get.requests).toHaveLength(2)
    })

    it('adopts the currency exponents it publishes', async () => {
        cleanup = endpoint('/vector/schema', { body: descriptor }).unregister

        await useVectorSchema().load()

        expect(minorDigits('JPY')).toBe(0)
    })

    it('builds the menu from its groups, skipping what the identity cannot see', async () => {
        cleanup = endpoint('/vector/schema', { body: descriptor }).unregister
        const { load, menu } = useVectorSchema()

        await load()

        expect(menu.value.map(g => [g.label, g.items.map(r => r.slug)])).toEqual([
            ['Catalog', ['products', 'tags']],
            ['Support', ['tickets']],
        ])
    })

    it('is not left loading after a failure', async () => {
        cleanup = endpoint('/vector/schema', { status: 500, body: { error: 'Down.' } }).unregister
        const { load, loading, schema: loaded } = useVectorSchema()

        await expect(load()).rejects.toMatchObject({ status: 500 })

        expect(loading.value).toBe(false)
        expect(loaded.value).toBeNull()
    })
})
