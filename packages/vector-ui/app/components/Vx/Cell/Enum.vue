<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

const props = defineProps<{ field: VectorField, value: unknown }>()
const option = computed(() => props.field.options?.find(o => o.value === props.value))
const { t } = useVectorT()
</script>

<template>
    <span v-if="value === null || value === undefined" class="text-disabled">{{ t('common.none') }}</span>
    <v-chip v-else size="small" :color="option?.color ?? undefined" variant="tonal" label>
        {{ option?.label ?? String(value) }}
    </v-chip>
</template>
