import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import VxInputUpload from '#vector/app/components/Vx/Input/Upload.vue'
import { endpoint, standaloneApi } from '../endpoints'
import { field, resource, schema } from '../fixtures'

// A refused upload says why: the first violation of the server's 422,
// whatever else its `errors` holds.

const cleanups: (() => void)[] = []

beforeEach(() => {
    standaloneApi()
    useState('vector.schema').value = schema([resource()], { capabilities: { impersonation: false, queue: false, uploads: true } })
})
afterEach(() => {
    cleanups.splice(0).forEach(unregister => unregister())
    useState('vector.schema').value = null
})

describe('VxInputUpload', () => {
    it('names the first violation of a refused upload, ignoring an entry that is not one', async () => {
        cleanups.push(endpoint('/vector/r/tags/fields/logo/upload', { status: 422, body: { detail: 'The request data failed validation.', errors: [null, { message: 42 }, { path: ['file'], message: 'is not an image.' }] } }, 'POST').unregister)
        const wrapper = await mountSuspended(VxInputUpload, { props: { field: field({ name: 'logo', type: 'image' }), rules: [], errorMessages: [], resource: 'tags' } })

        wrapper.findComponent({ name: 'VFileInput' }).vm.$emit('update:modelValue', new File(['x'], 'logo.png', { type: 'image/png' }))
        await flushPromises()

        await vi.waitFor(() => expect(wrapper.text()).toContain('is not an image.'))
    })

    // update permission decides an upload per record, so editing one names it; a new record names none
    it('names the record being edited, and none on a new one', async () => {
        const upload = endpoint('/vector/r/tags/fields/logo/upload', { body: { key: 'vector/tags/logo/k.png', name: 'logo.png', size: 1, type: 'image/png' } }, 'POST')
        cleanups.push(upload.unregister)

        for (const record of [{ id: 7 }, { id: undefined }]) {
            const wrapper = await mountSuspended(VxInputUpload, { props: { field: field({ name: 'logo', type: 'image' }), rules: [], errorMessages: [], resource: 'tags', record } })
            wrapper.findComponent({ name: 'VFileInput' }).vm.$emit('update:modelValue', new File(['x'], 'logo.png', { type: 'image/png' }))
            await flushPromises()
        }

        await vi.waitFor(() => expect(upload.requests).toHaveLength(2))
        expect(upload.requests.map(r => r.body)).toEqual([{ file: 'logo.png', record: '7' }, { file: 'logo.png' }])
    })
})
