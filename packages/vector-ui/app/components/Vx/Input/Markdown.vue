<script setup lang="ts">
import { EditorContent, useEditor } from '@tiptap/vue-3'
import type { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { Markdown } from '@tiptap/markdown'
import type { VectorField } from '#vector/app/types/vector'

// A Markdown field, for technical content: edited WYSIWYG, stored as the
// Markdown source (the editor reads and writes it through @tiptap/markdown).
// "Source" shows the text itself, to edit what the toolbar does not offer.
const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string }>()
const model = defineModel<string | null>()

const { t } = useVectorT()
const toolbar = computed(() => props.field.markdown?.toolbar ?? ['bold', 'italic', 'strike', 'code', 'heading', 'list', 'blockquote', 'codeBlock', 'horizontalRule', 'link'])
const on = (button: string) => toolbar.value.includes(button)
const source = ref(false)
let emitting = false

const editor = useEditor({
    extensions: [
        StarterKit.configure({
            link: { openOnClick: false, autolink: true, protocols: [], isAllowedUri: (url: string) => allowedHref(url) },
            heading: { levels: [1, 2, 3, 4] },
        }),
        Markdown,
    ],
    injectNonce: import.meta.client ? (window.__VECTOR__?.cspNonce ?? undefined) : undefined,
    editorProps: { attributes: { 'class': 'vx-richtext-content', 'aria-label': props.field.label, 'role': 'textbox', 'aria-multiline': 'true' } },
    onCreate: ({ editor: current }) => {
        current.commands.setContent(model.value ?? '', { contentType: 'markdown', emitUpdate: false })
    },
    onUpdate: ({ editor: current }) => {
        const text = current.getMarkdown().trim()
        emitting = true
        model.value = text === '' ? null : text
    },
})

watch(model, (value) => {
    if (emitting) {
        emitting = false
        return
    }
    if (!source.value) editor.value?.commands.setContent(value ?? '', { contentType: 'markdown', emitUpdate: false })
})

// leaving the source view reads the text back into the editor
watch(source, (showing) => {
    if (!showing) editor.value?.commands.setContent(model.value ?? '', { contentType: 'markdown', emitUpdate: false })
})

onBeforeUnmount(() => editor.value?.destroy())

const active = (name: string, attrs?: Record<string, unknown>) => editor.value?.isActive(name, attrs) ?? false
const run = (command: (e: Editor) => void) => {
    if (editor.value) command(editor.value)
}

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
</script>

<template>
    <v-input :model-value="model" :rules="rules" :error-messages="errorMessages" :hint="field.help ?? undefined" :persistent-hint="!!field.help" class="vx-richtext">
        <div class="vx-richtext-frame" :class="{ 'vx-richtext-frame--error': errorMessages.length > 0 }">
            <div class="text-caption text-medium-emphasis px-3 pt-2">{{ field.label }}</div>
            <div class="vx-richtext-toolbar d-flex flex-wrap align-center ga-1 px-2 py-1" role="toolbar" :aria-label="t('fields.richtext.toolbar', { label: field.label })">
                <template v-if="!source">
                    <v-btn v-if="on('bold')" icon="mdi-format-bold" size="small" variant="text" :active="active('bold')" :aria-label="t('fields.richtext.bold')" @click="run(e => e.chain().focus().toggleBold().run())" />
                    <v-btn v-if="on('italic')" icon="mdi-format-italic" size="small" variant="text" :active="active('italic')" :aria-label="t('fields.richtext.italic')" @click="run(e => e.chain().focus().toggleItalic().run())" />
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
                </template>
                <v-spacer />
                <v-btn size="small" variant="text" :prepend-icon="source ? 'mdi-eye-outline' : 'mdi-language-markdown-outline'" :aria-pressed="source" @click="source = !source">
                    {{ source ? t('fields.richtext.visual') : t('fields.richtext.source') }}
                </v-btn>
            </div>
            <v-divider />
            <v-textarea v-if="source" v-model="model" :aria-label="t('fields.richtext.sourceOf', { label: field.label })" variant="plain" auto-grow rows="6" hide-details class="px-3 font-monospace" />
            <EditorContent v-show="!source" :editor="editor" class="vx-richtext-editor" />
        </div>
    </v-input>
</template>
