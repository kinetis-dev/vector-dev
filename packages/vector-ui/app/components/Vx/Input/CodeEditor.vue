<script setup lang="ts">
import { Compartment, EditorState } from '@codemirror/state'
import { EditorView, drawSelection, highlightActiveLine, keymap, lineNumbers as gutterNumbers, placeholder as hint } from '@codemirror/view'
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands'
import { bracketMatching, indentOnInput, syntaxHighlighting } from '@codemirror/language'
import { classHighlighter } from '@lezer/highlight'
import { loadLanguage } from '#vector/app/utils/internal/codeLanguages'

// The code editor behind the Code and Json inputs and a Code field's
// record view. It is imported by those components only, each loaded on
// demand, so the editor stays out of the base bundle; a language's
// highlighting loads the first time it is shown. Tab is left to the
// page, so the keyboard moves through the form as it does elsewhere.
// Code reads left to right whatever the page's direction.
const props = withDefaults(defineProps<{
    modelValue: string
    language?: string
    lineNumbers?: boolean
    rows?: number
    readonly?: boolean
    disabled?: boolean
    placeholder?: string
    /** The ids of the element naming the editor and of the one describing it (its messages). */
    labelledBy?: string
    describedBy?: string
}>(), { language: 'plain', lineNumbers: true, rows: 10, readonly: false, disabled: false, placeholder: undefined, labelledBy: undefined, describedBy: undefined })

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const host = ref<HTMLElement | null>(null)
let view: EditorView | null = null
const highlighting = new Compartment()
const editable = new Compartment()

const editableState = () => [EditorView.editable.of(!props.disabled), EditorState.readOnly.of(props.readonly || props.disabled)]

const nonce = import.meta.client ? (window.__VECTOR__?.cspNonce ?? undefined) : undefined

onMounted(async () => {
    const attributes: Record<string, string> = { 'aria-multiline': 'true', 'spellcheck': 'false' }
    if (props.labelledBy) attributes['aria-labelledby'] = props.labelledBy
    if (props.describedBy) attributes['aria-describedby'] = props.describedBy

    view = new EditorView({
        parent: host.value!,
        state: EditorState.create({
            doc: props.modelValue,
            extensions: [
                ...(props.lineNumbers ? [gutterNumbers()] : []),
                history(),
                drawSelection(),
                highlightActiveLine(),
                indentOnInput(),
                bracketMatching(),
                syntaxHighlighting(classHighlighter),
                keymap.of([...defaultKeymap, ...historyKeymap]),
                ...(props.placeholder ? [hint(props.placeholder)] : []),
                EditorView.contentAttributes.of(attributes),
                // text typed over a selection is applied by the editor itself: Chromium's own edit of a
                // selection that spans lines leaves an empty line behind, and sets a style attribute a strict CSP refuses
                EditorView.domEventHandlers({
                    beforeinput(event, current) {
                        if (event.inputType !== 'insertText' || !event.data || current.state.selection.main.empty || current.state.readOnly) return false
                        current.dispatch({ ...current.state.replaceSelection(event.data), scrollIntoView: true, userEvent: 'input.type' })
                        event.preventDefault()
                        return true
                    },
                }),
                // the editor's own style element carries the hosted shell's nonce, so a strict style-src admits it
                ...(nonce ? [EditorView.cspNonce.of(nonce)] : []),
                EditorView.theme({ '.cm-scroller': { minHeight: `${props.rows * 1.45}em`, maxHeight: `${props.rows * 1.45}em` } }),
                highlighting.of([]),
                editable.of(editableState()),
                EditorView.updateListener.of((update) => {
                    if (update.docChanged) emit('update:modelValue', update.state.doc.toString())
                }),
            ],
        }),
    })

    const loaded = await loadLanguage(props.language)
    view?.dispatch({ effects: highlighting.reconfigure(loaded) })
})

// a value set from outside (a reset, a loaded record) replaces the text
watch(() => props.modelValue, (value) => {
    if (view && value !== view.state.doc.toString()) {
        view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value } })
    }
})

watch(() => [props.readonly, props.disabled], () => {
    view?.dispatch({ effects: editable.reconfigure(editableState()) })
})

// the label in the frame around the editor focuses it, as a label focuses its input
defineExpose({ focus: () => view?.focus() })

onBeforeUnmount(() => {
    view?.destroy()
    view = null
})
</script>

<template>
    <div ref="host" class="vx-code" :class="{ 'vx-code--disabled': disabled }" dir="ltr" />
</template>

<style>
.vx-code .cm-editor { background: transparent; color: rgb(var(--v-theme-on-surface)); font-size: 0.875rem; }
.vx-code .cm-editor.cm-focused { outline: none; }
.vx-code .cm-scroller { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; line-height: 1.45; overflow: auto; }
.vx-code .cm-content { caret-color: rgb(var(--v-theme-primary)); padding: 0.5rem 0; }
.vx-code .cm-gutters { background: rgba(var(--v-theme-on-surface), 0.04); color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); border-inline-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.vx-code .cm-activeLine { background: rgba(var(--v-theme-on-surface), 0.04); }
.vx-code .cm-activeLineGutter { background: rgba(var(--v-theme-on-surface), 0.08); }
.vx-code .cm-selectionBackground, .vx-code .cm-focused .cm-selectionBackground { background: rgba(var(--v-theme-primary), 0.25) !important; }
.vx-code .cm-cursor { border-inline-start-color: rgb(var(--v-theme-primary)); }
.vx-code .cm-placeholder { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.vx-code .cm-matchingBracket { background: rgba(var(--v-theme-primary), 0.18); outline: none; }
.vx-code--disabled { opacity: var(--v-disabled-opacity); }
/* the frame the Code and Json inputs draw around the editor, with their label */
.vx-code-input .v-input__control { display: block; }
.vx-code-frame { border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 4px; width: 100%; overflow: hidden; }
.vx-code-frame:focus-within { border-color: rgb(var(--v-theme-primary)); }
.vx-code-frame--error, .vx-code-frame--error:focus-within { border-color: rgb(var(--v-theme-error)); }
/* the highlighting, by token class, in the light theme and the dark one */
.vx-code .tok-keyword, .vx-code .tok-operator.tok-definition { color: #7c3aed; }
.vx-code .tok-string, .vx-code .tok-inserted { color: #15803d; }
.vx-code .tok-number, .vx-code .tok-bool, .vx-code .tok-atom { color: #b45309; }
.vx-code .tok-comment, .vx-code .tok-meta { color: #6b7280; font-style: italic; }
.vx-code .tok-propertyName { color: #0369a1; }
.vx-code .tok-typeName, .vx-code .tok-className, .vx-code .tok-namespace { color: #be123c; }
.vx-code .tok-variableName.tok-definition, .vx-code .tok-labelName, .vx-code .tok-macroName { color: #1d4ed8; }
.vx-code .tok-heading, .vx-code .tok-strong { font-weight: 600; }
.vx-code .tok-emphasis { font-style: italic; }
.vx-code .tok-link, .vx-code .tok-url { text-decoration: underline; }
.vx-code .tok-invalid, .vx-code .tok-deleted { color: #dc2626; }
.v-theme--dark .vx-code .tok-keyword, .v-theme--dark .vx-code .tok-operator.tok-definition { color: #c4b5fd; }
.v-theme--dark .vx-code .tok-string, .v-theme--dark .vx-code .tok-inserted { color: #86efac; }
.v-theme--dark .vx-code .tok-number, .v-theme--dark .vx-code .tok-bool, .v-theme--dark .vx-code .tok-atom { color: #fcd34d; }
.v-theme--dark .vx-code .tok-comment, .v-theme--dark .vx-code .tok-meta { color: #9ca3af; }
.v-theme--dark .vx-code .tok-propertyName { color: #7dd3fc; }
.v-theme--dark .vx-code .tok-typeName, .v-theme--dark .vx-code .tok-className, .v-theme--dark .vx-code .tok-namespace { color: #fda4af; }
.v-theme--dark .vx-code .tok-variableName.tok-definition, .v-theme--dark .vx-code .tok-labelName, .v-theme--dark .vx-code .tok-macroName { color: #93c5fd; }
.v-theme--dark .vx-code .tok-invalid, .v-theme--dark .vx-code .tok-deleted { color: #f87171; }
</style>
