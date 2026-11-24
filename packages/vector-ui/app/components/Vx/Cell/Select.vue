<script setup lang="ts">
import type { VectorField, VectorSelectOption } from '#vector/app/types/vector'

// A select's value, or a multiple one's values, by their labels: as
// chips when the field is a badge (or an option has a colour), as text
// otherwise.
const props = defineProps<{ field: VectorField, value: unknown }>()
const { t } = useVectorT()

const chosen = computed<VectorSelectOption[]>(() => {
    const values = Array.isArray(props.value) ? props.value : props.value === null || props.value === undefined ? [] : [props.value]

    return values.map(value => props.field.options?.find(o => String(o.value) === String(value)) ?? { value: String(value), label: String(value), color: null })
})
const chips = computed(() => props.field.badge === true || chosen.value.some(o => o.color !== null))
</script>

<template>
    <span v-if="chosen.length === 0" class="text-disabled">{{ t('common.none') }}</span>
    <span v-else-if="chips" class="d-inline-flex flex-wrap align-center ga-1">
        <v-chip v-for="option in chosen" :key="String(option.value)" size="small" :color="option.color ?? undefined" variant="tonal" label>
            {{ option.label }}
        </v-chip>
    </span>
    <span v-else>{{ chosen.map(o => o.label).join(', ') }}</span>
</template>
