<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// One option, or several for a multiple select (shown as chips). A
// searchable select types to narrow a long list.
// `disabled` stays undefined when not given, so a disabled v-form still disables the field
const props = withDefaults(defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string, disabled?: boolean }>(), { record: undefined, resource: undefined, disabled: undefined })
const model = defineModel<string | number | (string | number)[] | null>()
const multiple = computed(() => props.field.multiple === true)
// what both inputs share; each is named in the template so Vuetify imports it
const shared = computed(() => ({
    label: props.field.label,
    items: props.field.options ?? [],
    itemTitle: 'label',
    itemValue: 'value',
    multiple: multiple.value,
    chips: multiple.value,
    closableChips: multiple.value,
    rules: props.rules,
    errorMessages: props.errorMessages,
    clearable: props.field.nullable === true,
    hint: props.field.help ?? undefined,
    placeholder: props.field.placeholder ?? undefined,
    persistentHint: !!props.field.help,
    disabled: props.disabled,
}))
</script>

<template>
    <v-autocomplete v-if="field.autocomplete" v-model="model" v-bind="shared" auto-select-first />
    <v-select v-else v-model="model" v-bind="shared" />
</template>
