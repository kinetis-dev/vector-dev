<script setup lang="ts">
import { linkRecords } from '../../utils/recordLinks'
import ApprovalCard from './ApprovalCard.vue'

// The assistant: a sparkle button in the app bar (a shell action) and the
// end-side drawer it opens, holding the conversation, a composer, "New
// conversation" and a line saying which page the questions are about.
// Shell actions belong to the layout, so the drawer and its conversation
// survive navigation. An answer is Markdown, rendered and sanitized as the
// admin renders a markdown field, its `resource#id` references made links
// to the records. A change the assistant proposed (capabilities.ai.assistantWrites)
// shows as an approval card under its answer: approving or rejecting it
// sends the decision, and the assistant then says what happened. Shown
// only when capabilities.ai.assistant is on.


/** The tools that only propose a change. */
const PROPOSING = ['vector_create', 'vector_update', 'vector_delete', 'vector_run_action']

// the layout's own attributes (data-shell-action) go on the button: the drawer is elsewhere
defineOptions({ inheritAttrs: false })

const host = useVectorHost()
const appConfig = useAppConfig()
const route = useRoute()
const { t } = useVectorT()
const { open, draft, turns, busy, refused, send, retry, reset, decide } = useVectorAssistant()

const shown = computed(() => aiOffered(aiCapabilities(host.schema.value), 'assistant'))
const writes = computed(() => aiOffered(aiCapabilities(host.schema.value), 'assistantWrites'))
/** The turns the drawer shows: a decision on a proposal is told by its card, not as a message. */
const shownTurns = computed(() => turns.value.filter(turn => turn.role !== 'approval'))
const slugs = computed(() => new Set(Object.keys(host.schema.value?.resources ?? {})))

/** Which page the questions are about, in words. */
const contextLine = computed(() => {
    void route.fullPath // follows navigation
    const ctx = host.context()
    const resource = ctx.resource === undefined ? undefined : host.schema.value?.resources?.[ctx.resource]
    if (resource === undefined) return t('ai.assistant.context.page', { path: ctx.path })
    return ctx.recordId === undefined
        ? t('ai.assistant.context.list', { label: resource.label })
        : t('ai.assistant.context.record', { label: resource.labelSingular ?? resource.label, id: ctx.recordId })
})

function html(content: string): string {
    return linkRecords(host.markdownHtml(content), slugs.value, appConfig.vector.basePath)
}

/** A record link in an answer navigates inside the admin, rather than reloading it. */
function follow(event: MouseEvent) {
    const link = (event.target as Element | null)?.closest('a[data-record-link]')
    if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return
    event.preventDefault()
    void navigateTo(link.getAttribute('href') ?? '/')
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault()
        void send(draft.value)
    }
}

// the drawer lives in the application's layout, beside the page, wherever the shell action is rendered
const target = ref<string | null>(null)
onMounted(() => {
    target.value = document.querySelector('.v-application') ? '.v-application' : null
})
</script>

<template>
    <template v-if="shown">
        <v-btn v-bind="$attrs" icon="mdi-creation-outline" variant="text" :aria-label="t('ai.assistant.open')" :aria-expanded="open" data-ai-assistant-toggle @click="open = !open" />
        <Teleport :to="target ?? 'body'" :disabled="target === null">
            <v-navigation-drawer v-model="open" location="end" width="400" class="vx-ai-assistant" data-ai-assistant>
                <div class="d-flex flex-column fill-height">
                    <v-toolbar density="compact" color="transparent" :title="t('ai.assistant.title')">
                        <v-btn size="small" variant="text" prepend-icon="mdi-plus" :disabled="busy" data-ai-assistant-new @click="reset">{{ t('ai.assistant.new') }}</v-btn>
                        <v-btn icon="mdi-close" size="small" variant="text" :aria-label="t('ai.assistant.close')" @click="open = false" />
                    </v-toolbar>
                    <p class="text-caption text-medium-emphasis px-4 mb-2" data-ai-assistant-context>
                        <v-icon icon="mdi-map-marker-outline" size="x-small" class="me-1" />{{ contextLine }}
                    </p>
                    <v-divider />
                    <div class="flex-grow-1 overflow-y-auto pa-3" aria-live="polite" data-ai-assistant-conversation>
                        <p v-if="turns.length === 0" class="text-body-2 text-medium-emphasis" data-ai-assistant-empty>{{ t(writes ? 'ai.assistant.emptyWrites' : 'ai.assistant.empty') }}</p>
                        <div v-for="turn in shownTurns" :key="turn.id" class="mb-3" :data-ai-assistant-turn="turn.role">
                            <v-sheet v-if="turn.role === 'user'" color="primary" variant="tonal" rounded class="pa-2 ms-8 text-body-2 vx-ai-assistant__user">{{ turn.content }}</v-sheet>
                            <div v-else class="me-4">
                                <div v-if="turn.tools.length" class="d-flex flex-wrap ga-1 mb-1" data-ai-assistant-tools>
                                    <v-chip v-for="(tool, i) in turn.tools" :key="`${tool}.${i}`" size="x-small" variant="outlined" :prepend-icon="PROPOSING.includes(tool) ? 'mdi-pencil-outline' : 'mdi-database-search-outline'">{{ t(PROPOSING.includes(tool) ? 'ai.assistant.proposingTool' : 'ai.assistant.tool', { name: tool }) }}</v-chip>
                                </div>
                                <!-- eslint-disable-next-line vue/no-v-html -- Markdown sanitized by the admin's own renderer; the record links are built, not parsed -->
                                <div v-if="turn.content" class="text-body-2 vx-ai-assistant__answer" data-ai-assistant-answer @click="follow" v-html="html(turn.content)" />
                                <ApprovalCard
                                    v-for="approval in turn.approvals"
                                    :key="approval.frame.id"
                                    :approval="approval"
                                    :busy="busy"
                                    @approve="decide(approval, 'approve')"
                                    @reject="decide(approval, 'reject')"
                                />
                                <v-progress-linear v-if="turn.streaming" indeterminate color="primary" class="mt-1" data-ai-assistant-streaming />
                                <v-alert v-if="turn.failed" type="warning" variant="tonal" density="compact" class="mt-1" data-ai-assistant-failure>
                                    {{ turn.failed }}
                                    <template #append>
                                        <v-btn v-if="turn === turns[turns.length - 1]" size="small" variant="text" :disabled="busy" data-ai-assistant-retry @click="retry">{{ t('ai.assistant.retry') }}</v-btn>
                                    </template>
                                </v-alert>
                            </div>
                        </div>
                    </div>
                    <v-divider />
                    <div class="pa-3">
                        <v-alert v-if="refused" type="warning" variant="tonal" density="compact" class="mb-2" data-ai-assistant-refused>
                            {{ refused }}
                        </v-alert>
                        <v-textarea
                            v-model="draft"
                            :placeholder="t('ai.assistant.placeholder')"
                            :aria-label="t('ai.assistant.placeholder')"
                            rows="2"
                            auto-grow
                            max-rows="6"
                            :maxlength="8000"
                            density="compact"
                            hide-details
                            data-ai-assistant-input
                            @keydown="onKeydown"
                        >
                            <template #append-inner>
                                <v-btn icon="mdi-send" size="small" variant="text" :loading="busy" :disabled="busy || draft.trim() === ''" :aria-label="t('ai.assistant.send')" data-ai-assistant-send @click="send(draft)" />
                            </template>
                        </v-textarea>
                    </div>
                </div>
            </v-navigation-drawer>
        </Teleport>
    </template>
</template>

<style scoped>
.vx-ai-assistant__user { white-space: pre-wrap; }
.vx-ai-assistant__answer :deep(p) { margin-bottom: 0.5em; }
.vx-ai-assistant__answer :deep(pre) { white-space: pre-wrap; }
</style>
