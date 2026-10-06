<script setup lang="ts">
// A key-value field: its first entries as chips in a list, every entry
// as a two-column table on a record.
const props = defineProps<{ value: unknown, context: 'index' | 'show' }>()
const entries = computed(() => (props.value && typeof props.value === 'object' && !Array.isArray(props.value)
    ? Object.entries(props.value as Record<string, unknown>).map(([key, value]) => [key, String(value ?? '')] as const)
    : []))
const { t } = useVectorT()
</script>

<template>
    <span v-if="!entries.length" class="text-disabled">{{ t('common.none') }}</span>
    <v-table v-else-if="context === 'show'" density="compact">
        <tbody>
            <tr v-for="[key, text] in entries" :key="key">
                <th scope="row" class="text-medium-emphasis font-weight-regular">{{ key }}</th>
                <td>{{ text }}</td>
            </tr>
        </tbody>
    </v-table>
    <span v-else class="d-inline-flex flex-wrap align-center ga-1">
        <v-chip v-for="[key, text] in entries.slice(0, 3)" :key="key" size="x-small" label>{{ key }}: {{ text }}</v-chip>
        <span v-if="entries.length > 3" class="text-caption text-medium-emphasis">{{ t('fields.keyValue.more', { count: entries.length - 3 }) }}</span>
    </span>
</template>
