import { fileURLToPath } from 'node:url'

// Paths inside a layer's config resolve against the consuming app, so
// anything that names a file in this package goes through this alias.
const layerDir = fileURLToPath(new URL('./', import.meta.url))

export default defineNuxtConfig({
    $meta: {
        name: 'vector',
    },

    modules: ['vuetify-nuxt-module', '@pinia/nuxt', '@vueuse/nuxt'],

    // A back-office application: rendered in the browser only, hostable
    // as static files (standalone) or from the PHP application (hosted).
    ssr: false,

    runtimeConfig: {
        public: {
            vector: {
                // Overridden by NUXT_PUBLIC_VECTOR_API_BASE.
                apiBase: 'http://localhost:8080',
                // Runtime UI extensions (ES-module URLs) in standalone mode; hosted mode reads VECTOR_UI_EXTENSIONS.
                extensions: [] as string[],
            },
        },
    },

    vuetify: {
        moduleOptions: {
            prefixComposables: true,
        },
        vuetifyOptions: {
            theme: {
                defaultTheme: 'system',
                themes: {
                    // medium-emphasis text at 0.74 (Vuetify's 0.6 default) keeps subtitles and hints above WCAG AA contrast
                    light: {
                        dark: false,
                        colors: {
                            primary: '#1F5F8B',
                            secondary: '#546E7A',
                            // Status colours dark enough for white text at WCAG AA (4.5:1): Vuetify's
                            // defaults (#4CAF50, #FB8C00, #2196F3) fall below it on toasts and chips.
                            success: '#2E7D32',
                            warning: '#B45309',
                            info: '#1565C0',
                            error: '#C62828',
                        },
                        variables: { 'medium-emphasis-opacity': 0.74 },
                    },
                    dark: {
                        dark: true,
                        colors: {
                            primary: '#5AA9E6',
                            secondary: '#90A4AE',
                        },
                        variables: { 'medium-emphasis-opacity': 0.8 },
                    },
                },
            },
            // The house style every generated view shares; a consuming app
            // overrides any entry in its own nuxt.config.
            defaults: {
                VTextField: { variant: 'outlined', density: 'comfortable' },
                VTextarea: { variant: 'outlined', density: 'comfortable' },
                VSelect: { variant: 'outlined', density: 'comfortable' },
                VAutocomplete: { variant: 'outlined', density: 'comfortable' },
                VNumberInput: { variant: 'outlined', density: 'comfortable' },
                VBtn: { variant: 'flat' },
                VCard: { variant: 'flat', border: true },
            },
        },
    },

    typescript: {
        strict: true,
    },

    alias: {
        '#vector': layerDir,
    },
})
