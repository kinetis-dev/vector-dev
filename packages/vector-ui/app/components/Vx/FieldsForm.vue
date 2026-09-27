<script setup lang="ts">
import type { VectorField, VectorFieldState } from '#vector/app/types/vector'

// A block of inputs for a loose field list — an action's dialog, a
// settings group — sharing the record form's registry, rules and 422
// mapping. The owner holds the values and the server errors.
const props = defineProps<{
    fields: VectorField[]
    values: Record<string, unknown>
    serverErrors: Record<string, string[]>
    resource?: string
}>()

const emit = defineEmits<{ clear: [name: string] }>()
const { inputFor } = useVectorInputs()
const wide = (field: VectorField) => ['markdown', 'richtext', 'text', 'json'].includes(field.type)

// Conditions (visibleWhen, requiredWhen, disabledWhen) follow the values as they are edited.
const states = computed<Record<string, VectorFieldState>>(() => Object.fromEntries(props.fields.map(f => [f.name, fieldState(f, props.values)])))
const shownFields = computed(() => props.fields.filter(f => states.value[f.name]?.visible !== false))

function set(name: string, value: unknown) {
    props.values[name] = value
    emit('clear', name)
}
</script>

<template>
    <v-row density="compact">
        <v-col v-for="field in shownFields" :key="field.name" cols="12" :md="wide(field) || fields.length === 1 ? 12 : 6">
            <component
                :is="inputFor(field)"
                :model-value="values[field.name]"
                :field="field"
                :disabled="states[field.name]?.disabled === true"
                :rules="rulesFor(field, 'create', states[field.name])"
                :error-messages="serverErrors[field.name] ?? []"
                :record="values"
                :resource="resource ?? ''"
                @update:model-value="(v: unknown) => set(field.name, v)"
            />
        </v-col>
    </v-row>
</template>
