import { afterEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import VxFieldsForm from '#vector/app/components/Vx/FieldsForm.vue'
import VxCommandPalette from '#vector/app/components/Vx/CommandPalette.vue'
import SettingsPage from '#vector/app/pages/settings.vue'
import { markdownHtml, sanitizeHtml } from '#vector/app/utils/richtext'
import { endpoint, standaloneApi } from '../endpoints'
import { field, resource, schema } from '../fixtures'

// window.Vector version 2: every version 1 function as it was, the shell
// slots it adds, the host services, and each slot rendered where the
// admin promises it, with the props it promises.

const Marker = (label: string) => defineComponent({ props: ['field', 'resource', 'record', 'modelValue', 'disabled', 'group'], emits: ['update:modelValue'], setup: (props, { emit }) => () => h('button', { class: `marker-${label}`, 'data-props': JSON.stringify(props), onClick: () => emit('update:modelValue', 'from the addon') }, label) })

describe('window.Vector version 2', () => {
    const cleanups: (() => void)[] = []

    afterEach(() => {
        cleanups.splice(0).forEach(cleanup => cleanup())
        const r = useVectorExtensions()
        for (const map of [r.cells, r.inputs, r.widgets, r.pages, r.shellActions, r.listToolbar, r.inputAddons, r.editorToolbar, r.paletteCommands, r.settingsAddons]) {
            for (const key of [...map.keys()]) if (key.startsWith('test-')) map.delete(key)
        }
        r.settingsAddons.delete('store')
    })

    it('keeps every version 1 function and its behaviour', () => {
        const vector = window.Vector!
        const component = Marker('v1')

        for (const name of ['defineCell', 'defineInput', 'defineWidget', 'defineRichTextExtension', 'defineMessages', 'definePage', 'navigate', 't'] as const) {
            expect(typeof vector[name]).toBe('function')
        }

        vector.defineCell('test-cell', component)
        vector.defineInput('test-input', component)
        vector.defineWidget('test-widget', component)
        vector.definePage('test-page', { title: 'Test', component })
        expect(useVectorExtensions().cells.get('test-cell')).toBe(component)
        expect(useVectorExtensions().inputs.get('test-input')).toBe(component)
        expect(useVectorExtensions().widgets.get('test-widget')).toBe(component)
        expect(useVectorExtensions().pages.get('test-page')?.title).toBe('Test')
        expect(() => vector.definePage('Bad Name', { title: 'x', component })).toThrow('Vector page name "Bad Name" must be lowercase letters, digits and dashes.')
        expect(vector.api).toBeTypeOf('function')
        expect(vector.notify.success).toBeTypeOf('function')
    })

    it('registers into the shell slots, and refuses a malformed name', () => {
        const vector = window.Vector!
        const component = Marker('v2')
        const run = vi.fn()

        vector.defineShellAction('test-action', component)
        vector.defineListToolbarItem('test-toolbar', component)
        vector.defineInputAddon('test-addon', { types: ['string'], component })
        vector.defineEditorToolbarItem('test-editor', component)
        vector.definePaletteCommand('test-command', { title: 'Do it', icon: 'mdi-star', run })
        vector.defineSettingsAddon('test-group', component)

        const r = useVectorExtensions()
        expect(r.shellActions.get('test-action')).toBe(component)
        expect(r.listToolbar.get('test-toolbar')).toBe(component)
        expect(r.inputAddons.get('test-addon')).toEqual({ types: ['string'], component, placement: 'below' })
        expect(r.editorToolbar.get('test-editor')).toBe(component)
        expect(r.paletteCommands.get('test-command')?.run).toBe(run)
        expect(r.settingsAddons.get('test-group')).toBe(component)

        expect(() => vector.defineShellAction('Not ok', component)).toThrow('lowercase letters')
        expect(() => vector.defineInputAddon('test-x', { types: 'string' as unknown as string[], component })).toThrow('types')
        // placement: below by default, inner on request, nothing else
        vector.defineInputAddon('test-inner', { types: ['string'], component, placement: 'inner' })
        expect(r.inputAddons.get('test-inner')).toEqual({ types: ['string'], component, placement: 'inner' })
        expect(() => vector.defineInputAddon('test-z', { types: ['string'], component, placement: 'side' as unknown as 'inner' })).toThrow('placement: below | inner')
        expect(r.inputAddons.has('test-z')).toBe(false)
        expect(() => vector.definePaletteCommand('test-y', { title: 'x', run: undefined as unknown as () => void })).toThrow('run(query)')
    })

    it('lends the same points to a layer through useVectorExtend', () => {
        const extend = useVectorExtend()
        const component = Marker('layer')
        extend.defineShellAction('test-layer', component)

        expect(useVectorExtensions().shellActions.get('test-layer')).toBe(component)
        expect(Object.keys(extend).sort()).toEqual(Object.keys(window.Vector!).filter(k => k.startsWith('define') && k !== 'defineMessages').sort())
    })

    it('offers the host services', async () => {
        const host = window.Vector!.host

        expect(host.vuetify).toBe(window.Vector!.vuetify)
        // the admin's own renderers, whatever this environment's sanitizer can do
        expect(host.markdownHtml).toBe(markdownHtml)
        expect(host.sanitizeHtml).toBe(sanitizeHtml)
        expect(host.locale()).toBe('en')
        expect(host.schema.value === null || typeof host.schema.value === 'object').toBe(true)

        // signed in, so the auth middleware lets the navigation through
        standaloneApi()
        useVectorAuthStore().adopt('jwt', 3600, { id: 1, name: 'Vector Admin' })
        cleanups.push(() => useVectorAuthStore().clear(), endpoint('/vector/schema', { body: schema([resource()]) }).unregister)
        await useRouter().push('/r/tags/5/edit').catch(() => undefined)
        expect(host.context()).toEqual({ path: '/r/tags/5/edit', resource: 'tags', recordId: '5' })
        await useRouter().push('/settings').catch(() => undefined)
        expect(host.context()).toEqual({ path: '/settings' })
    })
})

describe('the shell slots render where promised', () => {
    const cleanups: (() => void)[] = []

    afterEach(() => {
        cleanups.splice(0).forEach(cleanup => cleanup())
        const r = useVectorExtensions()
        r.inputAddons.delete('test-addon')
        r.inputAddons.delete('test-inner')
        r.paletteCommands.delete('test-command')
        r.settingsAddons.delete('store')
    })

    it('puts an input addon under every input of a matching type, and its value reaches the form', async () => {
        useVectorExtend().defineInputAddon('test-addon', { types: ['string'], component: Marker('addon') })
        const values: Record<string, unknown> = { name: 'Widget', stock: 3 }
        const wrapper = await mountSuspended(VxFieldsForm, {
            props: { fields: [field({ name: 'name', type: 'string' }), field({ name: 'stock', type: 'number' })], values, serverErrors: {}, resource: 'products' },
        })

        const addons = wrapper.findAll('.marker-addon')
        expect(addons).toHaveLength(1)
        const props = JSON.parse(addons[0]!.attributes('data-props')!)
        expect(props.field.name).toBe('name')
        expect(props.resource).toBe('products')
        expect(props.modelValue).toBe('Widget')
        expect(props.disabled).toBe(false)

        await addons[0]!.trigger('click')
        expect(values.name).toBe('from the addon')
        wrapper.unmount()
    })

    it('renders nothing extra without addons', async () => {
        const wrapper = await mountSuspended(VxFieldsForm, { props: { fields: [field({ name: 'name', type: 'string', nullable: false }), field({ name: 'notes', type: 'text', nullable: false })], values: { name: '', notes: '' }, serverErrors: {} } })

        expect(wrapper.find('.vx-input-addons').exists()).toBe(false)
        // no inner addon (and nothing to clear): the fields have no append-inner slot at all
        expect(wrapper.find('.v-field__append-inner').exists()).toBe(false)
        wrapper.unmount()
    })

    it('puts an inner addon inside the text and textarea inputs at their end, not under them, and its value reaches the form', async () => {
        useVectorExtend().defineInputAddon('test-inner', { types: ['string', 'text'], component: Marker('inner'), placement: 'inner' })
        useVectorExtend().defineInputAddon('test-addon', { types: ['string'], component: Marker('addon') })
        const values: Record<string, unknown> = { name: 'Widget', notes: 'Some notes', stock: 3 }
        const wrapper = await mountSuspended(VxFieldsForm, {
            props: { fields: [field({ name: 'name', type: 'string' }), field({ name: 'notes', type: 'text' }), field({ name: 'stock', type: 'number' })], values, serverErrors: {}, resource: 'products' },
        })

        const inner = wrapper.findAll('.v-field__append-inner [data-input-addons="inner"] .marker-inner')
        expect(inner).toHaveLength(2)
        expect(wrapper.findAll('.marker-inner')).toHaveLength(2)
        expect(inner[0]!.element.closest('.v-text-field')).not.toBeNull()
        expect(inner[1]!.element.closest('.v-textarea')).not.toBeNull()
        // the below row holds the below addon only
        expect(wrapper.findAll('.vx-input-addons:not(.vx-input-addons--inner) .marker-inner')).toHaveLength(0)
        expect(wrapper.findAll('.vx-input-addons:not(.vx-input-addons--inner) .marker-addon')).toHaveLength(1)
        expect(wrapper.findAll('.v-field__append-inner .marker-addon')).toHaveLength(0)

        const props = JSON.parse(inner[1]!.attributes('data-props')!)
        expect([props.field.name, props.resource, props.modelValue, props.disabled, props.record]).toEqual(['notes', 'products', 'Some notes', false, values])

        await inner[1]!.trigger('click')
        expect(values.notes).toBe('from the addon')
        await inner[0]!.trigger('click')
        expect(values.name).toBe('from the addon')
        wrapper.unmount()
    })

    it('lists palette commands under Commands and runs one with what was typed', async () => {
        const run = vi.fn()
        useVectorExtend().definePaletteCommand('test-command', { title: 'Ask', icon: 'mdi-star', run })
        standaloneApi()
        cleanups.push(endpoint('/vector/search', { body: { results: [] } }).unregister)
        const wrapper = await mountSuspended(VxCommandPalette)
        ;(wrapper.vm as unknown as { open: boolean }).open = true
        await flushPromises()

        const input = document.querySelector<HTMLInputElement>('.v-dialog input')!
        input.value = 'low stock'
        input.dispatchEvent(new Event('input'))
        await flushPromises()

        expect(document.body.textContent).toContain('Commands')
        document.querySelector<HTMLElement>('[data-palette-command="test-command"]')!.click()
        await flushPromises()
        expect(run).toHaveBeenCalledWith('low stock')
        wrapper.unmount()
    })

    it('puts a settings addon under its group, given the group\'s name', async () => {
        standaloneApi()
        useVectorAuthStore().adopt('jwt', 3600, { id: 1, name: 'Vector Admin' })
        cleanups.push(() => useVectorAuthStore().clear())
        const group = { name: 'store', label: 'Store', icon: null, fields: [field({ name: 'title', type: 'string' })], values: { title: 'Shop' } }
        cleanups.push(endpoint('/vector/settings', { body: { data: [group] } }).unregister)
        useVectorExtend().defineSettingsAddon('store', Marker('settings'))
        const wrapper = await mountSuspended(SettingsPage, { route: '/settings' })
        await vi.waitFor(() => expect(wrapper.find('[data-settings-addon="store"]').exists()).toBe(true))

        expect(JSON.parse(wrapper.find('.marker-settings').attributes('data-props')!).group).toBe('store')
        wrapper.unmount()
    })
})
