<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

const props = defineProps<{ field: VectorField, value: unknown, context: 'index' | 'show' }>()
const text = computed(() => props.value === null || props.value === undefined ? '' : String(props.value))
const truncated = computed(() => props.context === 'index' && text.value.length > 80 ? text.value.slice(0, 77) + '…' : text.value)
const { t } = useVectorT()
</script>

<template>
    <span v-if="text === ''" class="text-disabled">{{ t('common.none') }}</span>
    <span v-else :title="truncated !== text ? text : undefined">{{ truncated }}</span>
</template>
