<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'
import type { WritingAnswer, WritingOperation } from '../../composables/useVectorWriting'
import WritingMenu from './WritingMenu.vue'
import WritingResult from './WritingResult.vue'

// Writing help inside a text input (defineInputAddon, `string` and
// `text`, placement `inner`): an icon at the field's inline end opens the
// menu; the answer opens in a popover anchored to the field, beside the
// text it was asked about, with Replace (which puts it in the input, for
// the person to review and save as their own edit) and Discard; Escape
// or a click outside it discards too. A failure keeps the text as it was
// and says why, in the same popover. Shown only where writing help is
// offered for this field (useVectorWriting().offered()), and only on that
// resource's own create or edit page: the record comes from the page, as
// it does for the editor toolbar item, so a settings form, a bulk edit and
// an action's dialog (forms the addon is also mounted in) show nothing.
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
    failure.value = null
}

// the popover hangs from the field the addon sits in (from the addon itself outside one), as wide as
// the field up to 480px; it is open while there is an answer or a failure to show
const root = ref<HTMLElement | null>(null)
const anchor = ref<HTMLElement | null>(null)
const width = ref(0)
const popover = computed({
    get: () => result.value !== null || failure.value !== null,
    set: (opened: boolean) => {
        if (!opened) discard()
    },
})

watch(root, (element) => {
    anchor.value = element?.closest<HTMLElement>('.v-input') ?? element
})
watch(popover, (opened) => {
    if (opened) width.value = Math.min(anchor.value?.getBoundingClientRect().width ?? 0, 480)
})
</script>

<template>
    <div v-if="shown" ref="root" class="vx-ai-writing d-inline-flex align-center" data-ai-writing>
        <WritingMenu icon :disabled="props.disabled || text.trim() === ''" :busy="busy" @run="run" />
        <v-menu
            v-if="anchor"
            v-model="popover"
            :activator="anchor"
            :open-on-click="false"
            :close-on-content-click="false"
            location="bottom end"
            :min-width="width || undefined"
            max-width="480"
        >
            <v-card class="pa-2" data-ai-writing-popover>
                <div v-if="failure" class="text-body-2 text-error pa-1" role="alert" data-ai-writing-failure>{{ failure }}</div>
                <WritingResult
                    v-if="result"
                    :before="result.before"
                    :after="result.answer.text"
                    format="plain"
                    :truncated="result.answer.truncated"
                    @replace="replace"
                    @discard="discard"
                />
            </v-card>
        </v-menu>
        <span class="d-sr-only" aria-live="polite">{{ busy ? t('ai.writing.busy') : '' }}</span>
    </div>
</template>
