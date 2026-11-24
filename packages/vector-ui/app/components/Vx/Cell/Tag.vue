<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// Free-text tags, as chips in the order they are stored.
const props = defineProps<{ field: VectorField, value: unknown }>()
const { t } = useVectorT()
const tags = computed(() => (Array.isArray(props.value) ? props.value.filter((v): v is string => typeof v === 'string') : []))
</script>

<template>
    <span v-if="tags.length === 0" class="text-disabled">{{ t('common.none') }}</span>
    <span v-else class="d-inline-flex flex-wrap align-center ga-1">
        <v-chip v-for="tag in tags" :key="tag" size="small" variant="tonal" label>{{ tag }}</v-chip>
    </span>
</template>
