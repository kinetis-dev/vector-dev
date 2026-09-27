<script setup lang="ts">
// Markdown is shown as its source text, never rendered: nothing a record holds becomes HTML.
const props = defineProps<{ value: unknown, context: 'index' | 'show' }>()
const text = computed(() => typeof props.value === 'string' ? props.value : '')
const { t } = useVectorT()
</script>

<template>
    <span v-if="text === ''" class="text-disabled">{{ t('common.none') }}</span>
    <div v-else-if="context === 'show'" class="text-body-1" style="white-space: pre-wrap">{{ text }}</div>
    <span v-else class="text-truncate d-inline-block" style="max-width: 24rem">{{ text }}</span>
</template>
