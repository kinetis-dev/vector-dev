<script setup lang="ts">
import type { VectorField, VectorResource } from '#vector/app/types/vector'

// A rich-text value. On the record page it is shown as HTML — and this is
// the one `v-html` in the layer. It is safe because the HTML is never the
// stored value: it is generated here from the document through the
// field's fixed schema (anything outside the schema cannot be expressed),
// then passed through DOMPurify with an allow-list matching that schema
// (no style, no event handlers, links only to http(s), mailto or relative
// places). Images carry their storage key and are fetched with the API's
// credentials. In a list, the plain text, shortened.
const props = defineProps<{ field: VectorField, value: unknown, record: Record<string, unknown>, resource: VectorResource, context: 'index' | 'show' }>()

const { t } = useVectorT()
const client = useVectorResource(() => props.resource.slug)
const { richText: custom } = useVectorExtensions()
const extensions = richTextExtensions({ features: props.field.richtext?.features ?? [], custom })
const text = computed(() => typeof props.value === 'string' ? props.value : '')
const html = computed(() => props.context === 'show' && text.value !== '' ? richTextHtml(text.value, extensions) : '')
const plain = computed(() => props.context === 'index' && text.value !== '' ? richTextPlain(text.value, extensions) : '')

const root = ref<HTMLElement | null>(null)
watch([html, root], async () => {
    await nextTick()
    const imageField = props.field.richtext?.imageField
    const id = props.record.id
    if (!root.value || !imageField || id === undefined || id === null) return

    for (const img of root.value.querySelectorAll<HTMLImageElement>('img[data-vector-key]')) {
        const key = img.dataset.vectorKey!
        client.fileBlob(id as string | number, imageField, key).then(dataUrl).then((url) => { img.src = url }).catch(() => undefined)
    }
}, { immediate: true })
</script>

<template>
    <span v-if="text === ''" class="text-disabled">{{ t('common.none') }}</span>
    <!-- eslint-disable-next-line vue/no-v-html -- generated through the schema, then sanitized (see above) -->
    <div v-else-if="context === 'show'" ref="root" class="vx-richtext-view text-body-1" v-html="html" />
    <span v-else class="text-truncate d-inline-block" style="max-width: 24rem">{{ plain }}</span>
</template>
