<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'
import { normalizeTags } from '#vector/app/utils/internal/repeater'

// Free-text tags as chips: typed and confirmed with Enter (or a comma),
// picked from the field's suggestions, or pasted. The list is kept as the
// server stores it: trimmed, without empty tags, each at most once
// ignoring case. A suggestion never restricts what a tag may be.
const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string, disabled?: boolean }>()
const model = defineModel<string[] | null>()

const { t } = useVectorT()
const maxLength = computed(() => props.field.tags?.maxLength ?? 50)
const maxTags = computed(() => props.field.tags?.maxTags ?? 20)
const tags = computed(() => (Array.isArray(model.value) ? model.value : []))

const limits = (value: unknown) => {
    const list = Array.isArray(value) ? value : []
    if (list.some(tag => typeof tag === 'string' && tag.trim().length > maxLength.value)) return t('fields.rules.maxLength', { max: maxLength.value })
    if (list.length > maxTags.value) return t('fields.tags.tooMany', { max: maxTags.value })
    return true
}

function update(value: unknown) {
    const list = normalizeTags(Array.isArray(value) ? value : [])
    model.value = list.length === 0 && props.field.nullable === true ? null : list
}
</script>

<template>
    <v-combobox
        :model-value="tags"
        :label="field.label"
        :items="field.tags?.suggestions ?? []"
        :rules="[...rules, limits]"
        :error-messages="errorMessages"
        :disabled="disabled"
        :hint="field.help ?? t('fields.tags.hint')"
        :placeholder="field.placeholder ?? undefined"
        :delimiters="[',']"
        multiple
        chips
        closable-chips
        hide-no-data
        persistent-hint
        @update:model-value="update"
    />
</template>
