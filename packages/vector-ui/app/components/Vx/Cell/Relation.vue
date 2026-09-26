<script setup lang="ts">
import type { VectorField, VectorRelationValue } from '#vector/app/types/vector'

const props = defineProps<{ field: VectorField, value: unknown }>()
const appConfig = useAppConfig()
const related = computed(() => (props.value && typeof props.value === 'object' && 'id' in props.value ? props.value as VectorRelationValue : null))
const to = computed(() => related.value && props.field.relation
    ? `${appConfig.vector.basePath}/r/${props.field.relation.resource}/${encodeURIComponent(String(related.value.id))}`
    : undefined)
const { t } = useVectorT()
</script>

<template>
    <v-chip v-if="related" size="small" variant="outlined" :to="to" link @click.stop>{{ related.label }}</v-chip>
    <span v-else class="text-disabled">{{ t('common.none') }}</span>
</template>
