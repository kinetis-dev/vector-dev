<script setup lang="ts">
import { fieldProps, type VectorAction, type VectorField, type VectorFilterGroup, type VectorFilterQuery, type VectorFilters, type VectorListState, type VectorPage, type VectorRecord, type VectorResource, type VectorSelection, type VectorSort, type VectorSavedView } from '#vector/app/types/vector'

// The list view: a server-driven table whose every option — page, size,
// sort, filters, search, grouping, columns — becomes one list request,
// with saved views, per-user column state, summaries, bulk operations,
// actions and export on top.
const props = defineProps<{ resource: VectorResource }>()

const appConfig = useAppConfig()
const { t } = useVectorT()
const notifications = useVectorNotifications()
const { schema } = useVectorSchema()
const { confirm } = useConfirm()
const client = useVectorResource(() => props.resource.slug)
const preferences = useVectorPreferences()
await preferences.load()

// --- list state -------------------------------------------------------
const columns = ref<string[]>(preferences.get<string[]>(`columns.${props.resource.slug}`)?.filter(c => props.resource.table.columns.includes(c)) ?? [...props.resource.table.columns])
// The filter: conditions that must all hold, "any of" groups of
// alternatives, and whatever a saved view holds that the bar cannot edit.
const filters = ref<VectorFilters>({})
const groups = ref<VectorFilterGroup[]>([])
const advanced = ref<VectorFilterQuery[]>([])
const search = ref<string | null>('')
const term = computed(() => (search.value ?? '').trim())
const group = ref<string | null>(null)
const trashed = ref<'without' | 'only' | 'with'>('without')
const summaries = ref(props.resource.table.summaries.length > 0)
const page = ref(1)
const itemsPerPage = ref(props.resource.table.perPage)
const sortBy = ref<{ key: string, order?: boolean | 'asc' | 'desc' }[]>(props.resource.table.defaultSort.map(s => ({ key: s.field, order: s.order })))
const activeView = ref<number | null>(null)

const fields = computed<VectorField[]>(() => columns.value
    .map(name => props.resource.fields.find(f => f.name === name))
    .filter((f): f is VectorField => f !== undefined))

/** Numbers and money align right, a computed one shown as either too. */
const numeric = (field: VectorField) => ['number', 'money'].includes(field.type === 'computed' ? field.computed?.as ?? '' : field.type)

const headers = computed(() => fields.value.map(field => ({
    key: field.name,
    title: field.label,
    sortable: field.sortable,
    align: (numeric(field) ? 'end' : 'start') as 'start' | 'end',
    nowrap: true,
})))

/** The filter as the list grammar reads it. */
const wireFilters = computed<VectorFilterQuery>(() => {
    const all: VectorFilterQuery = {}
    for (const [name, ops] of Object.entries(filters.value)) all[name] = { ...ops }
    const extra: VectorFilterQuery[] = [
        ...groups.value.filter(g => g.length > 0).map(g => ({ or: g.map(alternative => ({ ...alternative })) })),
        ...advanced.value,
    ]
    if (extra.length === 1 && Array.isArray(extra[0]!.or)) all.or = extra[0]!.or
    else if (extra.length > 0) all.and = extra
    if (props.resource.softDelete && trashed.value !== 'without') all.trashed = trashed.value
    return all
})

/** The list grammar as a view stores it and a bulk selection sends it. */
const state = computed<VectorListState>(() => ({
    filter: wireFilters.value,
    q: term.value || undefined,
    sort: sortBy.value.map(s => (s.order === 'desc' ? '-' : '') + s.key).join(',') || undefined,
    group: group.value ?? undefined,
    columns: columns.value.join(','),
    perPage: itemsPerPage.value,
    summaries: summaries.value,
}))

// --- loading ----------------------------------------------------------
const items = ref<VectorRecord[]>([])
const total = ref(0)
const summaryValues = ref<Record<string, number | null>>({})
const loading = ref(false)
let requestId = 0

async function load() {
    const id = ++requestId
    loading.value = true

    try {
        const sort: VectorSort[] = sortBy.value.map(s => ({ field: s.key, order: s.order === 'desc' ? 'desc' : 'asc' }))
        const result: VectorPage = await client.list({
            page: page.value, perPage: itemsPerPage.value, sort, filter: rawFilters(), q: term.value || undefined,
            group: group.value ?? undefined, summaries: summaries.value, columns: columns.value,
        })

        if (id !== requestId) return

        items.value = result.data
        total.value = result.total
        summaryValues.value = result.summaries ?? {}
    } catch (error) {
        notifications.error(vectorErrorMessage(error))
    } finally {
        if (id === requestId) loading.value = false
    }
}

/** The wire form of the filters, `trashed` as the server reads it. */
function rawFilters(): VectorFilterQuery {
    return wireFilters.value
}

const debouncedLoad = useDebounceFn(() => { page.value = 1; load() }, 300)
watch(search, () => debouncedLoad())
watch([filters, groups, advanced, group, trashed, summaries], () => { page.value = 1; load() }, { deep: true })
watch(columns, (c) => { preferences.put({ [`columns.${props.resource.slug}`]: c }).catch(() => undefined); load() })

function onOptions() {
    load()
}

// --- views ------------------------------------------------------------
const viewMenu = ref<{ views: VectorSavedView[] } | null>(null)

/**
 * A stored filter back into what the bar edits: its conditions, its
 * "any of" groups of plain alternatives, and anything deeper kept as it
 * is. Conditions on fields this account cannot see are dropped.
 */
function decodeFilter(filter: VectorFilterQuery, known: Set<string>) {
    const out = { filters: {} as VectorFilters, groups: [] as VectorFilterGroup[], advanced: [] as VectorFilterQuery[], trashed: 'without' as 'without' | 'only' | 'with' }
    const plain = (node: VectorFilterQuery): node is VectorFilters => Object.entries(node).every(([k, v]) => k !== 'or' && k !== 'and' && k !== 'trashed' && typeof v === 'object' && !Array.isArray(v))

    for (const [key, spec] of Object.entries(filter)) {
        if (key === 'trashed') {
            const value = typeof spec === 'string' ? spec : (spec as Record<string, string> | undefined)?.eq ?? (spec as Record<string, string> | undefined)?.['']
            out.trashed = (value === 'only' || value === 'with' ? value : 'without')
        } else if (key === 'or' && Array.isArray(spec)) {
            if (spec.every(plain)) out.groups.push(spec.map(a => Object.fromEntries(Object.entries(a).filter(([name]) => known.has(name)))).filter(a => Object.keys(a).length > 0))
            else out.advanced.push({ or: spec })
        } else if (key === 'and' && Array.isArray(spec)) {
            for (const child of spec) {
                const inner = decodeFilter(child, known)
                for (const [name, ops] of Object.entries(inner.filters)) out.filters[name] = { ...(out.filters[name] ?? {}), ...ops }
                out.groups.push(...inner.groups)
                out.advanced.push(...inner.advanced)
            }
        } else if (known.has(key) && spec !== undefined && !Array.isArray(spec)) {
            out.filters[key] = typeof spec === 'string' ? { eq: spec } : { ...spec }
        }
    }

    out.groups = out.groups.filter(g => g.length > 0)
    return out
}

function applyView(view: VectorSavedView | null) {
    activeView.value = view?.id ?? null
    const s = view?.state ?? {}
    // A shared view may name fields this account cannot see; those parts are dropped, not sent.
    const known = new Set(props.resource.fields.map(f => f.name))
    const decoded = decodeFilter(s.filter ?? {}, known)
    trashed.value = decoded.trashed
    filters.value = decoded.filters
    groups.value = decoded.groups
    advanced.value = decoded.advanced
    search.value = s.q ?? ''
    group.value = s.group && known.has(s.group) ? s.group : null
    const sorts = s.sort ? s.sort.split(',').map(e => ({ key: e.replace(/^-/, ''), order: e.startsWith('-') ? 'desc' as const : 'asc' as const })).filter(x => known.has(x.key)) : []
    sortBy.value = sorts.length ? sorts : props.resource.table.defaultSort.map(x => ({ key: x.field, order: x.order }))
    if (s.columns) columns.value = s.columns.split(',').filter(c => props.resource.table.columns.includes(c))
    itemsPerPage.value = s.perPage ?? props.resource.table.perPage
    summaries.value = s.summaries ?? summaries.value
    page.value = 1
}

onMounted(() => {
    // your own default, else the one set for everyone
    const preset = viewMenu.value?.views?.find(v => v.isDefault) ?? viewMenu.value?.views?.find(v => v.resourceDefault)
    if (preset) applyView(preset)
})

// --- selection, bulk operations, actions -----------------------------
const selected = ref<(number | string)[]>([])
const allMatching = ref(false)
watch(items, () => { selected.value = selected.value.filter(id => items.value.some(i => i.id === id)); if (!selected.value.length) allMatching.value = false })

const selection = computed<VectorSelection>(() => allMatching.value
    ? { all: { filter: rawFilters(), q: term.value || undefined } }
    : { ids: selected.value })
const selectionCount = computed(() => allMatching.value ? total.value : selected.value.length)
const indexActions = computed(() => props.resource.actions.filter(a => a.showOnIndex && !a.standalone))
const standaloneActions = computed(() => props.resource.actions.filter(a => a.showOnIndex && a.standalone))
const dialogAction = ref<VectorAction | null>(null)
const bulkEditing = ref(false)

async function operation(op: 'delete' | 'restore' | 'forceDelete') {
    const n = selectionCount.value
    const noun = (n === 1 ? props.resource.labelSingular : props.resource.label).toLowerCase()
    const titles = { delete: t('list.operation.deleteTitle', { count: n, noun }), restore: t('list.operation.restoreTitle', { count: n, noun }), forceDelete: t('list.operation.forceDeleteTitle', { count: n, noun }) }

    if (!await confirm({ title: titles[op], text: op === 'restore' ? undefined : (props.resource.softDelete && op === 'delete' ? t('list.operation.toTrash') : t('list.operation.irreversible')), confirmText: op === 'restore' ? t('common.restore') : t('common.delete'), destructive: op !== 'restore' })) return

    try {
        const { affected } = await client.batch(op, selection.value)
        notifications.success(t(op === 'restore' ? 'list.operation.restored' : 'list.operation.deleted', { count: affected, noun: affected === 1 ? props.resource.labelSingular.toLowerCase() : props.resource.label.toLowerCase() }))
        selected.value = []
        allMatching.value = false
        await load()
    } catch (error) {
        notifications.error(vectorErrorMessage(error))
    }
}

async function exportCsv() {
    try {
        const queued = await client.exportCsv({ sort: sortBy.value.map(s => ({ field: s.key, order: s.order === 'desc' ? 'desc' : 'asc' })), filter: rawFilters(), q: term.value || undefined, columns: columns.value })

        if (queued) {
            notifications.success(t('list.exportQueued'))
            await navigateTo(`${appConfig.vector.basePath}/jobs/${queued.jobId}`)
        }
    } catch (error) {
        notifications.error(vectorErrorMessage(error))
    }
}

const importing = ref(false)

function actionDone() {
    dialogAction.value = null
    selected.value = []
    allMatching.value = false
    load()
}

// --- rendering helpers ------------------------------------------------
const groupField = computed(() => props.resource.fields.find(f => f.name === group.value) ?? null)

function formatSummary(field: VectorField, value: number | null): string {
    if (value === null) return t('common.none')
    if (field.type === 'money') {
        const p = fieldProps(field)
        return formatMoney(Math.round(value), typeof p.currency === 'string' ? p.currency : (typeof p.currencyField === 'string' ? String(items.value[0]?.[p.currencyField] ?? 'EUR') : 'EUR'))
    }
    return formatNumber(value, field.summary === 'avg' ? 2 : undefined)
}

function open(record: VectorRecord) {
    navigateTo(`${appConfig.vector.basePath}/r/${props.resource.slug}/${encodeURIComponent(String(record.id))}`)
}

// --- inline editing ---------------------------------------------------
// A click on an inline-editable cell opens its editor; a changed cell keeps
// its editor (highlighted) until the changeset is saved or discarded, so
// nothing has to render a value the list has not loaded.
const { inputFor } = useVectorInputs()
const pending = reactive<Record<string, Record<string, unknown>>>({})
const opened = reactive<Record<string, true>>({})
const cellErrors = reactive<Record<string, string>>({})
const savingChanges = ref(false)
const cellKey = (id: string | number, field: string) => `${id}\u0000${field}`
const pendingCount = computed(() => Object.values(pending).reduce((n, values) => n + Object.keys(values).length, 0))

function editValue(item: VectorRecord, field: VectorField): unknown {
    const value = item[field.name]
    return field.type === 'belongsTo' && value && typeof value === 'object' && 'id' in value ? (value as { id: unknown }).id : value
}

function startEdit(item: VectorRecord, field: VectorField) {
    if (item._trashed === true) return
    closeUnchanged()
    opened[cellKey(item.id, field.name)] = true
}

/** Closes every open editor whose value was left as loaded. */
function closeUnchanged() {
    for (const key of Object.keys(opened)) {
        const [id, name] = key.split('\u0000') as [string, string]
        if (!(pending[id] && name in pending[id])) delete opened[key]
    }
}

function isEditing(item: VectorRecord, field: VectorField): boolean {
    return opened[cellKey(item.id, field.name)] === true
}

function setCell(item: VectorRecord, field: VectorField, value: unknown) {
    delete cellErrors[cellKey(item.id, field.name)]
    const row = (pending[String(item.id)] ??= {})
    if (value === editValue(item, field)) delete row[field.name]
    else row[field.name] = value
    if (!Object.keys(row).length) delete pending[String(item.id)]
}

function discardChanges() {
    for (const key of Object.keys(pending)) delete pending[key]
    for (const key of Object.keys(opened)) delete opened[key]
    for (const key of Object.keys(cellErrors)) delete cellErrors[key]
}

async function saveChanges() {
    const ids = Object.keys(pending)
    const list = ids.map((id) => {
        const item = items.value.find(i => String(i.id) === id)
        return { id: item?.id ?? id, version: item?._version as number | undefined, values: pending[id]! }
    })
    savingChanges.value = true
    for (const key of Object.keys(cellErrors)) delete cellErrors[key]

    try {
        await client.changes(list)
        notifications.success(t('list.inline.saved', { count: list.length }, list.length))
        discardChanges()
        await load()
    } catch (error) {
        const failure = error as { status?: number, statusCode?: number, data?: { errors?: { path: (string | number)[], message: string }[] } } | null
        const status = failure?.status ?? failure?.statusCode

        if (status === 422 && Array.isArray(failure?.data?.errors)) {
            for (const violation of failure.data.errors) {
                const [, index, field] = violation.path
                const change = typeof index === 'number' ? list[index] : undefined
                if (change && typeof field === 'string' && field in change.values) cellErrors[cellKey(change.id, field)] = violation.message
                else notifications.error(field ? `${String(field)}: ${violation.message}` : violation.message)
            }
        } else if (status === 409) {
            notifications.error(t('list.inline.conflict'))
        } else {
            notifications.error(vectorErrorMessage(error))
        }
    } finally {
        savingChanges.value = false
    }
}

async function mayLeave(): Promise<boolean> {
    if (!pendingCount.value) return true
    return await confirm({ title: t('list.inline.leaveTitle', { count: pendingCount.value }, pendingCount.value), text: t('list.inline.leaveText'), confirmText: t('list.inline.discard'), destructive: true })
}

// Another resource's list is the same route with another slug, so an update guards too.
onBeforeRouteLeave(mayLeave)
onBeforeRouteUpdate((to, from) => to.path === from.path || mayLeave())

function warnBeforeUnload(event: BeforeUnloadEvent) {
    if (pendingCount.value) event.preventDefault()
}
onMounted(() => window.addEventListener('beforeunload', warnBeforeUnload))
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload))
</script>

<template>
    <div>
        <v-row v-if="resource.widgets.length" density="compact" class="mb-2">
            <v-col v-for="widget in resource.widgets" :key="widget.name" cols="12" :md="widget.width">
                <VxWidgetCard :widget="widget" :load="(range: string | undefined) => client.widget(widget.name, range).then(r => r.data)" />
            </v-col>
        </v-row>
        <div class="d-flex align-center flex-wrap ga-2 mb-2">
            <v-text-field v-model="search" :placeholder="resource.table.search.length ? t('list.search', { label: resource.label.toLowerCase() }) : t('list.noSearch')" :disabled="resource.table.search.length === 0" prepend-inner-icon="mdi-magnify" density="compact" hide-details clearable :aria-label="t('common.search')" style="max-width: 320px" />
            <VxListViewMenu ref="viewMenu" :resource="resource" :current="state" :active-id="activeView" @apply="applyView" @update:active-id="(id: number | null) => activeView = id" />
            <v-select v-if="resource.table.groupable.length" v-model="group" :items="[{ title: t('list.noGrouping'), value: null }, ...resource.table.groupable.map(g => ({ title: resource.fields.find(f => f.name === g)?.label ?? g, value: g }))]" :label="t('list.groupBy')" density="compact" hide-details style="max-width: 200px" />
            <v-select v-if="resource.softDelete" v-model="trashed" :items="[{ title: t('list.trash.active'), value: 'without' }, { title: t('list.trash.only'), value: 'only' }, { title: t('list.trash.with'), value: 'with' }]" density="compact" hide-details :aria-label="t('list.trash.label')" style="max-width: 180px" prepend-inner-icon="mdi-delete-clock-outline" />
            <v-spacer />
            <v-btn v-for="action in standaloneActions" :key="action.name" size="small" variant="tonal" :prepend-icon="action.icon ?? undefined" @click="dialogAction = action">{{ action.label }}</v-btn>
            <v-btn v-if="resource.permissions.export" size="small" variant="text" icon="mdi-download-outline" :aria-label="t('list.exportCsv')" @click="exportCsv" />
            <v-btn v-if="(resource.permissions.create || resource.permissions.update) && schema?.capabilities.queue" size="small" variant="text" icon="mdi-upload-outline" :aria-label="t('list.importCsv')" @click="importing = true" />
            <VxListColumnManager v-model="columns" :resource="resource" />
        </div>
        <VxListFilterBar v-model="filters" v-model:groups="groups" v-model:advanced="advanced" :resource="resource" class="mb-2" />

        <VxListBulkBar
            v-if="selected.length"
            :resource="resource"
            :selected="selected.length"
            :total="total"
            :all-matching="allMatching"
            :trashed-only="trashed === 'only'"
            :actions="indexActions"
            @update:all-matching="(v: boolean) => allMatching = v"
            @clear="selected = []; allMatching = false"
            @operation="operation"
            @action="(a: VectorAction) => dialogAction = a"
            @edit="bulkEditing = true"
        />

        <VxListBulkEdit v-if="bulkEditing" :resource="resource" :selection="selection" :count="selectionCount" @done="bulkEditing = false; actionDone()" @cancel="bulkEditing = false" />

        <v-card v-if="pendingCount" color="warning" variant="tonal" flat class="d-flex align-center flex-wrap ga-2 px-4 py-2 mb-2" role="status">
            <v-icon icon="mdi-pencil-outline" />
            <span class="font-weight-medium">{{ t('list.inline.pending', { count: pendingCount }, pendingCount) }}</span>
            <v-spacer />
            <v-btn size="small" variant="text" :disabled="savingChanges" @click="discardChanges">{{ t('list.inline.discard') }}</v-btn>
            <v-btn size="small" color="primary" variant="flat" :loading="savingChanges" @click="saveChanges">{{ t('list.inline.save') }}</v-btn>
        </v-card>

        <v-data-table-server
            v-model="selected"
            v-model:page="page"
            v-model:items-per-page="itemsPerPage"
            v-model:sort-by="sortBy"
            :headers="headers"
            :items="items"
            :items-length="total"
            :loading="loading"
            :items-per-page-options="resource.table.perPageOptions"
            :group-by="group ? [{ key: group, order: 'asc' }] : []"
            item-value="id"
            show-select
            hover
            fixed-header
            density="comfortable"
            @update:options="onOptions"
            @click:row="(_: Event, { item }: { item: VectorRecord }) => open(item)"
        >
            <template v-for="field in fields" :key="field.name" #[`item.${field.name}`]="{ item }">
                <div v-if="field.inline && isEditing(item, field)" class="vx-inline-editor" :class="{ 'vx-inline-changed': pending[String(item.id)] && field.name in pending[String(item.id)]! }" @click.stop @keydown.esc="closeUnchanged">
                    <component
                        :is="inputFor(field)"
                        :model-value="pending[String(item.id)]?.[field.name] ?? editValue(item, field)"
                        :field="field"
                        :rules="rulesFor(field, 'update')"
                        :error-messages="cellErrors[cellKey(item.id, field.name)] ? [cellErrors[cellKey(item.id, field.name)]!] : []"
                        :record="item"
                        :resource="resource.slug"
                        :aria-label="t('list.inline.editLabel', { label: field.label })"
                        label=""
                        density="compact"
                        hide-details="auto"
                        autofocus
                        @update:model-value="(v: unknown) => setCell(item, field, v)"
                    />
                </div>
                <span
                    v-else-if="field.inline && item._trashed !== true && resource.permissions.update"
                    class="vx-inline-cell"
                    role="button"
                    tabindex="0"
                    :aria-label="t('list.inline.editLabel', { label: field.label })"
                    @click.stop="startEdit(item, field)"
                    @keydown.enter.stop.prevent="startEdit(item, field)"
                >
                    <VxCell :field="field" :value="item[field.name]" :record="item" :resource="resource" context="index" />
                    <v-icon icon="mdi-pencil-outline" size="x-small" class="vx-inline-pencil" />
                </span>
                <span v-else :class="{ 'text-disabled': item._trashed === true }">
                    <VxCell :field="field" :value="item[field.name]" :record="item" :resource="resource" context="index" />
                </span>
            </template>

            <template #group-header="{ item, columns: cols, toggleGroup, isGroupOpen }">
                <tr class="bg-surface-light">
                    <td :colspan="cols.length" class="font-weight-medium">
                        <v-btn size="small" variant="text" :icon="isGroupOpen(item) ? 'mdi-chevron-down' : 'mdi-chevron-right'" :aria-label="isGroupOpen(item) ? t('list.collapseGroup') : t('list.expandGroup')" :aria-expanded="isGroupOpen(item)" @click="toggleGroup(item)" />
                        <span class="text-medium-emphasis mr-2">{{ t('list.groupHeader', { label: groupField?.label ?? '' }) }}</span>
                        <VxCell v-if="groupField" :field="groupField" :value="item.value" :record="{}" :resource="resource" context="index" />
                        <v-chip size="x-small" class="ml-2" variant="tonal">{{ item.items.length }}</v-chip>
                    </td>
                </tr>
            </template>

            <template v-if="summaries && Object.keys(summaryValues).length" #body.append>
                <tr class="font-weight-medium bg-surface-light">
                    <td />
                    <td v-if="group" />
                    <td v-for="field in fields" :key="field.name" :class="numeric(field) ? 'text-end' : ''">
                        <template v-if="field.summary && field.name in summaryValues">
                            <span class="text-caption text-medium-emphasis mr-1">{{ t(`list.summary.${field.summary}`) }}</span>{{ formatSummary(field, summaryValues[field.name] ?? null) }}
                        </template>
                    </td>
                </tr>
            </template>

            <template #no-data>
                <v-empty-state icon="mdi-database-off-outline" :title="Object.keys(filters).length || groups.length || advanced.length || term ? t('list.nothingMatches') : t('list.empty', { label: resource.label.toLowerCase() })" />
            </template>
        </v-data-table-server>

        <VxImportDialog v-if="importing" :resource="resource" @queued="importing = false" @cancel="importing = false" />

        <VxActionDialog
            v-if="dialogAction"
            :resource="resource"
            :action="dialogAction"
            :selection="dialogAction.standalone ? null : selection"
            :count="selectionCount"
            @done="actionDone"
            @cancel="dialogAction = null"
        />
    </div>
</template>

<style scoped>
.vx-inline-cell { display: inline-flex; align-items: center; gap: 4px; cursor: text; border-radius: 4px; padding: 2px 4px; margin: -2px -4px; }
.vx-inline-cell:hover, .vx-inline-cell:focus-visible { outline: 1px dashed rgba(var(--v-theme-primary), 0.6); }
.vx-inline-pencil { opacity: 0; }
.vx-inline-cell:hover .vx-inline-pencil, .vx-inline-cell:focus-visible .vx-inline-pencil { opacity: 0.6; }
.vx-inline-editor { min-width: 140px; padding-block: 4px; }
.vx-inline-changed { background: rgba(var(--v-theme-warning), 0.12); border-radius: 4px; }
</style>
