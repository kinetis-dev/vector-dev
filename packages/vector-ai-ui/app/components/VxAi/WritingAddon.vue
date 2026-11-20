<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'
import type { WritingAnswer, WritingOperation } from '../../composables/useVectorWriting'
import WritingMenu from './WritingMenu.vue'
import WritingResult from './WritingResult.vue'

// Writing help under a text input (defineInputAddon, `string` and
// `text`): the menu, then the answer beside the text it was asked about,
// with Replace — which puts it in the input, for the person to review and
// save as their own edit — and Discard. A failure keeps the text as it
// was and says why. Shown only where writing help is offered for this
// field (useVectorWriting().offered()), and only on that resource's own
// create or edit page: the record comes from the page, as it does for the
// editor toolbar item, so a settings form, a bulk edit and an action's
// dialog — forms the addon is also mounted under — show nothing.
const props = defineProps<{
    field: VectorField
    /** The resource's slug; empty in a settings group. */
    resource: string
    record: Record<string, unknown> | null
    modelValue: unknown
    disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const writing = useVectorWriting()
const host = useVectorHost()
const { t } = useVectorT()

const context = host.context()
// This resource's create page (/r/{resource}/create) or one of its records'
// edit pages (/r/{resource}/{id}/edit), and nothing else: not its list
// (bulk edit), not a record's page (its actions' dialogs, a related
// record's create form), not another resource's page.
const page = context.path.split('/')
const onForm = props.resource !== '' && context.resource === props.resource && page[1] === 'r' && (
    context.recordId === undefined
        ? page.length === 4 && page[3] === 'create'
        : page.length === 5 && page[4] === 'edit'
)
const shown = computed(() => onForm && writing.offered(props.resource, props.field.name))
const text = computed(() => typeof props.modelValue === 'string' ? props.modelValue : '')
const busy = ref(false)
const failure = ref<string | null>(null)
const result = ref<{ before: string, answer: WritingAnswer } | null>(null)

async function run(operation: WritingOperation, targetLocale: string | null) {
    const before = text.value
    if (before.trim() === '' || busy.value) return
    busy.value = true
    failure.value = null
    result.value = null

    try {
        const answer = await writing.write(props.resource, props.field.name, { operation, text: before, format: 'plain', targetLocale, recordId: context.recordId ?? null })
        result.value = { before, answer }
    } catch (error) {
        // the input keeps its text: nothing was put in it
        failure.value = writing.describe(error)
    } finally {
        busy.value = false
    }
}

function replace() {
    if (result.value === null) return
    emit('update:modelValue', result.value.answer.text)
    result.value = null
}

function discard() {
    result.value = null
}
</script>

<template>
    <div v-if="shown" class="vx-ai-writing w-100" data-ai-writing>
        <WritingMenu :disabled="props.disabled || text.trim() === ''" :busy="busy" @run="run" />
        <span v-if="failure" class="text-caption text-error ml-2" role="alert" data-ai-writing-failure>{{ failure }}</span>
        <WritingResult
            v-if="result"
            class="mt-1"
            :before="result.before"
            :after="result.answer.text"
            format="plain"
            :truncated="result.answer.truncated"
            @replace="replace"
            @discard="discard"
        />
        <span class="d-sr-only" aria-live="polite">{{ busy ? t('ai.writing.busy') : '' }}</span>
    </div>
</template>
