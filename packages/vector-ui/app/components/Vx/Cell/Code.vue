<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'
import CodeEditor from '#vector/app/components/Vx/Input/CodeEditor.vue'

// Source text: on a record, highlighted in a read-only editor; in a list,
// its first line in monospace, shortened.
const props = defineProps<{ field: VectorField, value: unknown, context: 'index' | 'show' }>()
const text = computed(() => typeof props.value === 'string' ? props.value : '')
const firstLine = computed(() => {
    const lines = text.value.split('\n').map(line => line.trim()).filter(line => line !== '')
    return lines.length > 1 ? `${lines[0]} …` : lines[0] ?? ''
})
const { t } = useVectorT()
const labelId = useId()
</script>

<template>
    <span v-if="text === ''" class="text-disabled">{{ t('common.none') }}</span>
    <div v-else-if="context === 'show'" class="vx-code-view rounded">
        <span :id="labelId" class="d-sr-only">{{ field.label }}</span>
        <CodeEditor :model-value="text" :language="field.code?.language ?? 'plain'" :line-numbers="field.code?.lineNumbers ?? true" :rows="field.code?.rows ?? 10" readonly :labelled-by="labelId" />
    </div>
    <code v-else class="text-truncate d-inline-block font-monospace" style="max-width: 24rem" dir="ltr" :title="text">{{ firstLine }}</code>
</template>

<style>
.vx-code-view { border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); overflow: hidden; }
</style>
