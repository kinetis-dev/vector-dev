<script setup lang="ts">
import type { WritingOperation } from '../../composables/useVectorWriting'

// Writing help's menu, under a text input and in an editor's toolbar:
// Rewrite, Shorten, Fix grammar, and Translate to… which lists the
// languages. Emits the operation chosen, with the language for a
// translation.
const props = defineProps<{
    disabled?: boolean
    busy?: boolean
    /** The activator as an icon button, for an editor's toolbar. */
    icon?: boolean
    /** Why the menu cannot be opened now, shown as the activator's title. */
    hint?: string
}>()

const emit = defineEmits<{ run: [operation: WritingOperation, targetLocale: string | null] }>()

const { t } = useVectorT()
const { languageName, languages: languageTags } = useVectorWriting()
const open = ref(false)
const languages = ref(false)

watch(open, (opened) => {
    if (!opened) languages.value = false
})

function run(operation: WritingOperation, targetLocale: string | null = null) {
    open.value = false
    emit('run', operation, targetLocale)
}

const operations: { operation: Exclude<WritingOperation, 'translate'>, label: string, icon: string }[] = [
    { operation: 'rewrite', label: 'ai.writing.rewrite', icon: 'mdi-pencil-outline' },
    { operation: 'shorten', label: 'ai.writing.shorten', icon: 'mdi-arrow-collapse-horizontal' },
    { operation: 'grammar', label: 'ai.writing.grammar', icon: 'mdi-spellcheck' },
]
</script>

<template>
    <v-menu v-model="open" location="bottom start" :disabled="props.disabled || props.busy">
        <template #activator="{ props: activator }">
            <v-btn
                v-if="props.icon"
                v-bind="activator"
                data-ai-writing-menu
                icon="mdi-creation-outline"
                size="small"
                variant="text"
                :loading="props.busy"
                :disabled="props.disabled"
                :title="props.hint ?? t('ai.writing.label')"
                :aria-label="t('ai.writing.label')"
            />
            <v-btn
                v-else
                v-bind="activator"
                data-ai-writing-menu
                prepend-icon="mdi-creation-outline"
                size="small"
                variant="text"
                :loading="props.busy"
                :disabled="props.disabled"
                :title="props.hint"
            >
                {{ t('ai.writing.label') }}
            </v-btn>
        </template>
        <v-list v-if="!languages" density="compact" data-ai-writing-operations>
            <v-list-item
                v-for="item in operations"
                :key="item.operation"
                :data-ai-writing-operation="item.operation"
                :prepend-icon="item.icon"
                :title="t(item.label)"
                @click="run(item.operation)"
            />
            <v-list-item data-ai-writing-operation="translate" prepend-icon="mdi-translate" :title="t('ai.writing.translate')" append-icon="mdi-chevron-right" @click.stop="languages = true" />
        </v-list>
        <v-list v-else density="compact" data-ai-writing-languages>
            <v-list-item prepend-icon="mdi-chevron-left" :title="t('ai.writing.back')" @click.stop="languages = false" />
            <v-list-item
                v-for="tag in languageTags"
                :key="tag"
                :data-ai-writing-language="tag"
                :title="languageName(tag)"
                @click="run('translate', tag)"
            />
        </v-list>
    </v-menu>
</template>
