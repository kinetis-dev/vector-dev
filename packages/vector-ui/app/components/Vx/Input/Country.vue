<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'
import SelectInput from '#vector/app/components/Vx/Input/Select.vue'

// A select over country codes, each named in the UI's language.
const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string }>()
const model = defineModel<string | number | (string | number)[] | null>()
const names = useVectorCountryNames()
const named = computed(() => withCountryNames(props.field, names.value))
</script>

<template>
    <SelectInput v-model="model" :field="named" :rules="rules" :error-messages="errorMessages" :record="record" :resource="resource" />
</template>
