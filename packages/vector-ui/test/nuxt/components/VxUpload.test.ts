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
})
