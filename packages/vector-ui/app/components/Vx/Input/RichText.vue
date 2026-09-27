<script setup lang="ts">
import { EditorContent, useEditor } from '@tiptap/vue-3'
import type { Editor } from '@tiptap/core'
import type { VectorField } from '#vector/app/types/vector'

// A rich-text field: a Tiptap editor over exactly the field's schema. Its
// value is the document as JSON text — null when empty — which the server
// validates against the same schema. Pasted content outside the schema is
// dropped by construction: ProseMirror keeps only what the schema holds.
// An image is uploaded through the field's image field and stored by its
// key; a mention picks a record of the field's `mentions` relation.
const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string }>()
const model = defineModel<string | null>()

const { t } = useVectorT()
const client = useVectorResource(() => props.resource ?? '')
const { richText: custom } = useVectorExtensions()
const rich = computed(() => props.field.richtext ?? { features: [], imageField: null, mentions: null, storesHtml: false, maxBytes: 262_144 })
const on = (feature: string) => rich.value.features.includes(feature)

// images uploaded in this form, shown from the picked file until the record is saved
const uploaded = new Map<string, string>()

async function imageSource(key: string): Promise<string | null> {
    if (uploaded.has(key)) return uploaded.get(key)!
    const id = props.record?.id
    if (id === undefined || id === null || !rich.value.imageField) return null
    return await dataUrl(await client.fileBlob(id as string | number, rich.value.imageField, key))
}

async function mentionItems(query: string) {
    if (!rich.value.mentions) return []
    return (await client.options(rich.value.mentions, query)).data.slice(0, 8).map(o => ({ id: o.id, label: o.label }))
}

const extensions = richTextExtensions({ features: rich.value.features, placeholder: props.field.help ?? undefined, imageSource, mentionItems, custom })
let emitting = false

const editor = useEditor({
    content: model.value ? toDocument(model.value, extensions) : null,
    extensions,
    // Tiptap's own style element carries the hosted shell's nonce, so a strict style-src admits it
    injectNonce: import.meta.client ? (window.__VECTOR__?.cspNonce ?? undefined) : undefined,
    editorProps: { attributes: { 'class': 'vx-richtext-content', 'aria-label': props.field.label, 'role': 'textbox', 'aria-multiline': 'true' } },
    onUpdate: ({ editor: current }) => {
        const doc = current.getJSON()
        emitting = true
        model.value = isEmptyDocument(doc) ? null : JSON.stringify(doc)
    },
})

// a value set from outside (a reset, a repeater row) replaces the content
watch(model, (value) => {
    if (emitting) {
        emitting = false
        return
    }
    editor.value?.commands.setContent(value ? toDocument(value, extensions) : null, { emitUpdate: false })
})

onBeforeUnmount(() => editor.value?.destroy())

const active = (name: string, attrs?: Record<string, unknown>) => editor.value?.isActive(name, attrs) ?? false
const run = (command: (e: Editor) => void) => {
    if (editor.value) command(editor.value)
}

// links: http(s), mailto or relative only, as the server requires
const linkMenu = ref(false)
const linkHref = ref('')
const linkError = computed(() => linkHref.value.trim() !== '' && !allowedHref(linkHref.value.trim()) ? t('fields.richtext.linkRefused') : null)
function openLink() {
    linkHref.value = String(editor.value?.getAttributes('link').href ?? '')
    linkMenu.value = true
}
function applyLink() {
    const href = linkHref.value.trim()
    if (linkError.value) return
    run(e => href === '' ? e.chain().focus().unsetLink().run() : e.chain().focus().extendMarkRange('link').setLink({ href }).run())
    linkMenu.value = false
}

// images: uploaded at once through the image field, stored by key
const picker = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
const uploadError = ref<string | null>(null)
async function insertImage(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0]
    ;(event.target as HTMLInputElement).value = ''
    if (!file || !rich.value.imageField) return
    uploading.value = true
    uploadError.value = null
    try {
        const { key } = await client.upload(rich.value.imageField, file)
        uploaded.set(key, await dataUrl(file))
        run(e => e.chain().focus().setImage({ src: key, alt: file.name }).run())
    } catch (error) {
        uploadError.value = vectorErrorMessage(error)
    } finally {
        uploading.value = false
    }
}

const inTable = computed(() => active('table'))
</script>

<template>
    <v-input :model-value="model" :rules="rules" :error-messages="uploadError ? [uploadError, ...errorMessages] : errorMessages" :hint="field.help ?? undefined" :persistent-hint="!!field.help" class="vx-richtext">
        <div class="vx-richtext-frame" :class="{ 'vx-richtext-frame--error': errorMessages.length > 0 }">
            <div class="text-caption text-medium-emphasis px-3 pt-2">{{ field.label }}</div>
            <div class="vx-richtext-toolbar d-flex flex-wrap align-center ga-1 px-2 py-1" role="toolbar" :aria-label="t('fields.richtext.toolbar', { label: field.label })">
                <v-btn v-if="on('bold')" icon="mdi-format-bold" size="small" variant="text" :active="active('bold')" :aria-label="t('fields.richtext.bold')" @click="run(e => e.chain().focus().toggleBold().run())" />
                <v-btn v-if="on('italic')" icon="mdi-format-italic" size="small" variant="text" :active="active('italic')" :aria-label="t('fields.richtext.italic')" @click="run(e => e.chain().focus().toggleItalic().run())" />
                <v-btn v-if="on('underline')" icon="mdi-format-underline" size="small" variant="text" :active="active('underline')" :aria-label="t('fields.richtext.underline')" @click="run(e => e.chain().focus().toggleUnderline().run())" />
                <v-btn v-if="on('strike')" icon="mdi-format-strikethrough" size="small" variant="text" :active="active('strike')" :aria-label="t('fields.richtext.strike')" @click="run(e => e.chain().focus().toggleStrike().run())" />
                <v-btn v-if="on('code')" icon="mdi-code-tags" size="small" variant="text" :active="active('code')" :aria-label="t('fields.richtext.code')" @click="run(e => e.chain().focus().toggleCode().run())" />
                <template v-if="on('heading')">
                    <v-divider vertical class="mx-1" />
                    <v-btn v-for="level in ([1, 2, 3] as const)" :key="level" :icon="`mdi-format-header-${level}`" size="small" variant="text" :active="active('heading', { level })" :aria-label="t('fields.richtext.heading', { level })" @click="run(e => e.chain().focus().toggleHeading({ level }).run())" />
                </template>
                <template v-if="on('list')">
                    <v-divider vertical class="mx-1" />
                    <v-btn icon="mdi-format-list-bulleted" size="small" variant="text" :active="active('bulletList')" :aria-label="t('fields.richtext.bulletList')" @click="run(e => e.chain().focus().toggleBulletList().run())" />
                    <v-btn icon="mdi-format-list-numbered" size="small" variant="text" :active="active('orderedList')" :aria-label="t('fields.richtext.orderedList')" @click="run(e => e.chain().focus().toggleOrderedList().run())" />
                </template>
                <v-btn v-if="on('blockquote')" icon="mdi-format-quote-close" size="small" variant="text" :active="active('blockquote')" :aria-label="t('fields.richtext.blockquote')" @click="run(e => e.chain().focus().toggleBlockquote().run())" />
                <v-btn v-if="on('codeBlock')" icon="mdi-code-braces" size="small" variant="text" :active="active('codeBlock')" :aria-label="t('fields.richtext.codeBlock')" @click="run(e => e.chain().focus().toggleCodeBlock().run())" />
                <v-btn v-if="on('horizontalRule')" icon="mdi-minus" size="small" variant="text" :aria-label="t('fields.richtext.horizontalRule')" @click="run(e => e.chain().focus().setHorizontalRule().run())" />
                <v-menu v-if="on('link')" v-model="linkMenu" :close-on-content-click="false" location="bottom">
                    <template #activator="{ props: activator }">
                        <v-btn v-bind="activator" icon="mdi-link-variant" size="small" variant="text" :active="active('link')" :aria-label="t('fields.richtext.link')" @click="openLink" />
                    </template>
                    <v-card min-width="320" class="pa-3">
                        <v-text-field v-model="linkHref" :label="t('fields.richtext.linkUrl')" :error-messages="linkError ? [linkError] : []" autofocus @keydown.enter.prevent="applyLink" />
                        <div class="d-flex justify-end ga-2">
                            <v-btn variant="text" @click="linkHref = ''; applyLink()">{{ t('common.remove') }}</v-btn>
                            <v-btn color="primary" :disabled="!!linkError" @click="applyLink">{{ t('common.apply') }}</v-btn>
                        </div>
                    </v-card>
                </v-menu>
                <template v-if="on('table')">
                    <v-divider vertical class="mx-1" />
                    <v-btn icon="mdi-table-plus" size="small" variant="text" :aria-label="t('fields.richtext.insertTable')" @click="run(e => e.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run())" />
                    <template v-if="inTable">
                        <v-btn icon="mdi-table-row-plus-after" size="small" variant="text" :aria-label="t('fields.richtext.addRow')" @click="run(e => e.chain().focus().addRowAfter().run())" />
                        <v-btn icon="mdi-table-column-plus-after" size="small" variant="text" :aria-label="t('fields.richtext.addColumn')" @click="run(e => e.chain().focus().addColumnAfter().run())" />
                        <v-btn icon="mdi-table-row-remove" size="small" variant="text" :aria-label="t('fields.richtext.deleteRow')" @click="run(e => e.chain().focus().deleteRow().run())" />
                        <v-btn icon="mdi-table-column-remove" size="small" variant="text" :aria-label="t('fields.richtext.deleteColumn')" @click="run(e => e.chain().focus().deleteColumn().run())" />
                        <v-btn icon="mdi-table-remove" size="small" variant="text" :aria-label="t('fields.richtext.deleteTable')" @click="run(e => e.chain().focus().deleteTable().run())" />
                    </template>
                </template>
                <template v-if="on('image') && rich.imageField">
                    <v-btn icon="mdi-image-plus" size="small" variant="text" :loading="uploading" :aria-label="t('fields.richtext.image')" @click="picker?.click()" />
                    <input ref="picker" type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="d-none" @change="insertImage">
                </template>
                <v-spacer />
                <v-btn icon="mdi-undo" size="small" variant="text" :aria-label="t('fields.richtext.undo')" @click="run(e => e.chain().focus().undo().run())" />
                <v-btn icon="mdi-redo" size="small" variant="text" :aria-label="t('fields.richtext.redo')" @click="run(e => e.chain().focus().redo().run())" />
            </div>
            <v-divider />
            <EditorContent :editor="editor" class="vx-richtext-editor" />
        </div>
    </v-input>
</template>

<style>
.vx-richtext .v-input__control { display: block; }
.vx-richtext-frame { border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 4px; width: 100%; }
.vx-richtext-frame--error { border-color: rgb(var(--v-theme-error)); }
.vx-richtext-editor .vx-richtext-content { min-height: 8rem; padding: 0.75rem 1rem; outline: none; }
.vx-richtext-content p.is-editor-empty:first-child::before { content: attr(data-placeholder); float: left; height: 0; pointer-events: none; opacity: 0.5; }
.vx-richtext-content table, .vx-richtext-view table { border-collapse: collapse; margin: 0.5rem 0; }
.vx-richtext-content td, .vx-richtext-content th, .vx-richtext-view td, .vx-richtext-view th { border: 1px solid rgba(var(--v-border-color), 0.3); padding: 0.25rem 0.5rem; min-width: 4rem; vertical-align: top; }
.vx-richtext-content img, .vx-richtext-view img { max-width: 100%; height: auto; }
.vx-richtext-content blockquote, .vx-richtext-view blockquote { border-left: 3px solid rgba(var(--v-border-color), 0.4); padding-left: 0.75rem; margin: 0.5rem 0; }
.vx-richtext-content pre, .vx-richtext-view pre { background: rgba(var(--v-theme-on-surface), 0.06); padding: 0.5rem 0.75rem; border-radius: 4px; overflow-x: auto; }
.vx-richtext-content ul, .vx-richtext-content ol, .vx-richtext-view ul, .vx-richtext-view ol { padding-left: 1.5rem; }
.vx-mention { color: rgb(var(--v-theme-primary)); font-weight: 500; }
.vx-mention-menu { position: absolute; z-index: 3000; background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), 0.3); border-radius: 4px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); display: flex; flex-direction: column; min-width: 12rem; }
.vx-mention-choice { text-align: start; padding: 0.35rem 0.75rem; background: none; border: 0; cursor: pointer; }
.vx-mention-choice--active, .vx-mention-choice:hover { background: rgba(var(--v-theme-primary), 0.12); }
</style>
