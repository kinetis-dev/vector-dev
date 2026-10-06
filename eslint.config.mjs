// One flat config for every TypeScript and Vue file in the workspace:
// the @kinetis/vector-ui layer (and its hosted app), the demo frontend
// and the Playwright suite. @nuxt/eslint-config is used standalone, not
// through the @nuxt/eslint module, so linting needs no `nuxt prepare`
// and the published layer gains no module.
import { createConfigForNuxt } from '@nuxt/eslint-config/flat'

export default createConfigForNuxt({
    dirs: {
        src: [
            './packages/vector-ui',
            './packages/vector-ui/app',
            './packages/vector-ui/hosted',
            './demo/frontend',
            './demo/frontend/app',
        ],
    },
}).append({
    rules: {
        // Errors, filters and form values are records keyed by name, and a
        // key is removed with `delete`; a Map would not survive reactivity
        // and JSON the way a record does.
        '@typescript-eslint/no-dynamic-delete': 'off',
        // Vuetify names slots with dots, such as `#body.append` and `#item.<key>`.
        'vue/valid-v-slot': ['error', { allowModifiers: true }],
    },
}).prepend({
    ignores: [
        '**/node_modules/**',
        '**/.nuxt/**',
        '**/.output/**',
        '**/dist/**',
        'packages/vector/**',
        'demo/backend/**',
        'demo/e2e/test-results/**',
        'demo/e2e/playwright-report/**',
    ],
})
