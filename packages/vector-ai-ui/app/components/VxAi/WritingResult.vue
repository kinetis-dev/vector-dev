<script setup lang="ts">
// Writing help's answer beside the text it was asked about, before it
// touches the input: Replace puts it there (the person saves it as their
// own edit), Discard drops it. A Markdown answer renders only through the
// admin's own Markdown sanitizer; plain text renders as text.
const props = defineProps<{
    before: string
    after: string
    format: 'plain' | 'markdown'
    truncated: boolean
}>()

defineEmits<{ replace: [], discard: [] }>()

const { t } = useVectorT()
const host = useVectorHost()
const html = (text: string) => host.markdownHtml(text)
</script>

<template>
    <v-card variant="outlined" class="vx-ai-writing-result pa-3" data-ai-writing-result>
        <div class="d-flex flex-wrap ga-3">
            <div class="vx-ai-writing-side">
                <div class="text-caption text-medium-emphasis">{{ t('ai.writing.before') }}</div>
                <!-- eslint-disable-next-line vue/no-v-html -- sanitized by the admin's own Markdown renderer -->
                <div v-if="props.format === 'markdown'" class="vx-richtext-view" data-ai-writing-before v-html="html(props.before)" />
                <div v-else class="vx-ai-writing-text" data-ai-writing-before>{{ props.before }}</div>
            </div>
            <div class="vx-ai-writing-side">
                <div class="text-caption text-medium-emphasis">{{ t('ai.writing.after') }}</div>
                <!-- eslint-disable-next-line vue/no-v-html -- sanitized by the admin's own Markdown renderer -->
                <div v-if="props.format === 'markdown'" class="vx-richtext-view" data-ai-writing-after v-html="html(props.after)" />
                <div v-else class="vx-ai-writing-text" data-ai-writing-after>{{ props.after }}</div>
            </div>
        </div>
        <div v-if="props.truncated" class="text-caption text-warning mt-2" data-ai-writing-truncated>{{ t('ai.writing.truncated') }}</div>
        <div class="d-flex justify-end ga-2 mt-2">
            <v-btn variant="text" size="small" data-ai-writing-discard @click="$emit('discard')">{{ t('ai.writing.discard') }}</v-btn>
            <v-btn color="primary" variant="flat" size="small" data-ai-writing-replace @click="$emit('replace')">{{ t('ai.writing.replace') }}</v-btn>
        </div>
    </v-card>
</template>

<style>
.vx-ai-writing-result { width: 100%; }
.vx-ai-writing-side { flex: 1 1 240px; min-width: 0; }
.vx-ai-writing-text { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
