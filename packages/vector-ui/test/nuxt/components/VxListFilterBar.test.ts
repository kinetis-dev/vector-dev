import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { VAutocomplete, VBtn, VCombobox, VSelect } from 'vuetify/components'
import VxListFilterBar from '#vector/app/components/Vx/List/FilterBar.vue'
import { field, resource } from '../fixtures'

// The filter bar names a choice field's values as the UI shows them: a
// flag group's keys by their labels, a country in the UI's language.
const profiles = resource({
    slug: 'customer-profiles',
    fields: [
        field({ name: 'contact', label: 'Contact by', type: 'booleanGroup', storage: 'columns', filterable: true, operators: ['any', 'all'], options: [{ value: 'newsletter', label: 'Newsletter', color: null }, { value: 'acceptsSms', label: 'Text message', color: null }] }),
        field({ name: 'country', type: 'country', filterable: true, autocomplete: true, operators: ['eq', 'ne', 'in', 'nin'], options: [{ value: 'DE', label: 'Germany', color: null }, { value: 'NL', label: 'Netherlands', color: null }] }),
        field({ name: 'served', type: 'country', multiple: true, filterable: true, autocomplete: true, operators: ['any', 'all'], options: [{ value: 'DE', label: 'Germany', color: null }, { value: 'NL', label: 'Netherlands', color: null }] }),
        field({ name: 'keywords', type: 'tag', filterable: true, operators: ['any', 'all'] }),
    ],
})

/** Opens the add menu and picks a field, as a click on "Add filter" and a choice in its field list do. */
async function pickField(bar: VueWrapper, name: string) {
    if (bar.findAllComponents(VSelect).length === 0) {
        await bar.findAll('button').find(b => b.text() === 'Add filter')!.trigger('click')
        await flushPromises()
    }

    bar.findAllComponents(VSelect).find(s => s.props('label') === 'Field')!.vm.$emit('update:modelValue', name)
    await flushPromises()
}

const valueInput = (bar: VueWrapper) => bar.findComponent(VAutocomplete)

/** Applies the menu's condition; the menu's content is teleported, so its button is found as a component. */
async function apply(bar: VueWrapper) {
    await bar.findAllComponents(VBtn).find(b => b.text() === 'Apply')!.trigger('click')
    await flushPromises()
}

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

    it('adds the value picked in a searchable field\'s list', async () => {
        const bar = await chips({})
        await pickField(bar, 'country')
        valueInput(bar).vm.$emit('update:modelValue', 'NL')
        await flushPromises()

        expect(valueInput(bar).props('modelValue')).toBe('NL')
        await apply(bar)
        expect(bar.emitted('update:modelValue')!.at(-1)).toEqual([{ country: { eq: 'NL' } }])
    })

    it('adds a list picked for a list operator', async () => {
        const bar = await chips({})
        await pickField(bar, 'served')
        valueInput(bar).vm.$emit('update:modelValue', ['DE', 'NL'])
        await flushPromises()

        expect(valueInput(bar).props('modelValue')).toEqual(['DE', 'NL'])
        await apply(bar)
        expect(bar.emitted('update:modelValue')!.at(-1)).toEqual([{ served: { any: 'DE,NL' } }])
    })

    it('clears the picked value when another field is chosen', async () => {
        const bar = await chips({})
        await pickField(bar, 'country')
        valueInput(bar).vm.$emit('update:modelValue', 'DE')
        await flushPromises()
        expect(valueInput(bar).props('modelValue')).toBe('DE')

        await pickField(bar, 'served')
        expect(valueInput(bar).props('modelValue')).toEqual([])
        await pickField(bar, 'country')
        expect(valueInput(bar).props('modelValue')).toBeNull()
    })

    it('clears the typed tags when another field is chosen', async () => {
        const bar = await chips({})
        await pickField(bar, 'keywords')
        bar.findComponent(VCombobox).vm.$emit('update:modelValue', ['urgent', 'vip'])
        await flushPromises()
        expect(bar.findComponent(VCombobox).props('modelValue')).toEqual(['urgent', 'vip'])

        await pickField(bar, 'country')
        await pickField(bar, 'keywords')
        expect(bar.findComponent(VCombobox).props('modelValue')).toEqual([])
    })
})
