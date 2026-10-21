<script setup lang="ts">
import type { SubmitEventPromise } from 'vuetify'
import type { VectorResource, VectorSelection } from '#vector/app/types/vector'

// Writes the same values to every selected record: the user picks which
// fields to change, fills them in, and the server validates once and
// writes all records in one transaction, or none.
const props = defineProps<{
    resource: VectorResource
    selection: VectorSelection
    /** null: every match of a list that is not counted */
    count: number | null
}>()

const emit = defineEmits<{ done: [affected: number], cancel: [] }>()

const { t, noun } = useVectorT()
const notifications = useVectorNotifications()
const client = useVectorResource(() => props.resource.slug)
const { editable } = useVectorInputs()

const editableFields = computed(() => props.resource.fields.filter(f => editable(f, 'update') && f.type !== 'password' && f.type !== 'hasMany'))
const chosen = ref<string[]>([])
const fields = computed(() => editableFields.value.filter(f => chosen.value.includes(f.name)))
const values = reactive<Record<string, unknown>>({})
const serverErrors = reactive<Record<string, string[]>>({})
const formError = ref<string | null>(null)
const running = ref(false)

watch(chosen, (names) => {
    for (const field of editableFields.value) {
        if (names.includes(field.name) && !(field.name in values)) values[field.name] = initialValue(field)
        if (!names.includes(field.name)) delete values[field.name]
    }
})

const subject = computed(() => {
    if (!props.selection.all) return t('list.bulkEdit.some', { count: props.count, noun: noun(props.count === 1 ? props.resource.labelSingular : props.resource.label) })
    if (props.count === null) return t('list.bulkEdit.everyMatchingUncounted', { noun: noun(props.resource.labelSingular) })
    return t('list.bulkEdit.everyMatching', { noun: noun(props.resource.labelSingular), count: props.count })
})

async function save(event: SubmitEventPromise) {
    const { valid } = await event
    if (!valid || !chosen.value.length) return

    running.value = true
    formError.value = null
    for (const key of Object.keys(serverErrors)) delete serverErrors[key]

    try {
        const { affected } = await client.batch('update', props.selection, { ...values })
        notifications.success(t('list.bulkEdit.updated', { count: affected, noun: noun(affected === 1 ? props.resource.labelSingular : props.resource.label) }))
        emit('done', affected)
    } catch (error) {
        formError.value = applyViolations(error, values, serverErrors)
    } finally {
        running.value = false
    }
}
</script>

<template>
    <v-dialog :model-value="true" max-width="640" persistent @update:model-value="emit('cancel')">
        <v-form validate-on="blur lazy" @submit.prevent="save">
            <v-card>
                <v-card-title class="d-flex align-center ga-2">
                    <v-icon icon="mdi-pencil-box-multiple-outline" />
                    {{ t('list.bulkEdit.title', { subject }) }}
                </v-card-title>
                <v-card-text>
                    <v-alert v-if="formError" type="error" variant="tonal" class="mb-4" :text="formError" />
                    <v-select
                        v-model="chosen"
                        :items="editableFields.map(f => ({ title: f.label, value: f.name }))"
                        :label="t('list.bulkEdit.fieldsToChange')"
                        multiple
                        chips
                        closable-chips
                        :hint="t('list.bulkEdit.fieldsHint')"
                        persistent-hint
                        class="mb-4"
                    />
                    <VxFieldsForm v-if="fields.length" :fields="fields" :values="values" :server-errors="serverErrors" :resource="resource.slug" @clear="(name: string) => delete serverErrors[name]" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" :disabled="running" @click="emit('cancel')">{{ t('common.cancel') }}</v-btn>
                    <v-btn type="submit" color="primary" :loading="running" :disabled="!chosen.length">{{ count === null ? t('list.bulkEdit.applyToAll') : t('list.bulkEdit.applyTo', { count }) }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-form>
    </v-dialog>
</template>
