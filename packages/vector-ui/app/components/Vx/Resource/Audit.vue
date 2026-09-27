<script setup lang="ts">
import type { VectorAuditEntry, VectorResource } from '#vector/app/types/vector'

// The audit trail of one record: who changed what, when, as whom. An
// update (or a revert) can be reverted by whoever may edit the record.
const props = defineProps<{ resource: VectorResource, id: string | number, canUpdate?: boolean }>()
const emit = defineEmits<{ reverted: [] }>()
const { t } = useVectorT()
const { confirm } = useConfirm()
const notifications = useVectorNotifications()
const client = useVectorResource(() => props.resource.slug)
const { data, status, error, refresh } = await useAsyncData(() => `vector.audit.${props.resource.slug}.${props.id}`, () => client.audit(props.id))
const entries = computed<VectorAuditEntry[]>(() => data.value?.data ?? [])

const label = (name: string) => props.resource.fields.find(f => f.name === name)?.label ?? name
const show = (value: unknown) => value === null || value === undefined ? t('common.none') : typeof value === 'object' ? JSON.stringify(value) : String(value)
const icon: Record<string, string> = { create: 'mdi-plus-circle-outline', update: 'mdi-pencil-outline', revert: 'mdi-undo-variant', delete: 'mdi-delete-outline', restore: 'mdi-restore', forceDelete: 'mdi-delete-forever-outline' }

/** The fields of an entry a revert would put back: shown, and editable on update. */
function revertableFields(entry: VectorAuditEntry): string[] {
    if (!props.canUpdate || !['update', 'revert'].includes(entry.event)) return []
    return Object.keys(entry.changes).filter((name) => {
        const field = props.resource.fields.find(f => f.name === name)
        return field !== undefined && !field.readonly && field.views.includes('update') && !['id', 'hasOne', 'hasMany', 'manyToMany', 'password'].includes(field.type)
    })
}

const reverting = ref<number | null>(null)

async function revert(entry: VectorAuditEntry) {
    const fields = revertableFields(entry).map(label).join(', ')
    if (!await confirm({ title: t('record.audit.revertTitle'), text: t('record.audit.revertText', { fields }), confirmText: t('record.audit.revert') })) return

    reverting.value = entry.id
    try {
        await client.revert(props.id, entry.id)
        notifications.success(t('record.audit.reverted'))
        await refresh()
        emit('reverted')
    } catch (failure) {
        notifications.error(vectorErrorMessage(failure))
    } finally {
        reverting.value = null
    }
}
</script>

<template>
    <v-card class="mt-4">
        <v-card-title class="text-subtitle-1">{{ t('record.audit.title') }}</v-card-title>
        <v-card-text>
            <v-skeleton-loader v-if="status === 'pending'" type="list-item-two-line" />
            <v-alert v-else-if="error" type="error" variant="tonal" density="compact" :text="vectorErrorMessage(error)" />
            <v-timeline v-else-if="entries.length" side="end" density="compact" align="start">
                <v-timeline-item v-for="entry in entries" :key="entry.id" :icon="icon[entry.event] ?? 'mdi-play-circle-outline'" dot-color="primary" size="small">
                    <div class="text-body-2">
                        <strong>{{ entry.event }}</strong> {{ t('record.audit.byUser', { actor: entry.actorId }) }}<span v-if="entry.impersonatorId"> {{ t('record.audit.impersonatedBy', { impersonator: entry.impersonatorId }) }}</span>
                        <span class="text-medium-emphasis"> · {{ formatDateTime(entry.createdAt) }}</span>
                        <v-btn
                            v-if="revertableFields(entry).length"
                            size="x-small"
                            variant="text"
                            prepend-icon="mdi-undo-variant"
                            class="ms-2"
                            :loading="reverting === entry.id"
                            :aria-label="t('record.audit.revertLabel')"
                            @click="revert(entry)"
                        >{{ t('record.audit.revert') }}</v-btn>
                    </div>
                    <ul v-if="Object.keys(entry.changes).length" class="text-caption mt-1 ps-4">
                        <li v-for="(change, field) in entry.changes" :key="field">
                            <span class="text-medium-emphasis">{{ label(String(field)) }}:</span>
                            <template v-if="entry.event === 'create'"> {{ show(change) }}</template>
                            <template v-else> {{ show(change.from) }} → {{ show(change.to) }}</template>
                        </li>
                    </ul>
                </v-timeline-item>
            </v-timeline>
            <p v-else class="text-medium-emphasis text-body-2">{{ t('record.audit.empty') }}</p>
        </v-card-text>
    </v-card>
</template>
