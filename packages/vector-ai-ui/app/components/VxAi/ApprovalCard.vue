<script setup lang="ts">
import type { ApprovalCard } from '../../composables/useVectorAssistant'

// One change the assistant proposed, for the admin to approve or reject.
// Everything it shows is the server's structured proposal — the tool, the
// resource, the record and each argument field by field, with the value
// before the change where a vector_get of the same run read the record —
// and never the model's prose about it. Approving runs the server's own
// stored arguments; the card only says which.

const props = defineProps<{ approval: ApprovalCard, busy: boolean }>()
const emit = defineEmits<{ approve: [], reject: [] }>()

const host = useVectorHost()
const { t } = useVectorT()

/** Arguments shown in their own rows rather than as the change's fields. */
const NAMED = ['resource', 'id', 'values']

const frame = computed(() => props.approval.frame)
const resource = computed(() => frame.value.resource === null ? undefined : host.schema.value?.resources?.[frame.value.resource])

/** What the change is to: the resource as the admin names it, and the record. */
const target = computed(() => {
    const label = resource.value?.labelSingular ?? resource.value?.label ?? frame.value.resource ?? ''
    return frame.value.recordId === null ? label : `${label} #${frame.value.recordId}`
})

const title = computed(() => {
    const known = ['vector_create', 'vector_update', 'vector_delete', 'vector_run_action']
    return known.includes(frame.value.tool)
        ? t(`ai.assistant.approval.title.${frame.value.tool}`, { target: target.value })
        : t('ai.assistant.approval.title.other', { tool: frame.value.tool, target: target.value })
})

interface Row { name: string, label: string, before: string | null, after: string }

function show(value: unknown): string {
    return value === null || value === undefined ? t('common.none') : displayValue(value)
}

/** Each field the change writes, with its value before where the run read the record. */
const changes = computed<Row[]>(() => {
    const values = frame.value.arguments.values
    if (values === null || typeof values !== 'object' || Array.isArray(values)) return []
    const before = props.approval.before
    return Object.entries(values as Record<string, unknown>).map(([name, value]) => ({
        name,
        label: resource.value?.fields.find(f => f.name === name)?.label ?? name,
        before: before !== null && name in before ? show(before[name]) : null,
        after: show(value),
    }))
})

/** The call's other arguments (an action's name and ids, a delete's force, a version), as they are. */
const others = computed(() => Object.entries(frame.value.arguments)
    .filter(([name]) => !NAMED.includes(name))
    .map(([name, value]) => ({ name, value: show(value) })))

const expires = computed(() => formatDateTime(frame.value.expiresAt))

const reason = computed(() => {
    const result = props.approval.result
    const error = result !== null && typeof result === 'object' ? (result as { error?: unknown }).error : undefined
    return typeof error === 'string' && error !== '' ? error : t('ai.assistant.approval.unknownReason')
})

const decidable = computed(() => props.approval.state === 'pending' || props.approval.state === 'deciding')
</script>

<template>
    <v-card variant="outlined" class="mt-2" data-ai-approval :data-ai-approval-id="frame.id" :data-ai-approval-state="approval.state">
        <v-card-item>
            <v-card-title class="text-subtitle-2 text-wrap" data-ai-approval-title>
                <v-icon icon="mdi-shield-check-outline" size="small" class="me-1" />{{ title }}
            </v-card-title>
            <v-card-subtitle v-if="decidable" class="text-wrap" data-ai-approval-expires>{{ t('ai.assistant.approval.expires', { time: expires }) }}</v-card-subtitle>
        </v-card-item>
        <v-card-text class="pt-0">
            <v-table v-if="changes.length" density="compact" class="text-caption" data-ai-approval-changes>
                <thead>
                    <tr>
                        <th>{{ t('ai.assistant.approval.field') }}</th>
                        <th v-if="approval.before !== null">{{ t('ai.assistant.approval.from') }}</th>
                        <th>{{ t('ai.assistant.approval.to') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in changes" :key="row.name" :data-ai-approval-field="row.name">
                        <td>{{ row.label }}</td>
                        <td v-if="approval.before !== null" data-ai-approval-from>{{ row.before ?? t('common.none') }}</td>
                        <td data-ai-approval-to>{{ row.after }}</td>
                    </tr>
                </tbody>
            </v-table>
            <dl v-if="others.length" class="text-caption mt-1" data-ai-approval-arguments>
                <template v-for="arg in others" :key="arg.name">
                    <dt class="text-medium-emphasis">{{ arg.name }}</dt>
                    <dd class="ms-2" :data-ai-approval-argument="arg.name">{{ arg.value }}</dd>
                </template>
            </dl>
            <v-alert v-if="approval.state === 'executed'" type="success" variant="tonal" density="compact" class="mt-2" data-ai-approval-outcome>{{ t('ai.assistant.approval.executed') }}</v-alert>
            <v-alert v-else-if="approval.state === 'failed'" type="error" variant="tonal" density="compact" class="mt-2" data-ai-approval-outcome>{{ t('ai.assistant.approval.failed', { reason }) }}</v-alert>
            <v-alert v-else-if="approval.state === 'rejected'" type="info" variant="tonal" density="compact" class="mt-2" data-ai-approval-outcome>{{ t('ai.assistant.approval.rejected') }}</v-alert>
            <v-alert v-else-if="approval.state === 'expired'" type="warning" variant="tonal" density="compact" class="mt-2" data-ai-approval-outcome>{{ t('ai.assistant.approval.expired') }}</v-alert>
            <v-alert v-else-if="approval.state === 'gone'" type="warning" variant="tonal" density="compact" class="mt-2" data-ai-approval-outcome>{{ t('ai.assistant.approval.gone') }}</v-alert>
            <v-alert v-if="approval.error" type="error" variant="tonal" density="compact" class="mt-2" data-ai-approval-error>{{ approval.error }}</v-alert>
        </v-card-text>
        <v-card-actions v-if="decidable">
            <v-spacer />
            <v-btn variant="text" :disabled="busy || approval.state === 'deciding'" data-ai-approval-reject @click="emit('reject')">{{ t('ai.assistant.approval.reject') }}</v-btn>
            <v-btn color="primary" variant="flat" :disabled="busy" :loading="approval.state === 'deciding'" data-ai-approval-approve @click="emit('approve')">{{ t('ai.assistant.approval.approve') }}</v-btn>
        </v-card-actions>
    </v-card>
</template>
