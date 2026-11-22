import VxAiAssistant from '../components/VxAi/Assistant.vue'
import VxAiCredentials from '../components/VxAi/Credentials.vue'
import VxAiFilterAsk from '../components/VxAi/FilterAsk.vue'
import VxAiWritingAddon from '../components/VxAi/WritingAddon.vue'
import VxAiWritingToolbarItem from '../components/VxAi/WritingToolbarItem.vue'

/**
 * Registers the AI parts through the admin's own extension points: the
 * provider-key panel under the `ai` settings group, the plain-language
 * filter in every list's toolbar, writing help inside every text input
 * and in the rich-text and Markdown editors' toolbars, and the
 * assistant; its app-bar button and the drawer it opens, which ⌘J
 * (Ctrl+J) also toggles. They are registered only while the descriptor
 * carries `capabilities.ai` (kinetis/vector-ai installed on the server),
 * so a plain install registers nothing and looks as it would without
 * them. Each part then renders only while its own flag is on: the filter
 * with `capabilities.ai.filters`, writing help with
 * `capabilities.ai.writing`, the assistant and its shortcut with
 * `capabilities.ai.assistant`. The assistant's conversation is the
 * signed-in user's: it is forgotten when the user changes (an
 * impersonation starting or ending), the session ends, or the admin signs
 * out and clears its descriptor.
 */
export default defineNuxtPlugin({
    name: 'vector-ai',
    setup() {
        const extend = useVectorExtend()
        const registries = useVectorExtensions()
        const nuxtApp = useNuxtApp()
        const { schema } = useVectorSchema()
        const auth = useVectorAuthStore()

        watch(() => aiCapabilities(schema.value) !== undefined, (installed) => {
            if (installed) {
                extend.defineSettingsAddon('ai', VxAiCredentials)
                extend.defineListToolbarItem('ai-filters', VxAiFilterAsk)
                extend.defineInputAddon('ai-writing', { types: ['string', 'text'], component: VxAiWritingAddon, placement: 'inner' })
                extend.defineEditorToolbarItem('ai-writing', VxAiWritingToolbarItem)
                extend.defineShellAction('ai-assistant', VxAiAssistant)
            } else {
                registries.settingsAddons.delete('ai')
                registries.listToolbar.delete('ai-filters')
                registries.inputAddons.delete('ai-writing')
                registries.editorToolbar.delete('ai-writing')
                registries.shellActions.delete('ai-assistant')
            }
        }, { immediate: true })

        // whose conversation it is: nobody once the descriptor is cleared on sign-out
        watch(() => (schema.value === null || auth.user === null ? null : String(auth.user.id)), (now, before) => {
            if (now !== before) nuxtApp.runWithContext(() => useVectorAssistant().forget())
        })
    },
})
