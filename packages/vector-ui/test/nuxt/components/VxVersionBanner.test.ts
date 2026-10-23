import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import VxVersionBanner from '#vector/app/components/Vx/VersionBanner.vue'
import type { VectorSchema } from '#vector/app/types/vector'
import { endpoint, standaloneApi } from '../endpoints'
import { schema } from '../fixtures'
// the layer's own release, which the build bakes into runtime config
import { version } from '../../../package.json'

const DISMISSED = 'vector.uiVersion.dismissed'

function pin(expected: string | null) {
    useState<VectorSchema | null>('vector.schema').value = schema([], { ui: { expected } })
}

function host() {
    window.__VECTOR__ = {
        apiBase: '',
        basePath: '',
        authMode: 'cookie',
        loginUrl: '/login',
        logoutUrl: '/logout',
        csrfToken: null,
        cspNonce: null,
        user: { id: 1, name: 'Vector Admin' },
        branding: null,
        extensions: [],
    }
}

async function banner() {
    const wrapper = await mountSuspended(VxVersionBanner)
    await flushPromises()
    return wrapper
}

let cleanup = () => {}

beforeEach(() => {
    standaloneApi()
    sessionStorage.clear()
    useState<string | null>(DISMISSED).value = null
    useVectorSchema().clear()
})

afterEach(() => {
    cleanup()
    cleanup = () => {}
    delete window.__VECTOR__
})

describe('the UI version', () => {
    it('is the layer\'s package.json version, baked into runtime config', () => {
        expect(useRuntimeConfig().public.vector.uiVersion).toBe(version)
        expect(version).toMatch(/^\d+\.\d+\.\d+$/)
    })
})

describe('VxVersionBanner', () => {
    it('warns when the UI is not the release kinetis/vector pins', async () => {
        pin('99.0.0')
        const w = await banner()

        expect(w.find('.vx-version-banner').exists()).toBe(true)
        expect(w.text()).toContain(`This admin UI is version ${version}; kinetis/vector expects 99.0.0.`)
    })

    it('says nothing when the UI is the pinned release', async () => {
        pin(version)
        const w = await banner()

        expect(w.find('.vx-version-banner').exists()).toBe(false)
    })

    it('says nothing when kinetis/vector pins no release', async () => {
        pin(null)
        const w = await banner()

        expect(w.find('.vx-version-banner').exists()).toBe(false)
    })

    it('tells a layered app to upgrade the package and rebuild', async () => {
        pin('99.0.0')
        const w = await banner()

        expect(w.text()).toContain('Upgrade @kinetis/vector-ui to 99.0.0 and rebuild.')
        expect(w.text()).not.toContain('vector:install-ui')
    })

    it('tells a hosted app to run vector:install-ui and restart the workers', async () => {
        host()
        pin('99.0.0')
        const w = await banner()

        expect(w.text()).toContain('Run vendor/bin/kinetis vector:install-ui on the server, then restart the workers.')
        expect(w.text()).not.toContain('rebuild')
    })

    it('stays dismissed for this browser session, for the versions it was dismissed for', async () => {
        pin('99.0.0')
        const w = await banner()

        await w.find('.vx-version-banner button[aria-label="Dismiss"]').trigger('click')
        await flushPromises()

        expect(w.find('.vx-version-banner').exists()).toBe(false)
        expect(sessionStorage.getItem(DISMISSED)).toBe(`${version}→99.0.0`)
        // another mount in the same session stays quiet
        expect((await banner()).find('.vx-version-banner').exists()).toBe(false)

        // another expected release is another warning
        pin('100.0.0')
        expect((await banner()).find('.vx-version-banner').exists()).toBe(true)
    })

    it('reads a dismissal this browser session made before the app loaded', async () => {
        sessionStorage.setItem(DISMISSED, `${version}→99.0.0`)
        clearNuxtState(DISMISSED)
        pin('99.0.0')

        expect((await banner()).find('.vx-version-banner').exists()).toBe(false)
    })
})

describe('an unsupported descriptor', () => {
    const other = () => ({ ...schema([], { ui: { expected: '99.0.0' } }), version: 999 })

    it('stops a layered app with the upgrade-and-rebuild remedy', async () => {
        cleanup = endpoint('/vector/schema', { body: other() }).unregister

        await expect(useVectorSchema().load()).rejects.toThrow(
            'The API publishes descriptor version 999; this admin reads version 4. Upgrade @kinetis/vector-ui to 99.0.0 and rebuild.',
        )
    })

    it('stops a layered app without a pin with the generic remedy', async () => {
        cleanup = endpoint('/vector/schema', { body: { ...other(), ui: { expected: null } } }).unregister

        await expect(useVectorSchema().load()).rejects.toThrow('Install the @kinetis/vector-ui release this kinetis/vector expects and rebuild.')
    })

    it('reads the mode before the request, not after it answers', async () => {
        host()
        cleanup = endpoint('/vector/schema', () => {
            // the mode changing while the request is in flight changes nothing
            delete window.__VECTOR__
            return { body: other() }
        }).unregister

        await expect(useVectorSchema().load()).rejects.toThrow('Run vendor/bin/kinetis vector:install-ui on the server, then restart the workers.')
    })

    it('stops a hosted app with the install-ui remedy', async () => {
        host()
        cleanup = endpoint('/vector/schema', { body: other() }).unregister

        await expect(useVectorSchema().load()).rejects.toThrow(
            'The API publishes descriptor version 999; this admin reads version 4. Run vendor/bin/kinetis vector:install-ui on the server, then restart the workers.',
        )
    })
})
