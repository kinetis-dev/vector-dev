<script setup lang="ts">
// A JSON column's document: indented on a record, on one line in a list.
const props = defineProps<{ value: unknown, context: 'index' | 'show' }>()
const pretty = computed(() => {
    if (props.value === null || props.value === undefined) return ''
    return JSON.stringify(props.value, null, props.context === 'show' ? 2 : 0)
})
const { t } = useVectorT()
</script>

<template>
    <span v-if="pretty === ''" class="text-disabled">{{ t('common.none') }}</span>
    <pre v-else-if="context === 'show'" class="text-body-2 pa-3 rounded bg-surface-light overflow-auto">{{ pretty }}</pre>
    <code v-else class="text-truncate d-inline-block" style="max-width: 24rem">{{ pretty }}</code>
</template>
