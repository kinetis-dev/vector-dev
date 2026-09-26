<script setup lang="ts">
defineProps<{ value: unknown }>()
const { t } = useVectorT()
</script>

<template>
    <a v-if="typeof value === 'string' && value" :href="`mailto:${value}`" @click.stop>{{ value }}</a>
    <span v-else class="text-disabled">{{ t('common.none') }}</span>
</template>
