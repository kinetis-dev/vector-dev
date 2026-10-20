<script setup lang="ts">
import type { VectorField, VectorRecord, VectorRelationValue, VectorResource } from '#vector/app/types/vector'

// One collection of a record (hasMany or manyToMany) as a panel under
// its show page: the related records as a server-paged table in the
// target's own columns, and the changes the relation allows here —
// creating a child with the parent pre-filled (hasMany), attaching and
// detaching existing records (manyToMany).
const props = defineProps<{
    owner: VectorResource
    record: VectorRecord
    field: VectorField
    canUpdate: boolean
}>()

const emit = defineEmits<{ changed: [] }>()

const { t, noun } = useVectorT()
const appConfig = useAppConfig()
const { resource: find } = useVectorSchema()
const notifications = useVectorNotifications()
const { confirm } = useConfirm()
const client = useVectorResource(() => props.owner.slug)

const target = computed(() => props.field.relation ? find(props.field.relation.resource) : undefined)
const inverse = computed(() => props.field.relation?.inverse ?? null)
const columns = computed<VectorField[]>(() => (target.value?.table.columns ?? [])
    .filter(name => name !== inverse.value)
    .map(name => target.value!.fields.find(f => f.name === name))
    .filter((f): f is VectorField => !!f)
    .slice(0, 6))
const manyToMany = computed(() => props.field.type === 'manyToMany')
const canAttach = computed(() => manyToMany.value && props.canUpdate && !props.field.readonly && props.field.views.includes('update'))
const canCreate = computed(() => props.field.type === 'hasMany' && !!inverse.value && target.value?.permissions.create === true
    && target.value.fields.some(f => f.name === inverse.value))

const headers = computed(() => [
    ...columns.value.map(f => ({ title: f.label, key: f.name, sortable: f.sortable })),
    ...(canAttach.value ? [{ title: '', key: '_actions', sortable: false, align: 'end' as const }] : []),
])

// --- the related page ---------------------------------------------------
const items = ref<VectorRecord[]>([])
/** null when the target resource is not counted (withoutCount): previous and next only */
const total = ref<number | null>(0)
const hasMore = ref(false)
const page = ref(1)
const perPage = ref(10)
const sortBy = ref<{ key: string, order: 'asc' | 'desc' }[]>([])
const loading = ref(false)

async function load() {
    loading.value = true

    try {
        const result = await client.related(props.record.id, props.field.name, {
            page: page.value, perPage: perPage.value,
            sort: sortBy.value.map(s => ({ field: s.key, order: s.order })),
        })
        items.value = result.data
        total.value = result.total
        hasMore.value = result.hasMore ?? false
    } catch (error) {
        notifications.error(vectorErrorMessage(error))
    } finally {
        loading.value = false
    }
}

watch(() => props.record.id, () => { page.value = 1; load() })
// the table resets the page it pages itself on a new sort; an uncounted panel pages here
watch(sortBy, () => { if (total.value === null) page.value = 1 }, { deep: true, flush: 'sync' })

function open(item: VectorRecord) {
    if (target.value) navigateTo(`${appConfig.vector.basePath}/r/${target.value.slug}/${encodeURIComponent(String(item.id))}`)
}

// --- manyToMany: attach and detach ---------------------------------------
const attaching = ref(false)
const choice = ref<(number | string)[]>([])
const options = ref<VectorRelationValue[]>([])
const searching = ref(false)
const saving = ref(false)

const search = useDebounceFn(async (term: string) => {
    searching.value = true
    try {
        options.value = (await client.options(props.field.name, term ?? '')).data
    } catch (error) {
        notifications.error(vectorErrorMessage(error))
    } finally {
        searching.value = false
    }
}, 250)

function startAttach() {
    choice.value = []
    attaching.value = true
    search('')
}

async function attach() {
    if (!choice.value.length) return
    saving.value = true

    try {
        await client.attach(props.record.id, props.field.name, choice.value)
        notifications.success(t('record.panel.attached', { count: choice.value.length, label: label(choice.value.length) }))
        attaching.value = false
        await load()
        emit('changed')
    } catch (error) {
        notifications.error(vectorErrorMessage(error))
    } finally {
        saving.value = false
    }
}

async function detach(item: VectorRecord) {
    const title = String(item[target.value?.titleField ?? 'id'] ?? `#${item.id}`)
    if (!await confirm({
        title: t('record.panel.detachTitle', { title }),
        text: t('record.panel.detachText', { target: target.value ? noun(target.value.labelSingular) : t('record.panel.record'), owner: noun(props.owner.labelSingular) }),
        confirmText: t('record.panel.detach'),
        destructive: true,
    })) return

    try {
        await client.detach(props.record.id, props.field.name, [item.id])
        notifications.success(t('record.panel.detached', { title }))
        await load()
        emit('changed')
    } catch (error) {
        notifications.error(vectorErrorMessage(error))
    }
}

// --- hasMany: create a child under this record ---------------------------
const creating = ref(false)
const preset = computed(() => inverse.value ? { [inverse.value]: { id: props.record.id } } : {})

async function created() {
    creating.value = false
    await load()
    emit('changed')
}

function label(n: number): string {
    return target.value ? noun(n === 1 ? target.value.labelSingular : target.value.label) : t('record.panel.records')
}
</script>

<template>
    <v-card v-if="target" class="mt-4">
        <v-card-title class="d-flex align-center ga-2 text-subtitle-1">
            <v-icon v-if="target.icon" :icon="target.icon" size="small" />
            {{ field.label }}
            <v-chip v-if="total !== null" size="x-small" variant="tonal">{{ total }}</v-chip>
            <v-spacer />
            <v-btn v-if="canAttach" size="small" variant="tonal" prepend-icon="mdi-link-plus" @click="startAttach">{{ t('record.panel.attach') }}</v-btn>
            <v-btn v-if="canCreate" size="small" variant="tonal" color="primary" prepend-icon="mdi-plus" @click="creating = true">{{ t('record.newTitle', { label: noun(target.labelSingular) }) }}</v-btn>
        </v-card-title>

        <v-data-table-server
            v-model:items-per-page="perPage"
            v-model:sort-by="sortBy"
            :page="total === null ? 1 : page"
            :headers="headers"
            :items="items"
            :items-length="total ?? items.length"
            :loading="loading"
            :items-per-page-options="[5, 10, 25, 50]"
            item-value="id"
            density="compact"
            hover
            @update:page="(p: number) => { if (total !== null) page = p }"
            @update:options="load"
            @click:row="(_: Event, { item }: { item: VectorRecord }) => open(item)"
        >
            <template v-for="column in columns" :key="column.name" #[`item.${column.name}`]="{ item }">
                <VxCell :field="column" :value="item[column.name]" :record="item" :resource="target" context="index" />
            </template>
            <template #[`item._actions`]="{ item }">
                <v-btn icon="mdi-link-off" size="small" variant="text" :aria-label="t('record.panel.detachLabel', { title: String(item[target.titleField] ?? item.id) })" @click.stop="detach(item)" />
            </template>
            <template v-if="total === null" #bottom>
                <VxListPager
                    :per-page="perPage"
                    :per-page-options="[5, 10, 25, 50]"
                    :page="page"
                    :has-previous="page > 1"
                    :has-next="hasMore"
                    :disabled="loading"
                    @update:per-page="(n: number) => { page = 1; perPage = n }"
                    @previous="page--; load()"
                    @next="page++; load()"
                />
            </template>
            <template #no-data>
                <div class="text-medium-emphasis py-4">{{ t('record.panel.empty', { label: noun(target.label) }) }}</div>
            </template>
        </v-data-table-server>

        <v-dialog v-model="attaching" max-width="520">
            <v-card :title="t('record.panel.attachTitle', { label: noun(target.label) })">
                <v-card-text>
                    <v-autocomplete
                        v-model="choice"
                        :items="options"
                        item-title="label"
                        item-value="id"
                        :label="target.label"
                        :loading="searching"
                        multiple
                        chips
                        closable-chips
                        no-filter
                        autofocus
                        @update:search="search"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" :disabled="saving" @click="attaching = false">{{ t('common.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="saving" :disabled="!choice.length" @click="attach">{{ t('record.panel.attachCount', { count: choice.length || '' }) }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="creating" max-width="900">
            <v-card :title="t('record.newTitle', { label: noun(target.labelSingular) })">
                <VxResourceForm v-if="creating" :resource="target" operation="create" :preset="preset" @saved="created" @cancel="creating = false" />
            </v-card>
        </v-dialog>
    </v-card>
</template>
