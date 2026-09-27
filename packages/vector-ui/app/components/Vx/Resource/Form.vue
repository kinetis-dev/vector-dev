<script setup lang="ts">
import type { SubmitEventPromise } from 'vuetify'
import type { VectorField, VectorFieldState, VectorLayoutNode, VectorRecord, VectorResource } from '#vector/app/types/vector'

// A create or edit form generated from the descriptor: one input per
// editable field, client rules mirroring the server's, and the server's
// 422 mapped onto the field it names.
const props = defineProps<{
    resource: VectorResource
    operation: 'create' | 'update'
    record?: VectorRecord
    /** Values fixed by the context (a parent a child is created under): pre-filled, not shown. */
    preset?: Record<string, unknown>
}>()

const emit = defineEmits<{ saved: [record: VectorRecord], cancel: [] }>()

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

// The form's values, keyed by field name: relation values reduce to ids.
const values = reactive<Record<string, unknown>>({})
const initial: Record<string, unknown> = {}

for (const field of fields.value) {
    const current = props.preset?.[field.name] ?? props.record?.[field.name]
    // a new record starts from each field's default, which the server would apply as well
    let value: unknown = current !== undefined
        ? current
        : props.operation === 'create' && field.hasDefault ? JSON.parse(JSON.stringify(field.default ?? null)) : defaultFor(field)

    if (field.type === 'belongsTo' && current && typeof current === 'object' && 'id' in current) {
        value = (current as { id: unknown }).id
    }

    if (field.type === 'manyToMany' || field.type === 'hasMany') {
        value = [] // the ids, or a repeater's rows, load from their endpoint below
    }

    values[field.name] = value
    initial[field.name] = value
}

function defaultFor(field: VectorField): unknown {
    if (field.type === 'boolean') return false
    if (field.type === 'manyToMany' || field.type === 'hasMany') return []
    return null
}

// A manyToMany field's current membership is not in the record (it lists
// as a count), so an edit form fetches every id it holds: the server
// syncs the whole list, so a partial one would detach what it left out.
if (props.operation === 'update' && props.record) {
    for (const field of fields.value.filter(f => f.type === 'manyToMany')) {
        allRelatedIds(props.record.id, field).then((ids) => {
            values[field.name] = ids
            initial[field.name] = [...ids]
        }).catch((error) => {
            unloaded.add(field.name)
            formError.value = vectorErrorMessage(error)
        })
    }

    // A repeater's rows: each child as a row of the fields it writes, its relations as ids.
    for (const field of fields.value.filter(f => f.repeater)) {
        client.rows(props.record.id, field.name).catch((error) => {
            unloaded.add(field.name)
            formError.value = vectorErrorMessage(error)

            return null
        }).then((page) => {
            if (page === null) return

            const rows = page.data.map(record => Object.fromEntries([
                ['id', record.id],
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

        if (page >= result.lastPage) return ids
    }
}

const wide = (field: VectorField) => ['markdown', 'richtext', 'text', 'json', 'keyValue'].includes(field.type) || !!field.repeater

/** Fields the browser's own rules refused on the last submit, by the input id each carries. */
const clientErrors = ref<string[]>([])
const inputId = (name: string) => `vx-field-${name}`

async function submit(event: SubmitEventPromise) {
    const { valid, errors } = await event
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
        let response

        if (props.operation === 'create') {
            response = await client.create(payload(false))
        } else {
            const version = props.record?._version as number | undefined
            response = await client.update(props.record!.id, payload(true), version)
        }

        notifications.success(t('record.saved', { label: props.resource.labelSingular }))
        emit('saved', response.data)
    } catch (error) {
        const failure = error as { status?: number, statusCode?: number, data?: { errors?: unknown } } | null
        const violations = failure?.data?.errors

        if ((failure?.status ?? failure?.statusCode) === 422 && Array.isArray(violations)) {
            for (const violation of violations as { path: (string | number)[], message: string }[]) {
                // a repeater row's own violation stays with its row: `lines.2.quantity`
                const repeated = violation.path.length >= 3 && fields.value.some(f => f.name === violation.path[0] && f.repeater)
                const key = repeated ? violation.path.slice(0, 3).join('.') : String(violation.path[0] ?? '')

                if (key && (key in values || repeated)) {
                    (serverErrors[key] ??= []).push(`${violation.message.charAt(0).toUpperCase()}${violation.message.slice(1)}`)
                } else {
                    formError.value = violation.message
                }
            }

            if (!formError.value && Object.keys(serverErrors).length === 0) {
                formError.value = t('common.serverRejected')
            }
        } else {
            formError.value = vectorErrorMessage(error)
        }
    } finally {
        submitting.value = false
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

// Conditions (visibleWhen, requiredWhen, disabledWhen) follow the values as they are edited.
// Like the server, they read the stored record overlaid with the values being edited.
const states = computed<Record<string, VectorFieldState>>(() => {
    const current = { ...(props.record ?? {}), ...values }
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
</script>

<template>
    <v-form validate-on="blur lazy" @submit.prevent="submit">
        <v-card>
            <v-card-text>
                <v-alert v-if="formError" type="error" variant="tonal" class="mb-4" :text="formError" />
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
                <v-btn type="submit" color="primary" :loading="submitting">{{ operation === 'create' ? t('common.create') : t('common.save') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-form>
</template>
