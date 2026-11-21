import VxAiAssistant from '../components/VxAi/Assistant.vue'
import VxAiCredentials from '../components/VxAi/Credentials.vue'
import VxAiFilterAsk from '../components/VxAi/FilterAsk.vue'
import VxAiWritingAddon from '../components/VxAi/WritingAddon.vue'
import VxAiWritingToolbarItem from '../components/VxAi/WritingToolbarItem.vue'
import en from '../i18n/en'

/**
 * Registers the AI layer's parts through the admin's own extension
 * points: its messages, the provider-key panel under the `ai` settings
 * group, the plain-language filter in every list's toolbar, writing help
 * under every text input and in the rich-text and Markdown editors'
 * toolbars, and the assistant — its app-bar button and the drawer it
 * opens, which ⌘J (Ctrl+J) also toggles. Every
 * part renders nothing until the descriptor carries `capabilities.ai`
 * (the filter, until `capabilities.ai.filters`; writing help, until
 * `capabilities.ai.writing`; the assistant and its shortcut, until
 * `capabilities.ai.assistant`), so against a backend
 * without kinetis/vector-ai the admin is the plain one. The assistant's
 * conversation is the signed-in user's: it is forgotten when the user
 * changes (an impersonation starting or ending), the session ends, or
 * the admin signs out and clears its descriptor.
 */
export default defineNuxtPlugin({
    name: 'vector-ai',
    setup() {
        useNuxtApp().$vectorI18n.global.mergeLocaleMessage('en', en)
        const extend = useVectorExtend()
        extend.defineSettingsAddon('ai', VxAiCredentials)
        extend.defineListToolbarItem('ai-filters', VxAiFilterAsk)
        extend.defineInputAddon('ai-writing', { types: ['string', 'text'], component: VxAiWritingAddon, placement: 'inner' })
        extend.defineEditorToolbarItem('ai-writing', VxAiWritingToolbarItem)
        extend.defineShellAction('ai-assistant', VxAiAssistant)

        const nuxtApp = useNuxtApp()
        const { schema } = useVectorSchema()
        const auth = useVectorAuthStore()
        // whose conversation it is: nobody once the descriptor is cleared on sign-out
        watch(() => (schema.value === null || auth.user === null ? null : String(auth.user.id)), (now, before) => {
            if (now !== before) nuxtApp.runWithContext(() => useVectorAssistant().forget())
        })
    },
})
