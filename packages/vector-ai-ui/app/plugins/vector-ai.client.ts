import VxAiCredentials from '../components/VxAi/Credentials.vue'
import VxAiFilterAsk from '../components/VxAi/FilterAsk.vue'
import en from '../i18n/en'

/**
 * Registers the AI layer's parts through the admin's own extension
 * points: its messages, the provider-key panel under the `ai` settings
 * group, and the plain-language filter in every list's toolbar. Every
 * part renders nothing until the descriptor carries `capabilities.ai`
 * (the filter, until `capabilities.ai.filters`), so against a backend
 * without kinetis/vector-ai the admin is the plain one.
 */
export default defineNuxtPlugin({
    name: 'vector-ai',
    setup() {
        useNuxtApp().$vectorI18n.global.mergeLocaleMessage('en', en)
        const extend = useVectorExtend()
        extend.defineSettingsAddon('ai', VxAiCredentials)
        extend.defineListToolbarItem('ai-filters', VxAiFilterAsk)
    },
})
