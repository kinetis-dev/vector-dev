// The hosted bundle with AI that kinetis/vector serves at /vector/ui:
// @kinetis/vector-ui with this layer on top, and nothing else. No
// application input: the API origin, the user, the branding and any
// runtime extensions reach it from the PHP application through
// window.__VECTOR__. Built by this package's `pnpm generate:hosted`, with
// NUXT_APP_BASE_URL=/vector/ui/, into packages/vector-ai-ui/dist/static,
// which the npm package ships and `vector-ai:install-ui` installs.
export default defineNuxtConfig({
    extends: ['..'],

    compatibilityDate: '2026-09-24',

    devtools: { enabled: false },
})
