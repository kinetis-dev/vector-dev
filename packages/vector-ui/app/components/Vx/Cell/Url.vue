<script setup lang="ts">
defineProps<{ value: unknown }>()
const { t } = useVectorT()
</script>

<template>
    <a v-if="typeof value === 'string' && value && allowedHref(value)" :href="value" target="_blank" rel="noopener noreferrer" @click.stop>{{ value }}</a>
    <!-- a value with another scheme is shown, never followed -->
    <span v-else-if="typeof value === 'string' && value">{{ value }}</span>
    <span v-else class="text-disabled">{{ t('common.none') }}</span>
</template>
