<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// Rows edited inside the record's form: one line of inputs per row, added
// and removed within the repeater's bounds, and dragged by their handle
// (or moved with the arrow keys on it) when the repeater is sortable. A
// JSON repeater's rows are objects of its own row fields; a relationship
// repeater's are child records, each keeping its `id`, so the server
// updates that child, and a row without one is created. The form sends
// the whole list, in its order.
const props = defineProps<{
    field: VectorField
    /** the form's rules for the field itself; a row's inputs carry their own */
    rules?: unknown[]
    errorMessages: string[]
    resource: string
    record?: Record<string, unknown>
    /** the form's server errors, keyed `field.row.column` for a row's own */
    serverErrors?: Record<string, string[]>
    disabled?: boolean
}>()
const model = defineModel<Record<string, unknown>[] | null>()

const { t, noun } = useVectorT()
const { resource: resourceOf } = useVectorSchema()
const { inputFor } = useVectorInputs()

const json = computed(() => props.field.repeater?.mode !== 'relationship')
const child = computed(() => !json.value && props.field.relation ? resourceOf(props.field.relation.resource) : undefined)
const available = computed(() => json.value || child.value !== undefined)
const columns = computed<VectorField[]>(() => json.value
    ? props.field.repeater?.rowFields ?? []
    : (props.field.repeater?.fields ?? [])
            .map(name => child.value?.fields.find(f => f.name === name))
            .filter((f): f is VectorField => f !== undefined))
const min = computed(() => props.field.repeater?.min ?? 0)
const max = computed(() => props.field.repeater?.max ?? 100)
const sortable = computed(() => props.field.repeater?.sortable === true && !props.disabled)
const rows = computed(() => (Array.isArray(model.value) ? model.value : []))
const width = computed(() => Math.max(3, Math.floor(12 / Math.max(1, Math.min(columns.value.length, 4)))))
// a row's inputs ask for options and uploads as the resource they belong to
const rowResource = computed(() => child.value?.slug ?? props.resource)

const rowLabel = (index: number) => child.value
    ? t('fields.repeater.row', { label: child.value.labelSingular, n: index + 1 })
    : t('fields.rows.row', { n: index + 1 })
const removeLabel = (index: number) => child.value
    ? t('fields.repeater.remove', { label: noun(child.value.labelSingular), n: index + 1 })
    : t('fields.rows.remove', { n: index + 1 })
const addLabel = computed(() => child.value ? t('fields.repeater.add', { label: noun(child.value.labelSingular) }) : t('fields.rows.add'))

// Stable keys, so removing or moving a row keeps every other row's inputs
// (and a relation input's loaded label) with the row it belongs to.
let next = 0
const keys = ref<number[]>([])
watch(() => rows.value.length, (length) => {
    if (keys.value.length !== length) keys.value = Array.from({ length }, () => next++)
}, { immediate: true })

/** A new row's value for a column: its default, else unchecked for a checkbox, else empty. */
function blankValue(f: VectorField): unknown {
    if (f.hasDefault) return f.default
    if (f.type === 'tag') return []
    return f.type === 'boolean' ? false : null
}

function blank(): Record<string, unknown> {
    return Object.fromEntries(columns.value.map(f => [f.name, blankValue(f)]))
}

function add() {
    keys.value = [...keys.value, next++]
    model.value = [...rows.value, blank()]
}

function remove(index: number) {
    keys.value = keys.value.filter((_, i) => i !== index)
    model.value = rows.value.filter((_, i) => i !== index)
    clearRowErrors()
}

function set(index: number, name: string, value: unknown) {
    model.value = rows.value.map((row, i) => (i === index ? { ...row, [name]: value } : row))
    // The form owns the server errors and lets each input clear its own.
    // eslint-disable-next-line vue/no-mutating-props
    if (props.serverErrors) delete props.serverErrors[`${props.field.name}.${index}.${name}`]
}

/** Row errors are keyed by position, which a removal or a move shifts: they go with it. */
function clearRowErrors() {
    for (const key of Object.keys(props.serverErrors ?? {})) {
        if (key.startsWith(`${props.field.name}.`)) delete props.serverErrors![key]
    }
}

// --- reordering ----------------------------------------------------------

/** What a screen reader hears after a move. */
const announcement = ref('')
const handles = ref<HTMLElement[]>([])

/** Moves the row at `from` to `to`, its key and inputs with it. */
function move(from: number, to: number) {
    if (from === to || to < 0 || to >= rows.value.length) return

    const reorder = <T,>(list: T[]) => {
        const copy = [...list]
        const [moved] = copy.splice(from, 1)
        copy.splice(to, 0, moved as T)
        return copy
    }

    keys.value = reorder(keys.value)
    model.value = reorder(rows.value)
    clearRowErrors()
    announcement.value = t('fields.rows.moved', { from: from + 1, to: to + 1 })
}

/** The arrow keys on a row's handle move the row, the handle keeping focus. */
async function onHandleKey(event: KeyboardEvent, index: number) {
    const to = event.key === 'ArrowUp' ? index - 1 : event.key === 'ArrowDown' ? index + 1 : null
    if (to === null || to < 0 || to >= rows.value.length) return

    event.preventDefault()
    move(index, to)
    await nextTick()
    handles.value[to]?.focus()
}

const dragging = ref<number | null>(null)
const over = ref<number | null>(null)

function onDragStart(event: DragEvent, index: number) {
    dragging.value = index
    event.dataTransfer?.setData('text/plain', String(index))
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function onDrop(index: number) {
    if (dragging.value !== null) move(dragging.value, index)
    dragging.value = null
    over.value = null
}

// A relationship row's inputs read the record they belong to through the
// relation back to it: a line priced in `order.currency` sees the order
// being edited. A JSON row's inputs read the row.
const inverse = computed(() => props.field.relation?.inverse)
const rowRecord = (row: Record<string, unknown>) => (inverse.value ? { ...row, [inverse.value]: props.record ?? {} } : row)

const rowErrors = (index: number, name: string) => props.serverErrors?.[`${props.field.name}.${index}.${name}`] ?? []
/**
 * A row's errors no input of it shows: the row's own (`specs.2`, a row
 * that is not an object) and a key the row may not hold (`specs.2.colour`).
 */
function rowOwnErrors(index: number): string[] {
    const prefix = `${props.field.name}.${index}`

    return Object.entries(props.serverErrors ?? {}).flatMap(([key, messages]) => {
        if (key === prefix) return messages
        if (!key.startsWith(`${prefix}.`)) return []

        const column = key.slice(prefix.length + 1)
        return columns.value.some(c => c.name === column) ? [] : messages.map(message => `${column}: ${message}`)
    })
}

// A new record starts with the rows it must hold.
onMounted(() => {
    if (available.value && rows.value.length < min.value && !props.record?.id) {
        model.value = [...rows.value, ...Array.from({ length: min.value - rows.value.length }, blank)]
    }
})
</script>

<template>
    <v-card variant="outlined" class="pa-3" role="group" :aria-label="field.label">
        <div class="d-flex align-center mb-2">
            <span class="text-subtitle-2">{{ field.label }}</span>
            <v-spacer />
            <span class="text-caption text-medium-emphasis">{{ t('fields.repeater.count', { count: rows.length, max }) }}</span>
        </div>
        <v-alert v-for="message in errorMessages" :key="message" type="error" variant="tonal" density="compact" class="mb-2" :text="message" />
        <p v-if="!available" class="text-medium-emphasis text-body-2">{{ t('fields.repeater.unavailable') }}</p>
        <template v-else>
            <fieldset
                v-for="(row, index) in rows"
                :key="keys[index]"
                class="vx-fieldset vx-repeater-row d-flex ga-2 align-start mb-1"
                :class="{ 'vx-repeater-row--over': over === index && dragging !== index }"
                :aria-label="rowLabel(index)"
                @dragover.prevent="over = index"
                @dragleave="over = over === index ? null : over"
                @drop.prevent="onDrop(index)"
            >
                <v-btn
                    v-if="sortable"
                    :ref="(el: unknown) => { const node = (el as { $el?: HTMLElement } | null)?.$el; if (node) handles[index] = node }"
                    icon="mdi-drag-vertical"
                    variant="text"
                    size="small"
                    class="mt-2 vx-repeater-handle"
                    draggable="true"
                    :aria-label="t('fields.rows.drag', { n: index + 1 })"
                    @dragstart="onDragStart($event, index)"
                    @dragend="dragging = null; over = null"
                    @keydown="onHandleKey($event, index)"
                />
                <div class="flex-grow-1">
                    <v-alert v-for="message in rowOwnErrors(index)" :key="message" type="error" variant="tonal" density="compact" class="mb-1" :text="message" />
                    <v-row density="compact">
                        <v-col v-for="column in columns" :key="column.name" cols="12" :md="width">
                            <component
                                :is="inputFor(column)"
                                :model-value="row[column.name]"
                                :field="column"
                                :rules="rulesFor(column, row.id ? 'update' : 'create')"
                                :error-messages="rowErrors(index, column.name)"
                                :record="rowRecord(row)"
                                :resource="rowResource"
                                :disabled="disabled"
                                density="compact"
                                @update:model-value="(value: unknown) => set(index, column.name, value)"
                            />
                        </v-col>
                    </v-row>
                </div>
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    size="small"
                    class="mt-2"
                    :disabled="disabled || rows.length <= min"
                    :aria-label="removeLabel(index)"
                    @click="remove(index)"
                />
            </fieldset>
            <v-btn variant="tonal" size="small" prepend-icon="mdi-plus" :disabled="disabled || rows.length >= max" @click="add">
                {{ addLabel }}
            </v-btn>
            <span class="d-sr-only" aria-live="polite">{{ announcement }}</span>
        </template>
    </v-card>
</template>

<style scoped>
/* a fieldset that groups without a fieldset's own border, padding and margin */
.vx-fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
.vx-repeater-handle { cursor: grab; }
.vx-repeater-row--over { outline: 2px dashed rgb(var(--v-theme-primary)); outline-offset: 2px; border-radius: 4px; }
</style>
