<script setup lang="ts">
import type { VectorImportPreview, VectorResource } from '#vector/app/types/vector'

// CSV import in two steps: upload for a preview and a suggested
// mapping, adjust the mapping, then queue the import and follow the job.
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

const fields = computed(() => props.resource.fields
    .filter(f => !f.readonly && f.views.includes('create') && !['id', 'hasOne', 'hasMany'].includes(f.type))
    .map(f => ({ title: f.label, value: f.name })))

async function analyse() {
    if (!file.value) return
    busy.value = true
    error.value = null

    try {
        preview.value = await client.importPreview(file.value)
        for (const key of Object.keys(mapping)) delete mapping[key]
        for (const header of preview.value.headers) mapping[header] = preview.value.mapping[header] ?? ''
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
        const { jobId, rows } = await client.importCsv(file.value, chosen)
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
                <v-btn v-if="preview" color="primary" :loading="busy" @click="start">{{ t('list.import.start', { rows: preview.rows }) }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
