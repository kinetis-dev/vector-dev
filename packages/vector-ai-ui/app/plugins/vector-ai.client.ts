import VxAiCredentials from '../components/VxAi/Credentials.vue'
import en from '../i18n/en'

/**
 * Registers the AI layer's parts through the admin's own extension
 * points: its messages, and the provider-key panel under the `ai`
 * settings group. Every part renders nothing until the descriptor carries
 * `capabilities.ai`, so against a backend without kinetis/vector-ai the
 * admin is the plain one.
 */
export default defineNuxtPlugin({
    name: 'vector-ai',
    setup() {
        useNuxtApp().$vectorI18n.global.mergeLocaleMessage('en', en)
        useVectorExtend().defineSettingsAddon('ai', VxAiCredentials)
    },
})
