// The reference admin: nothing but the layer and where the API is.
export default defineNuxtConfig({
    extends: ['@kinetis/vector-ui'],

    compatibilityDate: '2026-09-24',

    devtools: { enabled: true },

    // The dev server runs in a container over a bind mount, where native
    // file events do not arrive: poll instead.
    watchers: {
        chokidar: { usePolling: true, interval: 1000 },
    },
    vite: {
        server: { watch: { usePolling: true, interval: 1000 } },
    },

    runtimeConfig: {
        public: {
            vector: {
                apiBase: 'http://localhost:8080',
                // The demo's runtime extension, served same-origin from public/vector-extensions.
                extensions: ['/vector-extensions/stock-level.js', '/vector-extensions/stock-overview.js'],
            },
        },
    },

    // The demo's extension modules live with the PHP application's public
    // files and are served here as well, so both deployment modes load the
    // same file from their own origin.
    nitro: {
        publicAssets: [{ dir: '../../backend/public/vector-extensions', baseURL: '/vector-extensions', maxAge: 0 }],
    },
})
