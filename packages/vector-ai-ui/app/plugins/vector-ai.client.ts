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
 * toolbars, and the assistant — its app-bar button and drawer, and "Ask the assistant…" in
 * the command palette, which opens the drawer with what was typed. Every
 * part renders nothing until the descriptor carries `capabilities.ai`
 * (the filter, until `capabilities.ai.filters`; writing help, until
 * `capabilities.ai.writing`; the assistant and its command, until
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
        extend.defineInputAddon('ai-writing', { types: ['string', 'text'], component: VxAiWritingAddon })
        extend.defineEditorToolbarItem('ai-writing', VxAiWritingToolbarItem)
        extend.defineShellAction('ai-assistant', VxAiAssistant)

        // the palette command is there only while the assistant is offered
        const nuxtApp = useNuxtApp()
        const { schema } = useVectorSchema()
        const { paletteCommands } = useVectorExtensions()
        const { t } = useVectorT()
        watch(() => aiOffered(aiCapabilities(schema.value), 'assistant'), (offered) => {
            if (offered) {
                // run when chosen, in the app's context: the API client is the one configured then
                extend.definePaletteCommand('ai-ask', { title: () => t('ai.assistant.ask'), icon: 'mdi-creation-outline', run: query => nuxtApp.runWithContext(() => useVectorAssistant().ask(query)) })
            } else {
                paletteCommands.delete('ai-ask')
            }
        }, { immediate: true })

        const auth = useVectorAuthStore()
        // whose conversation it is: nobody once the descriptor is cleared on sign-out
        watch(() => (schema.value === null || auth.user === null ? null : String(auth.user.id)), (now, before) => {
            if (now !== before) nuxtApp.runWithContext(() => useVectorAssistant().forget())
        })
    },
})
