<script setup lang="ts">
import type { SubmitEventPromise } from 'vuetify'
import type { VectorField, VectorFieldState, VectorLayoutNode, VectorRecord, VectorResource } from '#vector/app/types/vector'
import { type Violation, violationsOf } from '#vector/app/utils/internal/violations'

// A create or edit form generated from the descriptor: one input per
// editable field, client rules mirroring the server's, and the server's
// 422 mapped onto the field it names. It knows whether it holds unsaved
// changes (`dirty`), and on a page offers save variants: back to the
// record, keep editing, or (creating) start another — the last one chosen
// remembered per user, Ctrl/Cmd+S running it.
const props = defineProps<{
    resource: VectorResource
    operation: 'create' | 'update'
    record?: VectorRecord
    /** Values fixed by the context (a parent a child is created under): pre-filled, not shown. */
    preset?: Record<string, unknown>
    /** Offer the save variants and Ctrl/Cmd+S: the create and edit pages do, a dialog does not. */
    variants?: boolean
    /** Creating a copy: the record whose values the form starts from (Duplicate). */
    copyOf?: VectorRecord
}>()

/** What happens after a save: back to the record, keep editing it, or start another. */
export type SaveVariant = 'close' | 'continue' | 'another'

const emit = defineEmits<{ saved: [record: VectorRecord, variant: SaveVariant], cancel: [] }>()

const { t } = useVectorT()
const { inputFor, editable } = useVectorInputs()
const { resource: find } = useVectorSchema()
const notifications = useVectorNotifications()
const client = useVectorResource(() => props.resource.slug)

const serverErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)
const formError = ref<string | null>(null)
/** Relation fields whose current rows could not be read: shown as they are, never written from a guess. */
const unloaded = reactive(new Set<string>())

const fields = computed<VectorField[]>(() => props.resource.fields.filter(f => editable(f, props.operation)))

// The form's values, keyed by field name: relation values reduce to ids;
// `initial` is what they were loaded as, which `dirty` and an update compare against.
const values = reactive<Record<string, unknown>>({})
const initial = reactive<Record<string, unknown>>({})
const clone = (value: unknown) => value === undefined ? undefined : JSON.parse(JSON.stringify(value)) as unknown

/**
 * What a copy takes from the record it duplicates: every value the create
 * form writes, but never a unique one (it would clash) nor a file or image
 * (a stored key belongs to its record). Collections load below.
 */
const copies = (field: VectorField) => props.operation === 'create' && props.copyOf !== undefined
    && field.unique !== true && !['file', 'image', 'manyToMany', 'hasMany'].includes(field.type)

/** What a field starts with when nothing gives it a value: on create its declared default, else its type's empty one. */
function initialValue(field: VectorField): unknown {
    return props.operation === 'create' && field.hasDefault ? JSON.parse(JSON.stringify(field.default ?? null)) : defaultFor(field)
}

/** Fills the values from the record, the preset, the record being copied, or each field's default. */
function start() {
    for (const field of fields.value) {
        const current = props.preset?.[field.name] ?? props.record?.[field.name] ?? (copies(field) ? props.copyOf![field.name] : undefined)
        // a new record starts from each field's default, which the server would apply as well
        let value: unknown = current === undefined ? initialValue(field) : current

        if (field.type === 'belongsTo' && current && typeof current === 'object' && 'id' in current) {
            value = (current as { id: unknown }).id
        }

        if (field.type === 'manyToMany' || field.type === 'hasMany') {
            value = [] // the ids, or a repeater's rows, load from their endpoint below
        }

        values[field.name] = value
        initial[field.name] = clone(value)
    }
}
start()

function defaultFor(field: VectorField): unknown {
    if (field.type === 'boolean') return false
    if (field.type === 'manyToMany' || field.type === 'hasMany') return []
    return null
}

// A manyToMany field's current membership is not in the record (it lists
// as a count), so an edit form fetches every id it holds: the server
// syncs the whole list, so a partial one would detach what it left out.
// A copy takes the same collections: every membership, every repeater row (as a new row, without its id).
const loadFrom = props.operation === 'update' ? props.record : props.copyOf

if (loadFrom) {
    for (const field of fields.value.filter(f => f.type === 'manyToMany')) {
        allRelatedIds(loadFrom.id, field).then((ids) => {
            values[field.name] = ids
            initial[field.name] = [...ids]
        }).catch((error) => {
            unloaded.add(field.name)
            formError.value = vectorErrorMessage(error)
        })
    }

    // A repeater's rows: each child as a row of the fields it writes, its relations as ids.
    for (const field of fields.value.filter(f => f.repeater)) {
        client.rows(loadFrom.id, field.name).catch((error) => {
            unloaded.add(field.name)
            formError.value = vectorErrorMessage(error)

            return null
        }).then((page) => {
            if (page === null) return

            const rows = page.data.map(record => Object.fromEntries([
                ...(props.operation === 'update' ? [['id', record.id]] : []),
                ...field.repeater!.fields.map((name) => {
                    const value = record[name]
                    return [name, value && typeof value === 'object' && 'id' in value ? (value as { id: unknown }).id : value]
                }),
            ]))
            values[field.name] = rows
            initial[field.name] = JSON.parse(JSON.stringify(rows))
        })
    }
}

/** Every id a many-to-many field holds, page by page: the target's page cap bounds one request, not the membership. */
async function allRelatedIds(id: string | number, field: VectorField): Promise<unknown[]> {
    const perPage = (field.relation ? find(field.relation.resource)?.table.maxPerPage : undefined) ?? 100
    const ids: unknown[] = []

    for (let page = 1; ; page++) {
        const result = await client.related(id, field.name, { page, perPage })
        ids.push(...result.data.map(r => r.id))

        // an uncounted (withoutCount) target has no last page: it says whether another follows
        if (result.lastPage === null ? !result.hasMore : page >= result.lastPage) return ids
    }
}

const wide = (field: VectorField) => ['markdown', 'richtext', 'text', 'json', 'keyValue'].includes(field.type) || !!field.repeater

/** Fields the browser's own rules refused on the last submit, by the input id each carries. */
const clientErrors = ref<string[]>([])
const inputId = (name: string) => `vx-field-${name}`

/** Whether a value differs from what the form loaded: what leaving would lose. */
const dirty = computed(() => fields.value.some(f => !unloaded.has(f.name) && JSON.stringify(values[f.name] ?? null) !== JSON.stringify(initial[f.name] ?? null)))

// The save variant: the user's last choice, where this form offers it.
const preferences = useVectorPreferences()
preferences.load().catch(() => undefined)
const offered = computed<SaveVariant[]>(() => props.operation === 'create' ? ['close', 'continue', 'another'] : ['close', 'continue'])
const chosen = computed<SaveVariant>(() => {
    const stored = preferences.get<SaveVariant>('form.saveVariant')
    return stored && offered.value.includes(stored) ? stored : 'close'
})
const variantLabel = (variant: SaveVariant) => {
    const verb = props.operation === 'create' ? 'create' : 'save'
    if (variant === 'close') return t(`common.${verb}`)
    return t(`record.variants.${verb}${variant === 'continue' ? 'Continue' : 'Another'}`)
}

const formRef = ref<{ validate: () => Promise<{ valid: boolean, errors: { id: string | number }[] }>, resetValidation: () => void } | null>(null)
let pendingVariant: SaveVariant | null = null

/** Saves as $variant, remembering it as this user's default. */
async function saveAs(variant: SaveVariant) {
    if (variant !== chosen.value) preferences.put({ 'form.saveVariant': variant }).catch(() => undefined)
    pendingVariant = variant
    const result = await formRef.value?.validate()
    if (result) await save(result)
}

// Ctrl/Cmd+S saves with the default variant, on a page form
if (import.meta.client) {
    useEventListener(window, 'keydown', (event: KeyboardEvent) => {
        if (props.variants && (event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
            event.preventDefault()
            if (!submitting.value) saveAs(chosen.value)
        }
    })
}

async function submit(event: SubmitEventPromise) {
    await save(await event)
}

async function save({ valid, errors }: { valid: boolean, errors: { id: string | number }[] }) {
    const variant = pendingVariant ?? chosen.value
    pendingVariant = null
    clientErrors.value = errors.map(e => String(e.id)).filter(id => id.startsWith('vx-field-')).map(id => id.slice('vx-field-'.length))

    if (!valid) {
        return
    }

    submitting.value = true
    formError.value = null

    for (const key of Object.keys(serverErrors)) {
        delete serverErrors[key]
    }

    try {
        const response = await persist()

        notifications.success(t('record.saved', { label: props.resource.labelSingular }))
        // what is saved is no longer a change: leaving now asks nothing
        for (const field of fields.value) initial[field.name] = clone(values[field.name])
        emit('saved', response.data, variant)
    } catch (error) {
        showFailure(error)
    } finally {
        submitting.value = false
    }
}

/** Creates the record, or updates it with what changed. */
function persist() {
    if (props.operation === 'create') return client.create(payload(false))

    const version = props.record?._version as number | undefined
    return client.update(props.record!.id, payload(true), version)
}

/** A failed save: the server's 422 mapped onto the fields it names, anything else above the form. */
function showFailure(error: unknown) {
    const failure = error as { status?: number, statusCode?: number, data?: { errors?: unknown } } | null
    const violations = failure?.data?.errors

    if ((failure?.status ?? failure?.statusCode) !== 422 || !Array.isArray(violations)) {
        formError.value = vectorErrorMessage(error)
        return
    }

    for (const violation of violationsOf(violations)) showViolation(violation)

    if (!formError.value && Object.keys(serverErrors).length === 0) {
        formError.value = t('common.serverRejected')
    }
}

/** One violation under the field it names, or above the form when it names none the form holds. */
function showViolation(violation: Violation) {
    // a repeater row's own violation stays with its row: `lines.2.quantity`
    const repeated = violation.path.length >= 3 && fields.value.some(f => f.name === violation.path[0] && f.repeater)
    const key = repeated ? violation.path.slice(0, 3).join('.') : String(violation.path[0] ?? '')

    if (key && (key in values || repeated)) {
        serverErrors[key] ??= []
        serverErrors[key].push(`${violation.message.charAt(0).toUpperCase()}${violation.message.slice(1)}`)
    } else {
        formError.value = violation.message
    }
}

/** Create sends every editable field; update sends only what changed. */
function payload(onlyChanged: boolean): Record<string, unknown> {
    const body: Record<string, unknown> = {}

    for (const field of fields.value) {
        const value = values[field.name]

        if (unloaded.has(field.name)) {
            continue // its current rows never arrived; writing an empty list would detach them all
        }

        if (onlyChanged && JSON.stringify(value) === JSON.stringify(initial[field.name])) {
            continue
        }

        if (!onlyChanged && (value === null || value === '') && field.nullable !== true && field.type !== 'manyToMany') {
            continue // let the server report the required field rather than sending an empty string
        }

        body[field.name] = value === '' ? null : value
    }

    return body
}

function clearServerError(name: string) {
    delete serverErrors[name]
}

/** Back to a fresh form: the defaults a new record starts from, no errors. */
function reset() {
    for (const key of Object.keys(serverErrors)) delete serverErrors[key]
    formError.value = null
    clientErrors.value = []
    start()
    formRef.value?.resetValidation()
}

defineExpose({ dirty, reset })

// Conditions (visibleWhen, requiredWhen, disabledWhen) follow the values as they are edited.
// Like the server, they read the stored record overlaid with the values being edited.
const states = computed<Record<string, VectorFieldState>>(() => {
    const current = { ...props.record, ...values }
    return Object.fromEntries(fields.value.map(f => [f.name, fieldState(f, current)]))
})
const shownFields = computed(() => fields.value.filter(f => states.value[f.name]?.visible !== false && !(props.preset && f.name in props.preset)))
const fieldsByName = computed(() => new Map(fields.value.map(f => [f.name, f])))
// what an input sees of the record: the values being edited, and the stored record's id (a rich-text image loads through it)
const inputRecord = computed(() => ({ ...values, id: props.record?.id }))
const shownNames = computed(() => new Set(shownFields.value.map(f => f.name)))

// The declared layout, or every field in order; either way VxLayout draws it.
const layout = computed<VectorLayoutNode[]>(() => props.resource.layout?.form
    ?? fields.value.map(f => ({ kind: 'field', label: null, description: null, collapsible: false, collapsed: false, columns: null, span: null, name: f.name, children: null })))
const defaultSpan = (name: string) => {
    const field = fieldsByName.value.get(name)
    return field && wide(field) ? 'full' as const : 1 as const
}

/** Every field holding an error: the server's (a repeater row's under its field) and the browser's. */
const errorNames = computed(() => [...new Set([
    ...Object.entries(serverErrors).filter(([, messages]) => messages.length > 0).map(([key]) => key.split('.')[0]!),
    ...clientErrors.value,
])])

/** The server's errors on a field the form does not show (visibleWhen, or preset): above the form, under the field's label. */
const hiddenErrors = computed(() => Object.entries(serverErrors)
    .map(([key, messages]) => [key.split('.')[0]!, messages] as const)
    .filter(([name]) => !shownNames.value.has(name))
    .flatMap(([name, messages]) => messages.map(message => `${fieldsByName.value.get(name)?.label ?? name}: ${message}`)))
</script>

<template>
    <v-form ref="formRef" validate-on="blur lazy" @submit.prevent="submit">
        <v-card>
            <v-card-text>
                <v-alert v-if="formError || hiddenErrors.length" type="error" variant="tonal" class="mb-4">
                    <div v-if="formError">{{ formError }}</div>
                    <div v-for="message in hiddenErrors" :key="message">{{ message }}</div>
                </v-alert>
                <VxLayout :nodes="layout" :shown="name => shownNames.has(name)" :errors="errorNames" :default-span="defaultSpan">
                    <template #field="{ field: name }">
                        <component
                            :is="inputFor(fieldsByName.get(name)!)"
                            :id="inputId(name)"
                            v-model="values[name]"
                            :field="fieldsByName.get(name)!"
                            :disabled="states[name]?.disabled === true"
                            :rules="rulesFor(fieldsByName.get(name)!, operation, states[name])"
                            :error-messages="serverErrors[name] ?? []"
                            :record="inputRecord"
                            :resource="resource.slug"
                            v-bind="fieldsByName.get(name)!.repeater ? { serverErrors } : {}"
                            @update:model-value="clearServerError(name)"
                        />
                    </template>
                </VxLayout>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="emit('cancel')">{{ t('common.cancel') }}</v-btn>
                <v-btn v-if="!variants" type="submit" color="primary" :loading="submitting">{{ operation === 'create' ? t('common.create') : t('common.save') }}</v-btn>
                <v-btn-group v-else color="primary" density="comfortable" divided>
                    <v-btn type="submit" :loading="submitting">{{ variantLabel(chosen) }}</v-btn>
                    <v-menu location="top end">
                        <template #activator="{ props: activator }">
                            <v-btn v-bind="activator" icon="mdi-menu-down" :aria-label="t('record.variants.menu')" :disabled="submitting" />
                        </template>
                        <v-list density="compact">
                            <v-list-item v-for="variant in offered" :key="variant" :title="variantLabel(variant)" :active="variant === chosen" @click="saveAs(variant)" />
                        </v-list>
                    </v-menu>
                </v-btn-group>
            </v-card-actions>
        </v-card>
    </v-form>
</template>
