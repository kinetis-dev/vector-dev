<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'
import SelectCell from '#vector/app/components/Vx/Cell/Select.vue'

// A country, or several, shown as a select is, named in the UI's language.
const props = defineProps<{ field: VectorField, value: unknown }>()
const names = useVectorCountryNames()
const named = computed(() => withCountryNames(props.field, names.value))
</script>

<template>
    <SelectCell :field="named" :value="value" />
</template>
