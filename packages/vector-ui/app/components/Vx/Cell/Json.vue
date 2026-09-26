<script setup lang="ts">
const props = defineProps<{ value: unknown, context: 'index' | 'show' }>()
const pretty = computed(() => {
    if (typeof props.value !== 'string' || props.value === '') return ''
    try {
        return JSON.stringify(JSON.parse(props.value), null, props.context === 'show' ? 2 : 0)
    } catch {
        return props.value
    }
})
const { t } = useVectorT()
</script>

<template>
    <span v-if="pretty === ''" class="text-disabled">{{ t('common.none') }}</span>
    <pre v-else-if="context === 'show'" class="text-body-2 pa-3 rounded bg-surface-light overflow-auto">{{ pretty }}</pre>
    <code v-else class="text-truncate d-inline-block" style="max-width: 24rem">{{ pretty }}</code>
</template>
