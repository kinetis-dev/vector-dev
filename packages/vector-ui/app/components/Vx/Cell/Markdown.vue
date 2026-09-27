<script setup lang="ts">
// Markdown source. On the record page it is rendered: markdown-it with HTML
// in the source turned off (raw HTML shows as text), then DOMPurify with the
// same allow-list as rich text — the only other `v-html` in the layer. In a
// list, the plain text, shortened.
const props = defineProps<{ value: unknown, context: 'index' | 'show' }>()
const text = computed(() => typeof props.value === 'string' ? props.value : '')
const html = computed(() => props.context === 'show' && text.value !== '' ? markdownHtml(text.value) : '')
const plain = computed(() => props.context === 'index' && text.value !== '' ? markdownPlain(text.value) : '')
const { t } = useVectorT()
</script>

<template>
    <span v-if="text === ''" class="text-disabled">{{ t('common.none') }}</span>
    <!-- eslint-disable-next-line vue/no-v-html -- rendered with HTML off, then sanitized (see above) -->
    <div v-else-if="context === 'show'" class="vx-richtext-view text-body-1" v-html="html" />
    <span v-else class="text-truncate d-inline-block" style="max-width: 24rem">{{ plain }}</span>
</template>
