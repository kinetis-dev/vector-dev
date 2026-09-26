<script setup lang="ts">
defineProps<{ value: unknown }>()
const { t } = useVectorT()
</script>

<template>
    <v-icon v-if="value === true" icon="mdi-check-circle" color="success" size="small" />
    <v-icon v-else-if="value === false" icon="mdi-close-circle-outline" color="grey" size="small" />
    <span v-else class="text-disabled">{{ t('common.none') }}</span>
</template>
