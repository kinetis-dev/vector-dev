import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import JobsPage from '#vector/app/pages/jobs/index.vue'
import SettingsPage from '#vector/app/pages/settings.vue'
import { endpoint, standaloneApi } from '../endpoints'
import { schema } from '../fixtures'

// A page whose list fails to load says why, as the record page does,
// instead of showing an empty list.

const cleanups: (() => void)[] = []
const mock = (...args: Parameters<typeof endpoint>) => {
    const registered = endpoint(...args)
    cleanups.push(registered.unregister)
    return registered
}

beforeEach(() => {
    standaloneApi()
    clearNuxtData()
    useVectorAuthStore().adopt('jwt', 3600, { id: 1, name: 'Vector Admin' })
    mock('/vector/schema', { body: schema([]) })
})
afterEach(() => {
    cleanups.splice(0).forEach(unregister => unregister())
    useVectorAuthStore().clear()
})

describe('a page whose load fails', () => {
    it('jobs: shows the error instead of an empty list', async () => {
        mock('/vector/jobs', { status: 500, body: { error: 'The job store is down.' } })

        const wrapper = await mountSuspended(JobsPage)

        expect(wrapper.find('.v-alert').text()).toContain('The job store is down.')
        expect(wrapper.text()).not.toContain(useVectorT().t('jobs.empty'))
    })

    it('settings: shows the error instead of saying there are no settings', async () => {
        mock('/vector/settings', { status: 500, body: { error: 'The settings store is down.' } })

        const wrapper = await mountSuspended(SettingsPage)

        expect(wrapper.find('.v-alert').text()).toContain('The settings store is down.')
        expect(wrapper.text()).not.toContain(useVectorT().t('settings.empty.title'))
    })
})
