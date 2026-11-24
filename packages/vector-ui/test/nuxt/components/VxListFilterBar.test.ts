import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import VxListFilterBar from '#vector/app/components/Vx/List/FilterBar.vue'
import { field, resource } from '../fixtures'

// The filter bar names a choice field's values as the UI shows them: a
// flag group's keys by their labels, a country in the UI's language.
const profiles = resource({
    slug: 'customer-profiles',
    fields: [
        field({ name: 'contact', label: 'Contact by', type: 'booleanGroup', storage: 'columns', filterable: true, operators: ['any', 'all'], options: [{ value: 'newsletter', label: 'Newsletter', color: null }, { value: 'acceptsSms', label: 'Text message', color: null }] }),
        field({ name: 'country', type: 'country', filterable: true, operators: ['eq', 'ne', 'in', 'nin'], options: [{ value: 'DE', label: 'Germany', color: null }, { value: 'NL', label: 'Netherlands', color: null }] }),
    ],
})

async function chips(filters: Record<string, Record<string, string>>) {
    const wrapper = await mountSuspended(VxListFilterBar, { props: { resource: profiles, modelValue: filters } })
    await flushPromises()
    return wrapper
}

describe('VxListFilterBar', () => {
    it('names a flag group\'s keys by their labels', async () => {
        const bar = await chips({ contact: { all: 'newsletter,acceptsSms' } })
        expect(bar.find('.v-chip').text()).toBe('Contact by has all of Newsletter, Text message')
    })

    it('names countries in the UI\'s language', async () => {
        const locale = useNuxtApp().$vectorI18n.global.locale
        locale.value = 'nl'

        try {
            const bar = await chips({ country: { in: 'DE,NL' } })
            await expect.poll(() => bar.find('.v-chip').text()).toContain('Duitsland, Nederland')
        } finally {
            locale.value = 'en'
        }
    })
})
