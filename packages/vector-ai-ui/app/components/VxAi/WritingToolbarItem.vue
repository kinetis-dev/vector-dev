<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'
import type { WritingAnswer, WritingOperation } from '../../composables/useVectorWriting'
import WritingMenu from './WritingMenu.vue'
import WritingResult from './WritingResult.vue'

// Writing help in the toolbar of a rich-text or Markdown editor
// (defineEditorToolbarItem): the same menu, acting on the editor's
// selection — its plain text in a rich-text field, its Markdown source in
// a Markdown field — and disabled while nothing is selected. The answer
// shows beside the selection it was asked about; Replace puts it in
// place of that selection as text (paragraphs for its lines), so the
// document keeps to the field's own schema — a Markdown answer is parsed
// by the editor's own Markdown reader — and the person saves it as their
// own edit. The resource and the record come from the page the editor is
// on, so it is shown only on that resource's own create or edit page, for
// one of that resource's own fields — as the input addon is: an editor is
// also mounted in a bulk edit, an action's dialog and a relation's inline
// create, where the page names no record of this field's resource — and
// only where writing help is offered for this field.

/** The part of a Tiptap editor this item uses; the layer does not depend on Tiptap itself. */
interface Chain {
    focus: () => Chain
    insertContentAt: (range: { from: number, to: number }, content: unknown) => Chain
    run: () => boolean
}
interface WritingEditor {
    state: {
        selection: {
            from: number
            to: number
            empty: boolean
            $from: { parent: { isTextblock: boolean } }
            $to: { parent: { isTextblock: boolean } }
            content: () => { content: { toJSON: () => unknown } }
        }
        doc: { textBetween: (from: number, to: number, blockSeparator?: string) => string }
    }
    schema: { nodes: Record<string, unknown> }
    markdown?: { serialize: (content: unknown) => string, parse: (markdown: string) => { content?: unknown[] } }
    on: (event: 'transaction', handler: () => void) => unknown
    off: (event: 'transaction', handler: () => void) => unknown
    chain: () => Chain
}

const props = defineProps<{ editor: WritingEditor, field: VectorField }>()

const writing = useVectorWriting()
const host = useVectorHost()
const { t } = useVectorT()

const markdown = computed(() => props.field.type === 'markdown')
const context = host.context()
// This resource's create page (/r/{resource}/create) or one of its records'
// edit pages (/r/{resource}/{id}/edit), and a field that resource has: not
// its list (bulk edit), not a record's page (its actions' dialogs, a
// related record's create form), not another resource's page.
const page = context.path.split('/')
const ownField = context.resource !== undefined
    && host.schema.value?.resources?.[context.resource]?.fields.some(f => f.name === props.field.name) === true
const onForm = ownField && page[1] === 'r' && (
    context.recordId === undefined
        ? page.length === 4 && page[3] === 'create'
        : page.length === 5 && page[4] === 'edit'
)
const shown = computed(() => onForm && (props.field.type === 'richtext' || markdown.value) && writing.offered(context.resource, props.field.name))

// the editor's state is no Vue state: its transactions say when the selection moved
const empty = ref(props.editor.state.selection.empty)
const track = () => {
    empty.value = props.editor.state.selection.empty
}
onMounted(() => props.editor.on('transaction', track))
onBeforeUnmount(() => props.editor.off('transaction', track))

const busy = ref(false)
const failure = ref<string | null>(null)
const result = ref<{ from: number, to: number, inline: boolean, plain: string, before: string, answer: WritingAnswer } | null>(null)

/** The selection as text to send: its Markdown source in a Markdown field, its plain text otherwise. */
function selected(from: number, to: number): string {
    const { state } = props.editor
    if (markdown.value && props.editor.markdown) {
        return props.editor.markdown.serialize({ type: 'doc', content: state.selection.content().content.toJSON() }).trim()
    }
    return state.doc.textBetween(from, to, '\n')
}

async function run(operation: WritingOperation, targetLocale: string | null) {
    const { from, to, empty: nothing, $from, $to } = props.editor.state.selection
    if (nothing || busy.value || context.resource === undefined) return
    const before = selected(from, to)
    if (before.trim() === '') return
    busy.value = true
    failure.value = null
    result.value = null

    try {
        const answer = await writing.write(context.resource, props.field.name, {
            operation,
            text: before,
            format: markdown.value ? 'markdown' : 'plain',
            targetLocale,
            recordId: context.recordId ?? null,
        })
        const plain = props.editor.state.doc.textBetween(from, to, '\n')
        // within one line of text a single line goes in as text; anything wider as paragraphs
        result.value = { from, to, inline: $from.parent.isTextblock && $to.parent.isTextblock && !plain.includes('\n'), plain, before, answer }
    } catch (error) {
        // the document keeps its text: nothing was put in it
        failure.value = writing.describe(error)
    } finally {
        busy.value = false
    }
}

/** The answer as content for this editor: parsed Markdown, or text — a paragraph per line where the schema has paragraphs. */
function content(text: string, inline: boolean): unknown {
    if (markdown.value && props.editor.markdown) return props.editor.markdown.parse(text).content ?? []
    const lines = text.split('\n')
    if ((inline && lines.length === 1) || props.editor.schema.nodes.paragraph === undefined) return { type: 'text', text: lines.join(' ') }
    return lines.map(line => line === '' ? { type: 'paragraph' } : { type: 'paragraph', content: [{ type: 'text', text: line }] })
}

function replace() {
    const current = result.value
    if (current === null) return
    // the text it was asked about must still be there, where it was
    if (props.editor.state.doc.textBetween(current.from, current.to, '\n') !== current.plain) {
        failure.value = t('ai.writing.changed')
        result.value = null
        return
    }
    props.editor.chain().focus().insertContentAt({ from: current.from, to: current.to }, content(current.answer.text, current.inline)).run()
    result.value = null
}

function discard() {
    result.value = null
}

const dialog = computed({
    get: () => result.value !== null,
    set: (open: boolean) => {
        if (!open) discard()
    },
})
</script>

<template>
    <span v-if="shown" class="vx-ai-writing-toolbar d-inline-flex align-center" data-ai-writing>
        <WritingMenu icon :disabled="empty" :busy="busy" :hint="empty ? t('ai.writing.selectFirst') : undefined" @run="run" />
        <v-tooltip v-if="failure" :text="failure" location="bottom">
            <template #activator="{ props: activator }">
                <v-icon v-bind="activator" icon="mdi-alert-circle-outline" color="error" size="small" role="alert" :aria-label="failure" data-ai-writing-failure />
            </template>
        </v-tooltip>
        <v-dialog v-model="dialog" max-width="720">
            <WritingResult
                v-if="result"
                :before="result.before"
                :after="result.answer.text"
                :format="markdown ? 'markdown' : 'plain'"
                :truncated="result.answer.truncated"
                @replace="replace"
                @discard="discard"
            />
        </v-dialog>
    </span>
</template>
