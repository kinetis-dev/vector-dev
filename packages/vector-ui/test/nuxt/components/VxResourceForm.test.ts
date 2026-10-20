import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import VxResourceForm from '#vector/app/components/Vx/Resource/Form.vue'
import { endpoint, standaloneApi } from '../endpoints'
import { field, resource, rule } from '../fixtures'

const tags = resource()
const cleanups: (() => void)[] = []
const mock = (...args: Parameters<typeof endpoint>) => {
    const registered = endpoint(...args)
    cleanups.push(registered.unregister)
    return registered
}

beforeEach(() => {
    standaloneApi()
    useVectorNotifications().queue.value = []
})

// unmounted after each test: a page form listens to the keyboard (Ctrl/Cmd+S), and the app shows one at a time
const mounted: VueWrapper[] = []
afterEach(() => {
    cleanups.splice(0).forEach(unregister => unregister())
    mounted.splice(0).forEach(wrapper => wrapper.unmount())
})

async function mountForm(props: InstanceType<typeof VxResourceForm>['$props']) {
    const wrapper = await mountSuspended(VxResourceForm, { props })
    mounted.push(wrapper)
    await flushPromises() // inputs are async components
    await vi.waitFor(() => expect(wrapper.findAll('input').length).toBeGreaterThan(0))
    return wrapper
}

/** The input a field renders, found by its visible label. */
function input(wrapper: VueWrapper, label: string) {
    const control = wrapper.findAll('.v-input').find(i => i.find('label').exists() && i.find('label').text() === label)
    if (!control) throw new Error(`No input labelled ${label}; have: ${wrapper.findAll('.v-input label').map(l => l.text()).join(', ')}`)
    return control
}

/** The label of every input shown, once each (an outlined field renders its label twice: resting and floating). */
const labels = (wrapper: VueWrapper) => wrapper.findAll('.v-input').map(i => i.find('label').text())

async function submit(wrapper: VueWrapper) {
    await wrapper.find('form').trigger('submit')
    await flushPromises()
}

describe('VxResourceForm', () => {
    it('renders one input per editable field, never the id', async () => {
        const form = await mountForm({ resource: tags, operation: 'create' })

        expect(labels(form)).toEqual(['Name', 'Color'])
        expect(form.text()).toContain('Create')
    })

    it('checks the client rules before sending anything', async () => {
        const create = mock('/vector/r/tags', { body: { data: { id: 1 }, permissions: {} } }, 'POST')
        const form = await mountForm({ resource: tags, operation: 'create' })

        await submit(form)

        await vi.waitFor(() => expect(input(form, 'Name').text()).toContain('Required'))
        expect(input(form, 'Color').text()).toContain('Required')
        expect(create.requests).toHaveLength(0)
        expect(form.emitted('saved')).toBeUndefined()

        await input(form, 'Name').find('input').setValue('x'.repeat(61))
        await submit(form)
        await vi.waitFor(() => expect(input(form, 'Name').text()).toContain('At most 60 characters'))
        expect(create.requests).toHaveLength(0)
    })

    it('creates the record with every editable field and reports it saved', async () => {
        const create = mock('/vector/r/tags', { status: 201, body: { data: { id: 42, name: 'Sale', color: '#ff0000' }, permissions: {} } }, 'POST')
        const form = await mountForm({ resource: tags, operation: 'create' })

        await input(form, 'Name').find('input').setValue('Sale')
        await input(form, 'Color').find('input').setValue('#ff0000')
        await submit(form)

        await vi.waitFor(() => expect(form.emitted('saved')).toBeDefined())
        expect(create.requests[0]!.body).toEqual({ name: 'Sale', color: '#ff0000' })
        expect(form.emitted('saved')![0]).toEqual([{ id: 42, name: 'Sale', color: '#ff0000' }, 'close'])
        expect(useVectorNotifications().queue.value).toContainEqual(expect.objectContaining({ text: 'Tag saved.', color: 'success' }))
    })

    it('puts the server\'s 422 on the field it names, and clears it once edited', async () => {
        mock('/vector/r/tags', { status: 422, body: { errors: [{ path: ['name'], message: 'this name is already used.' }] } }, 'POST')
        const form = await mountForm({ resource: tags, operation: 'create' })

        await input(form, 'Name').find('input').setValue('Sale')
        await input(form, 'Color').find('input').setValue('#ff0000')
        await submit(form)

        await vi.waitFor(() => expect(input(form, 'Name').text()).toContain('This name is already used.'))
        expect(form.emitted('saved')).toBeUndefined()

        await input(form, 'Name').find('input').setValue('Summer sale')
        expect(input(form, 'Name').text()).not.toContain('This name is already used.')
    })

    it('shows a failure that names no field above the form', async () => {
        mock('/vector/r/tags', { status: 403, body: { error: 'You may not create tags.' } }, 'POST')
        const form = await mountForm({ resource: tags, operation: 'create' })

        await input(form, 'Name').find('input').setValue('Sale')
        await input(form, 'Color').find('input').setValue('#ff0000')
        await submit(form)

        await vi.waitFor(() => expect(form.find('.v-alert').text()).toContain('You may not create tags.'))
    })

    it('updates only what changed, with the record version', async () => {
        const update = mock('/vector/r/tags/7', { body: { data: { id: 7, name: 'Winter sale', color: '#0000ff' }, permissions: {} } }, 'PATCH')
        const form = await mountForm({ resource: tags, operation: 'update', record: { id: 7, name: 'Sale', color: '#0000ff', _version: 4 } })

        expect(input(form, 'Name').find('input').element.value).toBe('Sale')
        expect(form.text()).toContain('Save')

        await input(form, 'Name').find('input').setValue('Winter sale')
        await submit(form)

        await vi.waitFor(() => expect(form.emitted('saved')).toBeDefined())
        expect(update.requests[0]!.body).toEqual({ name: 'Winter sale' })
        expect(update.requests[0]!.headers.get('if-match')).toBe('"4"')
    })

    it('follows conditions: a field shows only while its condition holds', async () => {
        const products = resource({
            slug: 'products',
            label: 'Products',
            labelSingular: 'Product',
            fields: [
                field({ name: 'name', nullable: false, rules: [rule('NotBlank')] }),
                field({
                    name: 'code',
                    label: 'Release code',
                    conditions: { visible: [{ field: 'name', op: 'ne', value: 'draft' }], required: [], disabled: [] },
                }),
            ],
        })
        const form = await mountForm({ resource: products, operation: 'create' })

        await input(form, 'Name').find('input').setValue('draft')
        expect(labels(form)).toEqual(['Name'])

        await input(form, 'Name').find('input').setValue('Mug')
        expect(labels(form)).toEqual(['Name', 'Release code'])
    })

    it('emits cancel', async () => {
        const form = await mountForm({ resource: tags, operation: 'create' })

        await form.findAll('button').find(b => b.text() === 'Cancel')!.trigger('click')

        expect(form.emitted('cancel')).toHaveLength(1)
    })
})

describe('VxResourceForm: unsaved changes and save variants', () => {
    const exposed = (form: VueWrapper) => form.vm as unknown as { dirty: boolean, reset: () => void }
    const menuItems = () => [...document.querySelectorAll<HTMLElement>('.v-overlay__content .v-list-item')]

    it('knows when it holds unsaved changes', async () => {
        const form = await mountForm({ resource: tags, operation: 'update', record: { id: 7, name: 'Sale', color: '#0000ff', _version: 1 } })
        expect(exposed(form).dirty).toBe(false)

        await input(form, 'Name').find('input').setValue('Winter sale')
        expect(exposed(form).dirty).toBe(true)

        await input(form, 'Name').find('input').setValue('Sale')
        expect(exposed(form).dirty).toBe(false)
    })

    it('is clean again once saved', async () => {
        mock('/vector/r/tags/7', { body: { data: { id: 7, name: 'Winter sale', color: '#0000ff' }, permissions: {} } }, 'PATCH')
        const form = await mountForm({ resource: tags, operation: 'update', record: { id: 7, name: 'Sale', color: '#0000ff', _version: 1 } })

        await input(form, 'Name').find('input').setValue('Winter sale')
        await submit(form)

        await vi.waitFor(() => expect(form.emitted('saved')).toBeDefined())
        expect(exposed(form).dirty).toBe(false)
    })

    it('offers the variants on a page, and saves with the one picked', async () => {
        const create = mock('/vector/r/tags', { status: 201, body: { data: { id: 9, name: 'Sale', color: '#ff0000' }, permissions: {} } }, 'POST')
        const prefs = mock('/vector/preferences', { body: { data: { 'form.saveVariant': 'another' } } }, 'PUT')
        const form = await mountForm({ resource: tags, operation: 'create', variants: true })
        await input(form, 'Name').find('input').setValue('Sale')
        await input(form, 'Color').find('input').setValue('#ff0000')

        await form.find('button[aria-label="More ways to save"]').trigger('click')
        await flushPromises()
        expect(menuItems().map(i => i.textContent?.trim())).toEqual(['Create', 'Create and continue editing', 'Create and add another'])

        menuItems()[2]!.click()
        await vi.waitFor(() => expect(form.emitted('saved')).toBeDefined())
        expect(form.emitted('saved')![0]![1]).toBe('another')
        expect(create.requests).toHaveLength(1)
        expect(prefs.requests[0]!.body).toEqual({ 'form.saveVariant': 'another' })
    })

    it('starts from the variant this user saved with last, where the form offers it', async () => {
        useVectorPreferences().preferences.value = { 'form.saveVariant': 'another' }
        const creating = await mountForm({ resource: tags, operation: 'create', variants: true })
        expect(creating.find('button[type="submit"]').text()).toBe('Create and add another')

        // editing offers no "add another": the default is back to the record
        const editing = await mountForm({ resource: tags, operation: 'update', record: { id: 7, name: 'Sale', color: '#0000ff' }, variants: true })
        expect(editing.find('button[type="submit"]').text()).toBe('Save')
        useVectorPreferences().preferences.value = { 'form.saveVariant': 'continue' }
        await flushPromises()
        expect(editing.find('button[type="submit"]').text()).toBe('Save and continue editing')
        useVectorPreferences().clear()
    })

    it('saves with Ctrl+S', async () => {
        const update = mock('/vector/r/tags/7', { body: { data: { id: 7, name: 'Keyboard', color: '#0000ff' }, permissions: {} } }, 'PATCH')
        const form = await mountForm({ resource: tags, operation: 'update', record: { id: 7, name: 'Sale', color: '#0000ff' }, variants: true })
        await input(form, 'Name').find('input').setValue('Keyboard')

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 's', ctrlKey: true }))

        await vi.waitFor(() => expect(update.requests).toHaveLength(1))
        expect(form.emitted('saved')![0]![1]).toBe('close')
    })

    it('resets to a fresh form with the defaults', async () => {
        const withDefault = resource({ fields: [field({ name: 'name', nullable: false }), field({ name: 'color', type: 'color', hasDefault: true, default: '#1f5f8b' })] })
        const form = await mountForm({ resource: withDefault, operation: 'create', variants: true })
        await input(form, 'Name').find('input').setValue('Sale')
        await input(form, 'Color').find('input').setValue('#000000')

        exposed(form).reset()
        await flushPromises()

        expect(input(form, 'Name').find('input').element.value).toBe('')
        expect(input(form, 'Color').find('input').element.value).toBe('#1f5f8b')
        expect(exposed(form).dirty).toBe(false)
    })

    it('keeps the plain buttons in a dialog', async () => {
        const form = await mountForm({ resource: tags, operation: 'create' })

        expect(form.find('button[aria-label="More ways to save"]').exists()).toBe(false)
        expect(form.find('button[type="submit"]').text()).toBe('Create')
    })
})

describe('VxResourceForm: a copy of a record', () => {
    const products = resource({
        slug: 'products',
        label: 'Products',
        labelSingular: 'Product',
        fields: [
            field({ name: 'sku', label: 'SKU', nullable: false, unique: true }),
            field({ name: 'name', nullable: false }),
            field({ name: 'status', type: 'enum', options: [{ value: 'draft', label: 'Draft', color: null }, { value: 'active', label: 'Active', color: null }] }),
            field({ name: 'category', type: 'belongsTo', relation: { kind: 'belongsTo', resource: 'categories', titleField: 'name' } }),
            field({ name: 'photo', type: 'image', props: { accept: { 'image/png': 'png' }, maxBytes: 1000 } }),
            field({ name: 'tags', type: 'manyToMany', relation: { kind: 'manyToMany', resource: 'tags', titleField: 'name' } }),
        ],
    })
    const source = { id: 5, sku: 'SKU-5', name: 'Mug', status: 'active', category: { id: 3, label: 'Kitchen' }, photo: 'vector/products/photo/abc.png', tags: { count: 2 } }

    // the relation inputs resolve their choices' labels
    beforeEach(() => {
        mock('/vector/r/products/fields/category/options', { body: { data: [{ id: 3, label: 'Kitchen' }] } })
        mock('/vector/r/products/fields/tags/options', { body: { data: [{ id: 1, label: 'Sale' }, { id: 4, label: 'New' }] } })
    })

    it('starts from the record, without its unique values, files or id', async () => {
        const tags = mock('/vector/r/products/5/tags', { body: { data: [{ id: 1 }, { id: 4 }], currentPage: 1, perPage: 100, total: 2, lastPage: 1 } })
        const form = await mountForm({ resource: products, operation: 'create', copyOf: source })
        await vi.waitFor(() => expect(tags.requests).toHaveLength(1))
        await flushPromises()

        expect(input(form, 'SKU').find('input').element.value).toBe('')
        expect(input(form, 'Name').find('input').element.value).toBe('Mug')
        expect((form.vm as unknown as { dirty: boolean }).dirty).toBe(false)
    })

    it('saves as a new record with the copied values and memberships', async () => {
        mock('/vector/r/products/5/tags', { body: { data: [{ id: 1 }, { id: 4 }], currentPage: 1, perPage: 100, total: 2, lastPage: 1 } })
        const create = mock('/vector/r/products', { status: 201, body: { data: { id: 6 }, permissions: {} } }, 'POST')
        const form = await mountForm({ resource: products, operation: 'create', copyOf: source })
        await flushPromises()
        await input(form, 'SKU').find('input').setValue('SKU-6')

        await submit(form)

        await vi.waitFor(() => expect(create.requests).toHaveLength(1))
        expect(create.requests[0]!.body).toEqual({ sku: 'SKU-6', name: 'Mug', status: 'active', category: 3, photo: null, tags: [1, 4] })
    })

    it('copies every membership of an uncounted target, page by page while another follows', async () => {
        const tags = mock('/vector/r/products/5/tags', (request) => {
            const page = Number(new URL(request.path, 'http://x').searchParams.get('page'))

            return { body: { data: page === 1 ? [{ id: 1 }] : [{ id: 4 }], currentPage: page, perPage: 1, total: null, lastPage: null, hasMore: page === 1 } }
        })
        const create = mock('/vector/r/products', { status: 201, body: { data: { id: 6 }, permissions: {} } }, 'POST')
        const form = await mountForm({ resource: products, operation: 'create', copyOf: source })
        await vi.waitFor(() => expect(tags.requests).toHaveLength(2))
        await flushPromises()
        await input(form, 'SKU').find('input').setValue('SKU-6')

        await submit(form)

        await vi.waitFor(() => expect(create.requests).toHaveLength(1))
        expect(create.requests[0]!.body).toMatchObject({ tags: [1, 4] })
    })
})

