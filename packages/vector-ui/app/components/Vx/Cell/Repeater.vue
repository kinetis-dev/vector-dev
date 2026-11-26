<script setup lang="ts">
import type { VectorField, VectorRecord, VectorResource } from '#vector/app/types/vector'

// A repeater's rows. In a list, how many there are. On a record, a
// compact table: a JSON repeater's rows are the value itself; a
// relationship repeater's are child records, loaded from their endpoint
// in the order the repeater keeps them.
const props = defineProps<{ field: VectorField, value: unknown, record: Record<string, unknown>, resource: VectorResource, context: 'index' | 'show' }>()

const { t } = useVectorT()
const { resource: resourceOf } = useVectorSchema()

const json = computed(() => props.field.repeater?.mode !== 'relationship')
const child = computed(() => !json.value && props.field.relation ? resourceOf(props.field.relation.resource) : undefined)
const columns = computed<VectorField[]>(() => json.value
    ? props.field.repeater?.rowFields ?? []
    : (props.field.repeater?.fields ?? [])
            .map(name => child.value?.fields.find(f => f.name === name))
            .filter((f): f is VectorField => f !== undefined))

const count = computed<number | null>(() => {
    if (Array.isArray(props.value)) return props.value.length
    return props.value && typeof props.value === 'object' && 'count' in props.value ? Number((props.value as { count: unknown }).count) : null
})

const client = useVectorResource(() => props.resource.slug)
const loaded = ref<VectorRecord[] | null>(null)
const failed = ref(false)
const rows = computed<Record<string, unknown>[]>(() => {
    if (!json.value) return loaded.value ?? []
    return Array.isArray(props.value) ? props.value as Record<string, unknown>[] : []
})

onMounted(async () => {
    if (props.context !== 'show' || json.value || !child.value || props.record.id === undefined || count.value === 0) return

    try {
        loaded.value = (await client.rows(props.record.id as string | number, props.field.name)).data
    } catch {
        failed.value = true
    }
})
</script>

<template>
    <template v-if="context === 'index'">
        <v-chip v-if="count !== null" size="small" variant="tonal" :color="count > 0 ? 'primary' : undefined">{{ count }}</v-chip>
        <span v-else class="text-disabled">{{ t('common.none') }}</span>
    </template>
    <span v-else-if="count === 0 || count === null" class="text-disabled">{{ t('fields.rows.none') }}</span>
    <span v-else-if="failed || (!json && !child)" class="text-medium-emphasis">{{ t('fields.repeater.unavailable') }}</span>
    <v-progress-linear v-else-if="!json && loaded === null" indeterminate :aria-label="t('fields.cell.loading', { label: field.label })" />
    <v-table v-else density="compact" class="vx-repeater-table">
        <thead>
            <tr>
                <th v-for="column in columns" :key="column.name" scope="col">{{ column.label }}</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="(row, index) in rows" :key="index">
                <td v-for="column in columns" :key="column.name">
                    <VxCell :field="column" :value="row[column.name]" :record="row" :resource="child ?? resource" context="index" />
                </td>
            </tr>
        </tbody>
    </v-table>
</template>
