import { afterEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import VxAiCredentials from '#vector/app/components/VxAi/Credentials.vue'
import { DESCRIPTOR_VERSION } from '#vector/app/composables/useVectorSchema'
import { endpoint, standaloneApi } from './endpoints'

// The provider-key panel under the `ai` settings group: nothing at all
// against a backend without kinetis/vector-ai (no capabilities.ai), and
// with it, each provider's status and never a stored key.

function descriptor(capabilities: Record<string, unknown>) {
    return { version: DESCRIPTOR_VERSION, resources: {}, menu: [], settings: [], dashboards: [], capabilities: { impersonation: false, queue: false, ...capabilities }, branding: null, ui: { expected: null } }
}

describe('the AI provider-key panel', () => {
    const cleanups: (() => void)[] = []

    afterEach(() => {
        cleanups.splice(0).forEach(cleanup => cleanup())
        useState('vector.schema').value = null
    })

    it('renders nothing, and asks nothing, without capabilities.ai', async () => {
        standaloneApi()
        const credentials = endpoint('/vector/ai/credentials', { body: {} })
        cleanups.push(credentials.unregister)
        useState('vector.schema').value = descriptor({})
        const wrapper = await mountSuspended(VxAiCredentials, { props: { group: 'ai' } })
        await flushPromises()

        expect(wrapper.find('[data-ai-credentials]').exists()).toBe(false)
        expect(wrapper.text()).toBe('')
        expect(credentials.requests).toHaveLength(0)
        wrapper.unmount()
    })

    it('shows each provider\'s status with capabilities.ai, and sends a key once without showing it', async () => {
        standaloneApi()
        cleanups.push(endpoint('/vector/ai/credentials', {
            body: {
                openai: { set: true, managedBy: 'admin', storable: true, updatedAt: '2026-11-19T10:00:00.000Z', updatedBy: '1' },
                bedrock: { set: false, managedBy: 'environment', storable: false },
                stale: { set: true, managedBy: 'admin', storable: true, updatedAt: '2026-11-01T10:00:00.000Z', updatedBy: '2', sealedWithAnotherKey: true },
            },
        }).unregister)
        const put = endpoint('/vector/ai/credentials/openai', { body: { set: true, managedBy: 'admin', storable: true, updatedAt: '2026-11-19T11:00:00.000Z', updatedBy: '1' } }, 'PUT')
        cleanups.push(put.unregister)
        useState('vector.schema').value = descriptor({ ai: { filters: false, assistant: false, assistantWrites: false, writing: false, excluded: {} } })
        const wrapper = await mountSuspended(VxAiCredentials, { props: { group: 'ai' } })
        await vi.waitFor(() => expect(wrapper.find('[data-ai-provider="openai"]').exists()).toBe(true))

        expect(wrapper.find('[data-ai-provider="openai"]').text()).toContain('Key set')
        expect(wrapper.find('[data-ai-provider="openai"]').text()).toContain('Set by user 1')
        expect(wrapper.find('[data-ai-provider="bedrock"]').text()).toContain('Managed by the environment')
        expect(wrapper.find('[data-ai-provider="bedrock"] input').exists()).toBe(false)
        expect(wrapper.find('[data-ai-provider="stale"]').text()).toContain('Sealed with another key; re-enter it.')

        const input = wrapper.find<HTMLInputElement>('[data-ai-provider="openai"] input')
        expect(input.attributes('type')).toBe('password')
        expect(input.element.value).toBe('')
        await input.setValue('sk-typed-SECRET')
        await wrapper.find('[data-ai-provider="openai"] button').trigger('click')
        await vi.waitFor(() => expect(put.requests).toHaveLength(1))
        await flushPromises()

        expect(put.requests[0]!.body).toEqual({ key: 'sk-typed-SECRET' })
        expect(input.element.value).toBe('') // the key leaves the page once sent
        expect(wrapper.html()).not.toContain('sk-typed-SECRET')
        wrapper.unmount()
    })

    it('asks for VECTOR_AI_KEY when a key is stored but the server has none to open it', async () => {
        standaloneApi()
        cleanups.push(endpoint('/vector/ai/credentials', {
            body: {
                orphan: { set: true, managedBy: 'admin', storable: false, updatedAt: '2026-11-01T10:00:00.000Z', updatedBy: '2', problem: 'Set VECTOR_AI_KEY to use the stored key.' },
            },
        }).unregister)
        useState('vector.schema').value = descriptor({ ai: { filters: false, assistant: false, assistantWrites: false, writing: false, excluded: {} } })
        const wrapper = await mountSuspended(VxAiCredentials, { props: { group: 'ai' } })
        await vi.waitFor(() => expect(wrapper.find('[data-ai-provider="orphan"]').exists()).toBe(true))

        const row = wrapper.find('[data-ai-provider="orphan"]')
        expect(row.find('[data-ai-cannot-store]').text()).toBe('Set VECTOR_AI_KEY on the server to store provider keys.')
        expect(row.text()).not.toContain('Sealed with another key')
        expect(row.find('input').exists()).toBe(false)
        wrapper.unmount()
    })
})
