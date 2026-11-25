import { afterEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { h } from 'vue'
import { VForm } from 'vuetify/components'
import SelectInput from '#vector/app/components/Vx/Input/Select.vue'
import { field } from '../fixtures'

const mounted: VueWrapper[] = []

afterEach(() => {
    mounted.splice(0).forEach(wrapper => wrapper.unmount())
})

const options = [{ value: 'a', label: 'A', color: null }, { value: 'b', label: 'B', color: null }]

async function mountSelect(props: { autocomplete?: boolean, disabled?: boolean }) {
    const wrapper = await mountSuspended(SelectInput, {
        props: { field: field({ name: 'size', type: 'select', options, autocomplete: props.autocomplete }), rules: [], errorMessages: [], modelValue: 'a', ...(props.disabled === undefined ? {} : { disabled: props.disabled }) },
    })
    mounted.push(wrapper)
    await flushPromises()
    return wrapper
}

describe('VxInputSelect', () => {
    it('renders disabled when disabled is given, for a select and an autocomplete', async () => {
        for (const autocomplete of [false, true]) {
            const disabled = await mountSelect({ autocomplete, disabled: true })
            expect(disabled.find('.v-input').classes(), `autocomplete: ${autocomplete}`).toContain('v-input--disabled')
            expect(disabled.find('.v-field').classes(), `autocomplete: ${autocomplete}`).toContain('v-field--disabled')

            const enabled = await mountSelect({ autocomplete })
            expect(enabled.find('.v-input').classes(), `autocomplete: ${autocomplete}`).not.toContain('v-input--disabled')
            expect(enabled.find('.v-field').classes(), `autocomplete: ${autocomplete}`).not.toContain('v-field--disabled')
        }
    })

    it('follows a disabled form when disabled is not given', async () => {
        const wrapper = await mountSuspended({
            render: () => h(VForm, { disabled: true }, () => h(SelectInput, { field: field({ name: 'size', type: 'select', options }), rules: [], errorMessages: [], modelValue: 'a' })),
        })
        mounted.push(wrapper)
        await flushPromises()
        expect(wrapper.find('.v-input').classes()).toContain('v-input--disabled')
    })
})
