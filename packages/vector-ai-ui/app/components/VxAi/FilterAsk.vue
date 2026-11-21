<script setup lang="ts">
import type { VectorFilterGroup, VectorFilterQuery, VectorFilters, VectorOperator, VectorResource } from '#vector/app/types/vector'

// Plain-language filters: a list-toolbar item beside the search. The
// sentence goes to POST /vector/ai/filters/{resource}; the answer — the
// list's own grammar, validated by the server — reaches the list at once,
// through its update:filters, update:groups and update:search, and a note
// below says what it understood and offers Undo, which puts back the
// list's filter and search as they were before. A relation the sentence
// named ambiguously shows its candidates in the note; the one picked joins
// the list's filter. Shown only when capabilities.ai.filters is on, the
// resource is not kept from AI, and it has a field to filter by.

interface Candidate { id: number | string, label: string }
interface Unresolved { field: string, op: VectorOperator, text: string, candidates: Candidate[] }
interface Translation {
    filter: VectorFilters
    groups: VectorFilterGroup[]
    q: string | null
    explanation: string
    unresolved: Unresolved[]
}
interface Before { filters: VectorFilters, groups: VectorFilterGroup[], search: string | null, searched: boolean }

const props = defineProps<{
    resource: VectorResource
    filters: VectorFilters
    groups: VectorFilterGroup[]
    advanced?: VectorFilterQuery[]
    search: string | null
}>()

const emit = defineEmits<{
    'update:filters': [VectorFilters]
    'update:groups': [VectorFilterGroup[]]
    'update:search': [string | null]
}>()

const { schema } = useVectorSchema()
const api = useVectorApi()
const { t } = useVectorT()

const shown = computed(() => aiOffered(aiCapabilities(schema.value), 'filters', props.resource.slug)
    && props.resource.fields.some(f => f.operators.length > 0))

const text = ref('')
const busy = ref(false)
const failure = ref<string | null>(null)
const note = ref<{ explanation: string, unresolved: Unresolved[] } | null>(null)
const before = ref<Before | null>(null)

/** The list's filter as the server reads it: the conditions, and its groups as the list page sends them. */
function current(): VectorFilterQuery {
    const all: VectorFilterQuery = { ...props.filters }
    const groups = props.groups.filter(g => g.length > 0).map(g => ({ or: g }))
    if (groups.length === 1) all.or = groups[0]!.or
    else if (groups.length > 1) all.and = groups
    return all
}

function zone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone
    } catch {
        return 'UTC'
    }
}

async function ask() {
    const sentence = text.value.trim()
    if (sentence === '' || busy.value) return
    busy.value = true
    failure.value = null

    try {
        const answer = await api<Translation>(`/vector/ai/filters/${encodeURIComponent(props.resource.slug)}`, {
            method: 'POST',
            body: { text: sentence, timezone: zone(), current: { filter: current() } },
        })
        apply(answer)
    } catch (error) {
        // the sentence stays, to be corrected or sent again
        failure.value = describe(error)
    } finally {
        busy.value = false
    }
}

/**
 * The answer reaches the list at once. The list as it was is kept for
 * Undo — from before the first of several sentences in a row, so Undo
 * goes back to the list the admin started from. The request carries no
 * search, so a null q leaves the list's own search as it is. The sentence
 * stays in the field, to be refined and sent again.
 */
function apply(answer: Translation) {
    const searched = answer.q !== null
    before.value ??= { filters: { ...props.filters }, groups: props.groups.map(group => group.map(alternative => ({ ...alternative }))), search: props.search, searched: false }
    if (searched) before.value.searched = true
    emit('update:filters', Array.isArray(answer.filter) ? {} : { ...answer.filter })
    emit('update:groups', answer.groups.map(group => group.map(alternative => ({ ...alternative }))))
    if (searched) emit('update:search', answer.q)
    note.value = { explanation: answer.explanation, unresolved: answer.unresolved }
}

/**
 * The server's reason; for a spent budget, when it renews — when the
 * response lets Retry-After be read. Cross-origin it can only be read when
 * the API's CORS exposes it; without it, the server's own words.
 */
function describe(error: unknown): string {
    return aiStatusOf(error) === 429 ? aiFailureMessage(error, t('ai.filters.budgetSpent')) : vectorErrorMessage(error)
}

/** A candidate of an ambiguous relation joins the list's filter as its condition. */
function pick(entry: Unresolved, candidate: Candidate) {
    emit('update:filters', { ...props.filters, [entry.field]: { ...props.filters[entry.field], [entry.op]: String(candidate.id) } })
    if (note.value) note.value = { ...note.value, unresolved: note.value.unresolved.filter(u => u !== entry) }
}

const fieldLabel = (name: string) => props.resource.fields.find(f => f.name === name)?.label ?? name

/** Puts the list's filter and search back as they were before the sentence. */
function undo() {
    if (before.value) {
        emit('update:filters', before.value.filters)
        emit('update:groups', before.value.groups)
        if (before.value.searched) emit('update:search', before.value.search)
    }
    close()
}

/** Keeps the list as it is; the next sentence starts from it. */
function close() {
    note.value = null
    before.value = null
}
</script>

<template>
    <div v-if="shown" class="vx-ai-filter d-contents" data-ai-filters>
        <v-text-field
            v-model="text"
            :placeholder="t('ai.filters.placeholder', { label: resource.label })"
            :aria-label="t('ai.filters.label')"
            prepend-inner-icon="mdi-creation-outline"
            :loading="busy"
            :disabled="busy"
            :maxlength="500"
            density="compact"
            hide-details
            style="max-width: 360px; min-width: 240px"
            data-ai-filters-input
            @keydown.enter.prevent="ask"
        />
        <v-alert v-if="failure" type="warning" variant="tonal" density="compact" class="vx-ai-filter__row" :text="failure" closable data-ai-filters-failure @click:close="failure = null" />
        <v-sheet v-if="note" border rounded class="vx-ai-filter__row px-3 py-2" data-ai-filters-note>
            <div class="d-flex align-center ga-2">
                <v-icon icon="mdi-creation-outline" size="small" />
                <span class="text-body-2 flex-grow-1" data-ai-filters-explanation>{{ note.explanation || t('ai.filters.noExplanation') }}</span>
                <v-btn size="small" variant="text" prepend-icon="mdi-undo" data-ai-filters-undo @click="undo">{{ t('ai.filters.undo') }}</v-btn>
                <v-btn size="small" variant="text" icon="mdi-close" :aria-label="t('ai.filters.close')" data-ai-filters-close @click="close" />
            </div>
            <div v-for="entry in note.unresolved" :key="`${entry.field}.${entry.op}`" class="d-flex align-center flex-wrap ga-1 mt-2" :data-ai-filters-unresolved="entry.field">
                <v-chip size="small" color="warning" variant="tonal" prepend-icon="mdi-help-circle-outline">{{ t('ai.filters.which', { field: fieldLabel(entry.field), text: entry.text }) }}</v-chip>
                <v-chip v-for="candidate in entry.candidates" :key="candidate.id" size="small" variant="outlined" :data-ai-filters-candidate="candidate.id" @click="pick(entry, candidate)">{{ candidate.label }}</v-chip>
            </div>
        </v-sheet>
    </div>
</template>

<style scoped>
/* the field sits in the toolbar's own row; the note and a failure take a row of their own below it */
.d-contents { display: contents; }
.vx-ai-filter__row { flex-basis: 100%; }
</style>
