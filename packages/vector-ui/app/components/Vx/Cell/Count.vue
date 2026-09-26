<script setup lang="ts">
import type { VectorCountValue } from '#vector/app/types/vector'

const props = defineProps<{ value: unknown }>()
const count = computed(() => (props.value && typeof props.value === 'object' && 'count' in props.value ? (props.value as VectorCountValue).count : null))
const { t } = useVectorT()
</script>

<template>
    <v-chip v-if="count !== null" size="small" variant="tonal" :color="count > 0 ? 'primary' : undefined">{{ count }}</v-chip>
    <span v-else class="text-disabled">{{ t('common.none') }}</span>
</template>
