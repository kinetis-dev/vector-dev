<script setup lang="ts">
import type { VectorField, VectorRelationValue } from '#vector/app/types/vector'

// belongsTo edits one identifier, manyToMany a list; both search the
// target's title on the server, debounced, dropping stale answers. A
// dependent choice list searches with the form's value of the field it
// depends on, waits for one, and drops its choice when that value changes.
// An option shows the target's subtitle and thumbnail when it has them;
// thumbnails come through the API client as data URLs, like image cells.
const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], resource: string, record?: Record<string, unknown>, disabled?: boolean }>()
const model = defineModel<number | string | (number | string)[] | null>()
const multiple = computed(() => props.field.type === 'manyToMany')
const client = useVectorResource(() => props.resource)
const { t, noun } = useVectorT()

const items = ref<VectorRelationValue[]>([])
const target = useVectorResource(() => props.field.relation?.resource ?? props.resource)
const thumbs = reactive<Record<string, string>>({})
const requested = new Set<string>()

function loadThumbs(options: VectorRelationValue[]) {
    for (const option of options) {
        const key = String(option.id)
        if (!option.image || requested.has(key)) continue
        requested.add(key)
        target.fileBlob(option.id, option.image).then(dataUrl).then((url) => { thumbs[key] = url }).catch(() => {})
    }
}

const search = ref('')
const loading = ref(false)
let requestId = 0

const dependsOn = computed(() => props.field.relation?.dependsOn ?? null)
const dependency = computed<string | number | null>(() => {
    if (!dependsOn.value) return null
    const value = props.record?.[dependsOn.value.field]
    const key = value && typeof value === 'object' && 'id' in value ? (value as { id: unknown }).id : value
    if (typeof key === 'number' || (typeof key === 'string' && key !== '')) return key
    return typeof key === 'boolean' ? Number(key) : null
})
const waiting = computed(() => dependsOn.value !== null && dependency.value === null)

async function fetchOptions(query: string, ids: (number | string)[] = []) {
    if (waiting.value && !ids.length) {
        items.value = items.value.filter(i => isSelected(i.id))
        return
    }
    const id = ++requestId
    loading.value = true
    try {
        const depends = dependsOn.value && dependency.value !== null ? { [dependsOn.value.field]: dependency.value } : {}
        const result = await client.options(props.field.name, query, ids, depends)
        if (id === requestId) {
            // keep the selected entries visible while the list changes
            const selected = items.value.filter(i => isSelected(i.id))
            const merged = [...selected, ...result.data.filter(r => !selected.some(s => s.id === r.id))]
            items.value = merged
            loadThumbs(result.data)
        }
    } finally {
        if (id === requestId) loading.value = false
    }
}

function isSelected(id: number | string) {
    return multiple.value ? Array.isArray(model.value) && model.value.includes(id) : model.value === id
}

const debounced = useDebounceFn((q: string) => fetchOptions(q), 250)
watch(search, (q) => { if (typeof q === 'string') debounced(q) })

// Another team, other agents: a choice made for the old value no longer holds.
watch(dependency, (now, before) => {
    if (now === before) return
    if (!multiple.value && model.value !== null && model.value !== undefined) model.value = null
    items.value = []
    fetchOptions('')
})

/** The identifiers the input starts with. */
function initialIds(): (number | string)[] {
    if (multiple.value) return Array.isArray(model.value) ? model.value : []
    return model.value === null || model.value === undefined || Array.isArray(model.value) ? [] : [model.value]
}

// Resolve the labels of an initial value once.
onMounted(async () => {
    const initial = initialIds()
    await fetchOptions('', initial.length ? initial : [])
    if (initial.length) await fetchOptions('')
})
</script>

<template>
    <v-autocomplete
        v-model="model"
        v-model:search="search"
        :label="field.label"
        :items="items"
        item-title="label"
        item-value="id"
        :multiple="multiple"
        :chips="multiple"
        :closable-chips="multiple"
        :loading="loading"
        :rules="rules"
        :error-messages="errorMessages"
        :clearable="field.nullable === true || multiple"
        :disabled="disabled || waiting"
        no-filter
        :hint="waiting ? t('fields.relation.dependsFirst', { label: noun(dependsOn!.label) }) : (field.help ?? undefined)"
        :persistent-hint="waiting || !!field.help"
        :placeholder="t('fields.relation.placeholder')"
    >
        <template #item="{ props: itemProps, item }">
            <v-list-item v-bind="itemProps" :subtitle="(item as VectorRelationValue).subtitle ?? undefined">
                <template v-if="thumbs[String((item as VectorRelationValue).id)]" #prepend>
                    <v-avatar rounded size="32"><v-img :src="thumbs[String((item as VectorRelationValue).id)]" alt="" cover /></v-avatar>
                </template>
            </v-list-item>
        </template>
        <template v-if="multiple" #chip="{ props: chipProps, item }">
            <v-chip v-bind="chipProps" :prepend-avatar="thumbs[String((item as VectorRelationValue).id)]" :title="(item as VectorRelationValue).subtitle ?? undefined" />
        </template>
        <template v-else #selection="{ item }">
            <v-avatar v-if="thumbs[String((item as VectorRelationValue).id)]" rounded size="24" class="me-2"><v-img :src="thumbs[String((item as VectorRelationValue).id)]" alt="" cover /></v-avatar>
            <span>{{ (item as VectorRelationValue).label }}</span>
            <span v-if="(item as VectorRelationValue).subtitle" class="text-medium-emphasis ms-2 vx-relation-subtitle">{{ (item as VectorRelationValue).subtitle }}</span>
        </template>
    </v-autocomplete>
</template>
