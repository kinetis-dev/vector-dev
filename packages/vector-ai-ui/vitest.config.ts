import { defineVitestConfig } from '@nuxt/test-utils/config'

// A zone far from UTC, so a date that leaks through an instant shows up as
// the day before; the test workers inherit it from this process.
process.env.TZ = 'Pacific/Honolulu'

// Every test runs inside a Nuxt app built from this layer, so auto-imports,
// app config, plugins (vue-i18n, Vuetify) and Pinia behave as in the admin.
export default defineVitestConfig({
    test: {
        environment: 'nuxt',
        environmentOptions: {
            nuxt: { domEnvironment: 'happy-dom' },
        },
        include: ['test/nuxt/**/*.test.ts'],
        setupFiles: ['test/nuxt/setup.ts'],
        // Booting the Nuxt app (Vuetify included) runs in each file's beforeAll.
        hookTimeout: 60_000,
        restoreMocks: true,
        unstubGlobals: true,
    },
})
