// The hosted bundle kinetis/vector serves at /vector/ui: this layer and
// nothing else. No application input — the API origin, the user, the
// branding and any runtime extensions reach it from the PHP application
// through window.__VECTOR__. Built by the repository's
// `pnpm generate:hosted`, with NUXT_APP_BASE_URL=/vector/ui/, into
// packages/vector/resources/dist.
export default defineNuxtConfig({
    extends: ['..'],

    compatibilityDate: '2026-09-24',

    devtools: { enabled: false },
})
