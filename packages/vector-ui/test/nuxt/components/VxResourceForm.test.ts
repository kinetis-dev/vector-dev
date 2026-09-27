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

afterEach(() => cleanups.splice(0).forEach(unregister => unregister()))

async function mountForm(props: InstanceType<typeof VxResourceForm>['$props']) {
    const wrapper = await mountSuspended(VxResourceForm, { props })
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
        expect(form.emitted('saved')![0]).toEqual([{ id: 42, name: 'Sale', color: '#ff0000' }])
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
