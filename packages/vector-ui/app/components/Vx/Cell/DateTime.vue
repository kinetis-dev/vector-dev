<script setup lang="ts">
const props = defineProps<{ value: unknown }>()
const text = computed(() => typeof props.value === 'string' ? formatDateTime(props.value) : '')
const { t } = useVectorT()
</script>

<template>
    <span v-if="text === ''" class="text-disabled">{{ t('common.none') }}</span>
    <span v-else class="text-no-wrap" :title="String(value)">{{ text }}</span>
</template>
