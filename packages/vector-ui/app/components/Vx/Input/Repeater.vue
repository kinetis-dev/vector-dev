<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// An owned relation's rows edited inside the parent's form: one line of
// the child's inputs per row, added and removed within the repeater's
// bounds. The form sends the whole list; a row keeps its `id`, so the
// server updates that child, and a row without one is created.
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

const child = computed(() => props.field.relation ? resourceOf(props.field.relation.resource) : undefined)
const columns = computed<VectorField[]>(() => (props.field.repeater?.fields ?? [])
    .map(name => child.value?.fields.find(f => f.name === name))
    .filter((f): f is VectorField => f !== undefined))
const min = computed(() => props.field.repeater?.min ?? 0)
const max = computed(() => props.field.repeater?.max ?? 100)
const rows = computed(() => (Array.isArray(model.value) ? model.value : []))
const width = computed(() => Math.max(3, Math.floor(12 / Math.max(1, Math.min(columns.value.length, 4)))))

// Stable keys, so removing a row keeps every other row's inputs (and a
// relation input's loaded label) with the row it belongs to.
let next = 0
const keys = ref<number[]>([])
watch(() => rows.value.length, (length) => {
    if (keys.value.length !== length) keys.value = Array.from({ length }, () => next++)
}, { immediate: true })

function blank(): Record<string, unknown> {
    return Object.fromEntries(columns.value.map(f => [f.name, f.hasDefault ? f.default : f.type === 'boolean' ? false : null]))
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

/** Row errors are keyed by position, which a removal shifts: they go with it. */
function clearRowErrors() {
    for (const key of Object.keys(props.serverErrors ?? {})) {
        if (key.startsWith(`${props.field.name}.`)) delete props.serverErrors![key]
    }
}

// A row's inputs read the record they belong to through the relation back
// to it: a line priced in `order.currency` sees the order being edited.
const inverse = computed(() => props.field.relation?.inverse)
const rowRecord = (row: Record<string, unknown>) => (inverse.value ? { ...row, [inverse.value]: props.record ?? {} } : row)

const rowErrors = (index: number, name: string) => props.serverErrors?.[`${props.field.name}.${index}.${name}`] ?? []

// A new record starts with the rows it must hold.
onMounted(() => {
    if (child.value && rows.value.length < min.value && !props.record?.id) {
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
        <p v-if="!child" class="text-medium-emphasis text-body-2">{{ t('fields.repeater.unavailable') }}</p>
        <template v-else>
            <div
                v-for="(row, index) in rows"
                :key="keys[index]"
                class="d-flex ga-2 align-start mb-1"
                role="group"
                :aria-label="t('fields.repeater.row', { label: child.labelSingular, n: index + 1 })"
            >
                <v-row density="compact" class="flex-grow-1">
                    <v-col v-for="column in columns" :key="column.name" cols="12" :md="width">
                        <component
                            :is="inputFor(column)"
                            :model-value="row[column.name]"
                            :field="column"
                            :rules="rulesFor(column, row.id ? 'update' : 'create')"
                            :error-messages="rowErrors(index, column.name)"
                            :record="rowRecord(row)"
                            :resource="child.slug"
                            :disabled="disabled"
                            density="compact"
                            @update:model-value="(value: unknown) => set(index, column.name, value)"
                        />
                    </v-col>
                </v-row>
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    size="small"
                    class="mt-2"
                    :disabled="disabled || rows.length <= min"
                    :aria-label="t('fields.repeater.remove', { label: noun(child.labelSingular), n: index + 1 })"
                    @click="remove(index)"
                />
            </div>
            <v-btn variant="tonal" size="small" prepend-icon="mdi-plus" :disabled="disabled || rows.length >= max" @click="add">
                {{ t('fields.repeater.add', { label: noun(child.labelSingular) }) }}
            </v-btn>
        </template>
    </v-card>
</template>
