<script setup lang="ts">
import type { VectorImportMode, VectorImportPreview, VectorResource } from '#vector/app/types/vector'

// CSV import in two steps: upload for a preview and a suggested
// mapping, adjust the mapping, then queue the import and follow the job.
// An import creates records, or updates the records a key column finds
// (the key or a unique field), or does either row by row.
const props = defineProps<{ resource: VectorResource }>()
const emit = defineEmits<{ queued: [jobId: number], cancel: [] }>()

const appConfig = useAppConfig()
const client = useVectorResource(() => props.resource.slug)
const { t } = useVectorT()
const notifications = useVectorNotifications()
const file = ref<File | null>(null)
const preview = ref<VectorImportPreview | null>(null)
const mapping = reactive<Record<string, string>>({})
const busy = ref(false)
const error = ref<string | null>(null)
const mode = ref<VectorImportMode>(props.resource.permissions.create ? 'create' : 'update')
const key = ref('')

const modes = computed(() => (['create', 'update', 'upsert'] as const)
    .filter(m => m === 'create' ? props.resource.permissions.create : m === 'update' ? props.resource.permissions.update : props.resource.permissions.create && props.resource.permissions.update)
    .map(m => ({ title: t(`list.import.modes.${m}`), value: m })))

// what the mode writes; an update also maps the column it matches rows by
const fields = computed(() => {
    const views = mode.value === 'create' ? ['create'] : mode.value === 'update' ? ['update'] : ['create', 'update']
    const writable = props.resource.fields.filter(f => !f.readonly && f.views.some(v => views.includes(v)) && !['id', 'hasOne', 'hasMany', 'computed'].includes(f.type))
    const keys = mode.value === 'create' ? [] : keyFields.value.filter(f => !writable.includes(f))

    return [...keys, ...writable].map(f => ({ title: f.label, value: f.name }))
})
const keyFields = computed(() => props.resource.fields.filter(f => f.name === props.resource.primaryKey.field || f.unique))
const keyChoices = computed(() => keyFields.value.filter(f => Object.values(mapping).includes(f.name)).map(f => ({ title: f.label, value: f.name })))

watch(keyChoices, choices => {
    if (!choices.some(c => c.value === key.value)) key.value = choices[0]?.value ?? ''
})
// the suggestion maps the key column too; a mode that does not take a field skips its column
const suggested = ref<Record<string, string>>({})
watch(mode, applyMapping)

function applyMapping() {
    const allowed = new Set(fields.value.map(f => f.value))
    for (const key of Object.keys(mapping)) delete mapping[key]
    for (const header of preview.value?.headers ?? []) {
        const field = suggested.value[header] ?? ''
        mapping[header] = allowed.has(field) ? field : ''
    }
}

async function analyse() {
    if (!file.value) return
    busy.value = true
    error.value = null

    try {
        preview.value = await client.importPreview(file.value)
        suggested.value = preview.value.mapping
        applyMapping()
    } catch (caught) {
        error.value = vectorErrorMessage(caught)
    } finally {
        busy.value = false
    }
}

async function start() {
    if (!file.value) return
    busy.value = true
    error.value = null

    try {
        const chosen = Object.fromEntries(Object.entries(mapping).filter(([, v]) => v))
        const { jobId, rows } = await client.importCsv(file.value, chosen, mode.value, key.value)
        notifications.success(t('list.import.queued', { rows }))
        emit('queued', jobId)
        await navigateTo(`${appConfig.vector.basePath}/jobs/${jobId}`)
    } catch (caught) {
        error.value = vectorErrorMessage(caught)
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <v-dialog :model-value="true" max-width="720" persistent scrollable @update:model-value="emit('cancel')">
        <v-card :title="t('list.import.title', { label: resource.label.toLowerCase() })">
            <v-card-text>
                <v-alert v-if="error" type="error" variant="tonal" class="mb-4" :text="error" />
                <v-file-input v-model="file" :label="t('list.import.file')" accept=".csv,text/csv" prepend-icon="mdi-file-delimited-outline" :disabled="busy" @update:model-value="preview = null" />
                <v-btn v-if="!preview" color="primary" :disabled="!file" :loading="busy" @click="analyse">{{ t('list.import.analyse') }}</v-btn>
                <template v-else>
                    <p class="text-body-2 mb-3">{{ t('list.import.instructions', { rows: preview.rows, pipe: '|' }) }}</p>
                    <div class="d-flex flex-wrap ga-3 mb-3">
                        <v-select v-model="mode" :items="modes" :label="t('list.import.mode')" density="compact" hide-details style="min-width: 220px" />
                        <v-select
                            v-if="mode !== 'create'"
                            v-model="key"
                            :items="keyChoices"
                            :label="t('list.import.key')"
                            :no-data-text="t('list.import.noKey')"
                            density="compact"
                            hide-details
                            style="min-width: 220px"
                        />
                    </div>
                    <p v-if="mode !== 'create'" class="text-body-2 text-medium-emphasis mb-3">{{ t(`list.import.modeHelp.${mode}`) }}</p>
                    <v-table density="compact">
                        <thead><tr><th>{{ t('list.import.csvColumn') }}</th><th>{{ t('list.import.sample') }}</th><th style="width: 260px">{{ t('list.field') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="header in preview.headers" :key="header">
                                <td>{{ header }}</td>
                                <td class="text-medium-emphasis text-truncate" style="max-width: 200px">{{ preview.sample[0]?.[header] ?? '' }}</td>
                                <td><v-select v-model="mapping[header]" :items="[{ title: t('list.import.skip'), value: '' }, ...fields]" density="compact" hide-details /></td>
                            </tr>
                        </tbody>
                    </v-table>
                </template>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" :disabled="busy" @click="emit('cancel')">{{ t('common.cancel') }}</v-btn>
                <v-btn v-if="preview" color="primary" :loading="busy" :disabled="mode !== 'create' && !key" @click="start">{{ t('list.import.start', { rows: preview.rows }) }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
