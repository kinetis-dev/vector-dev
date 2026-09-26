<script setup lang="ts">
import type { VectorResource } from '#vector/app/types/vector'

// Which index columns show, and in what order. Persisted per user and
// resource through the preferences store.
const props = defineProps<{ resource: VectorResource }>()
const model = defineModel<string[]>({ required: true })

const { t } = useVectorT()
const open = ref(false)
const draft = ref<string[]>([])
const available = computed(() => props.resource.table.columns)

function start() {
    draft.value = [...model.value.filter(c => available.value.includes(c)), ...available.value.filter(c => !model.value.includes(c))]
    open.value = true
}

const visible = (name: string) => model.value.includes(name)
const shown = ref<Record<string, boolean>>({})

watch(open, (o) => {
    if (o) shown.value = Object.fromEntries(available.value.map(c => [c, visible(c)]))
})

function move(index: number, delta: number) {
    const target = index + delta
    if (target < 0 || target >= draft.value.length) return
    const next = [...draft.value]
    const [item] = next.splice(index, 1)
    next.splice(target, 0, item!)
    draft.value = next
}

function apply() {
    const chosen = draft.value.filter(c => shown.value[c])
    model.value = chosen.length ? chosen : [available.value[0]!]
    open.value = false
}

function reset() {
    model.value = [...available.value]
    open.value = false
}

const label = (name: string) => props.resource.fields.find(f => f.name === name)?.label ?? name
</script>

<template>
    <v-btn size="small" variant="text" icon="mdi-table-column-plus-after" :aria-label="t('list.columns.title')" @click="start" />
    <v-dialog v-model="open" max-width="420">
        <v-card :title="t('list.columns.title')">
            <v-card-text>
                <v-list density="compact">
                    <v-list-item v-for="(name, index) in draft" :key="name" :title="label(name)">
                        <template #prepend>
                            <v-checkbox-btn v-model="shown[name]" />
                        </template>
                        <template #append>
                            <v-btn icon="mdi-chevron-up" size="x-small" variant="text" :disabled="index === 0" :aria-label="t('list.columns.moveUp')" @click="move(index, -1)" />
                            <v-btn icon="mdi-chevron-down" size="x-small" variant="text" :disabled="index === draft.length - 1" :aria-label="t('list.columns.moveDown')" @click="move(index, 1)" />
                        </template>
                    </v-list-item>
                </v-list>
            </v-card-text>
            <v-card-actions>
                <v-btn variant="text" @click="reset">{{ t('list.columns.reset') }}</v-btn>
                <v-spacer />
                <v-btn variant="text" @click="open = false">{{ t('common.cancel') }}</v-btn>
                <v-btn color="primary" @click="apply">{{ t('common.apply') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
