<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'
import CodeEditor from '#vector/app/components/Vx/Input/CodeEditor.vue'

// Source text in a code editor highlighting the field's language. An
// emptied editor is no value where the field may be empty.
const props = withDefaults(defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string, disabled?: boolean }>(), { record: undefined, resource: undefined, disabled: undefined })
const model = defineModel<string | null>()
const labelId = useId()
const editor = useTemplateRef<InstanceType<typeof CodeEditor>>('editor')

function edit(text: string) {
    model.value = text === '' && props.field.nullable === true ? null : text
}
</script>

<template>
    <v-input :model-value="model" :rules="rules" :error-messages="errorMessages" :hint="field.help ?? undefined" :persistent-hint="!!field.help" :disabled="props.disabled" class="vx-code-input">
        <template #default="{ isValid, isDisabled, messagesId }">
            <div class="vx-code-frame" :class="{ 'vx-code-frame--error': isValid.value === false }">
                <label :id="labelId" class="d-block text-caption text-medium-emphasis px-3 pt-2" @click="editor?.focus()">{{ field.label }}</label>
                <CodeEditor
                    ref="editor"
                    :model-value="model ?? ''"
                    :language="field.code?.language ?? 'plain'"
                    :line-numbers="field.code?.lineNumbers ?? true"
                    :rows="field.code?.rows ?? 10"
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
