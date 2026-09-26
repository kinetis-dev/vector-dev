/**
 * Hosted under a strict Content-Security-Policy, the shell hands the
 * bundle the response's nonce; Vuetify puts it on the style element it
 * creates for the theme, so `style-src 'nonce-…'` admits it.
 */
export default defineNuxtPlugin((nuxtApp) => {
    nuxtApp.hook('vuetify:before-create', ({ vuetifyOptions }) => {
        const nonce = window.__VECTOR__?.cspNonce

        if (nonce) {
            vuetifyOptions.theme = { ...(typeof vuetifyOptions.theme === 'object' ? vuetifyOptions.theme : {}), cspNonce: nonce }
        }
    })
})
