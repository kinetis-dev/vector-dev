<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'
import CodeEditor from '#vector/app/components/Vx/Input/CodeEditor.vue'

// A JSON column's document edited as text in the code editor. Valid JSON
// holding an object or an array is sent as that document; anything else
// is sent as the text it is, which the server refuses, and is flagged
// here meanwhile.
const props = withDefaults(defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string, disabled?: boolean }>(), { record: undefined, resource: undefined, disabled: undefined })
const model = defineModel<unknown>()
const { t } = useVectorT()
const labelId = useId()
const editor = useTemplateRef<InstanceType<typeof CodeEditor>>('editor')

const format = (value: unknown): string => (value === null || value === undefined ? '' : typeof value === 'string' ? value : JSON.stringify(value, null, 2))
const text = ref(format(model.value))
const invalid = ref(false)
// the value this input last set, so its own update leaves the text as typed
let emitted: unknown = model.value

watch(model, (value) => {
    // a value set from outside (a reset, a loaded record) replaces the text
    if (value === emitted) return
    emitted = value
    text.value = format(value)
    invalid.value = false
})

function parsed(raw: string): unknown {
    if (raw.trim() === '') return null

    try {
        const value: unknown = JSON.parse(raw)

        return value !== null && typeof value === 'object' ? value : raw
    } catch {
        return raw
    }
}

function edit(raw: string) {
    text.value = raw
    const value = parsed(raw)
    invalid.value = typeof value === 'string'
    emitted = value
    model.value = value
}

const messages = computed(() => [...(invalid.value ? [t('fields.json.invalid')] : []), ...props.errorMessages])
</script>

<template>
    <v-input :model-value="model" :rules="rules" :error-messages="messages" :hint="field.help ?? undefined" :persistent-hint="!!field.help" :disabled="props.disabled" class="vx-code-input">
        <template #default="{ isValid, isDisabled, messagesId }">
            <div class="vx-code-frame" :class="{ 'vx-code-frame--error': isValid.value === false }">
                <label :id="labelId" class="d-block text-caption text-medium-emphasis px-3 pt-2" @click="editor?.focus()">{{ field.label }}</label>
                <CodeEditor
                    ref="editor"
                    :model-value="text"
                    language="json"
                    :rows="8"
                    :readonly="field.readonly"
                    :disabled="isDisabled.value"
                    :placeholder="field.placeholder ?? undefined"
                    :labelled-by="labelId"
                    :described-by="messagesId.value"
                    @update:model-value="edit"
                />
            </div>
        </template>
    </v-input>
</template>
