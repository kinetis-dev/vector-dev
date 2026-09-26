<script setup lang="ts">
defineProps<{ value: unknown }>()
const { t } = useVectorT()
</script>

<template>
    <span v-if="typeof value === 'string' && value" class="d-inline-flex align-center ga-2">
        <v-avatar :color="value" size="16" />
        <code>{{ value }}</code>
    </span>
    <span v-else class="text-disabled">{{ t('common.none') }}</span>
</template>
