import { describe, expect, it } from 'vitest'
import * as components from 'vuetify/components'

// Every Vuetify component the curated set hands an extension; tree-shaking keeps no other.
const curated = [
    'VAlert', 'VAutocomplete', 'VBtn', 'VCard', 'VCheckbox', 'VChip', 'VIcon', 'VProgressLinear', 'VRating', 'VSelect', 'VSwitch',
    'VTable', 'VTextField', 'VTextarea',
    // added for the queue dashboard's pages
    'VDialog', 'VTabs', 'VTab', 'VTabsWindow', 'VTabsWindowItem', 'VTooltip', 'VMenu', 'VList', 'VListItem', 'VExpansionPanels',
    'VExpansionPanel', 'VExpansionPanelTitle', 'VExpansionPanelText', 'VPagination', 'VDivider', 'VSkeletonLoader',
] as const

describe('window.Vector', () => {
    it('is version 1 of the extension API', () => {
        expect(window.Vector?.version).toBe(1)
    })

    it.each(curated)('hands an extension Vuetify\'s own %s', (name) => {
        expect(components[name]).toBeDefined()
        expect(window.Vector?.vuetify[name]).toBe(components[name])
    })

    it('hands over exactly the curated set', () => {
        expect(Object.keys(window.Vector?.vuetify ?? {}).sort()).toEqual([...curated].sort())
    })
})
