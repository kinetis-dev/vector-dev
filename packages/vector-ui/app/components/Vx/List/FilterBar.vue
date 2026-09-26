<script setup lang="ts">
import { fieldProps, type VectorField, type VectorFilterGroup, type VectorFilterQuery, type VectorFilters, type VectorOperator, type VectorResource } from '#vector/app/types/vector'

// The active filters as chips, plus a menu to add one: a field, an
// operator it admits, a value shaped by the field. The conditions (the
// model, `{field: {op: value}}`) must all hold; each "any of" group holds
// alternatives, one of which must. A saved view's deeper nesting shows as
// one chip that can only be removed.
const props = defineProps<{ resource: VectorResource }>()
const model = defineModel<VectorFilters>({ required: true })
const groups = defineModel<VectorFilterGroup[]>('groups', { default: () => [] })
const advanced = defineModel<VectorFilterQuery[]>('advanced', { default: () => [] })

/** Where the menu's condition goes: the conditions, an alternative, a new alternative (-1), or a new group (-1). */
type Target = { group: number, alternative: number } | null
const target = ref<Target>(null)
const menuActivator = ref<HTMLElement | undefined>(undefined)

function openAdd(where: Target, event: Event) {
    target.value = where
    menuActivator.value = event.currentTarget as HTMLElement
    fieldName.value = null
    adding.value = true
}

const { t, locale } = useVectorT()

const OPERATORS: readonly VectorOperator[] = ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'in', 'nin', 'like', 'nlike', 'between', 'null', 'nnull']

const operatorLabel = (op: string): string => (OPERATORS as readonly string[]).includes(op) ? t(`list.filter.operators.${op}`) : op

const filterable = computed(() => props.resource.fields.filter(f => f.operators.length > 0))

const adding = ref(false)
const fieldName = ref<string | null>(null)
const operator = ref<VectorOperator | null>(null)
const value = ref<string>('')
const valueTo = ref<string>('')
const client = useVectorResource(() => props.resource.slug)
const relationOptions = ref<{ id: number | string, label: string }[]>([])
const relationSearch = ref('')

const field = computed(() => filterable.value.find(f => f.name === fieldName.value) ?? null)

watch(field, (f) => {
    operator.value = f?.operators[0] ?? null
    value.value = ''
    valueTo.value = ''
    relationOptions.value = []

    if (f?.type === 'belongsTo') searchRelation('')
})

const needsValue = computed(() => operator.value !== null && !['null', 'nnull'].includes(operator.value))
const list = computed(() => operator.value === 'in' || operator.value === 'nin')

const searchRelation = useDebounceFn(async (q: string) => {
    if (!field.value) return
    relationOptions.value = (await client.options(field.value.name, q)).data
}, 250)

watch(relationSearch, q => searchRelation(q ?? ''))

function inputType(f: VectorField): string {
    if (f.type === 'date') return 'date'
    if (f.type === 'datetime') return 'datetime-local'
    if (['number', 'money', 'id'].includes(f.type)) return 'number'
    return 'text'
}

/**
 * A money filter compares minor units. A fixed currency says how many
 * decimals the typed amount has; one priced per record has no single
 * answer, so it is read in hundredths.
 */
function moneyDigits(f: VectorField): number {
    const currency = fieldProps(f).currency
    return minorDigits(typeof currency === 'string' ? currency : null)
}

function add() {
    if (!field.value || !operator.value) return

    let v = value.value.trim()

    if (field.value.type === 'money' && v !== '' && !list.value) {
        v = String(Math.round(Number.parseFloat(v.replace(',', '.')) * 10 ** moneyDigits(field.value)))
    }

    if (operator.value === 'between') v = `${value.value.trim()},${valueTo.value.trim()}`
    if (field.value.type === 'datetime' && v !== '' && !list.value && operator.value !== 'between' && v.includes('T')) v = new Date(v).toISOString()
    if (needsValue.value && v === '') return

    const name = field.value.name
    const condition = { [operator.value]: needsValue.value ? v : '' }
    const where = target.value

    if (where === null) {
        model.value = { ...model.value, [name]: { ...(model.value[name] ?? {}), ...condition } }
    } else if (where.group === -1) {
        groups.value = [...groups.value, [{ [name]: condition }]]
    } else {
        groups.value = groups.value.map((group, g) => {
            if (g !== where.group) return group
            if (where.alternative === -1) return [...group, { [name]: condition }]
            return group.map((alternative, a) => (a === where.alternative ? { ...alternative, [name]: { ...(alternative[name] ?? {}), ...condition } } : alternative))
        })
    }

    adding.value = false
    fieldName.value = null
}

function removeFromGroup(g: number, a: number, name: string, op: string) {
    groups.value = groups.value
        .map((group, gi) => gi !== g
            ? group
            : group.map((alternative, ai) => {
                if (ai !== a) return alternative
                const next = { ...alternative }
                const ops = { ...(next[name] ?? {}) }
                delete ops[op as VectorOperator]
                if (Object.keys(ops).length === 0) delete next[name]
                else next[name] = ops
                return next
            }).filter(alternative => Object.keys(alternative).length > 0))
        .filter(group => group.length > 0)
}

const chipsOf = (conditions: VectorFilters) => Object.entries(conditions).flatMap(([name, ops]) => Object.entries(ops).map(([op, v]) => ({ name, op, value: v ?? '' })))

function clearAll() {
    model.value = {}
    groups.value = []
    advanced.value = []
}

function remove(name: string, op: string) {
    const next = { ...model.value }
    const ops = { ...(next[name] ?? {}) }
    delete ops[op as VectorOperator]

    if (Object.keys(ops).length === 0) delete next[name]
    else next[name] = ops

    model.value = next
}

function chipText(name: string, op: string, v: string): string {
    const f = props.resource.fields.find(x => x.name === name)
    const label = f?.label ?? name
    const opLabel = operatorLabel(op)

    if (!f || op === 'null' || op === 'nnull') return t('list.filter.chipNoValue', { field: label, operator: opLabel })

    let shown = v

    if (f.type === 'enum') shown = v.split(',').map(x => f.options?.find(o => String(o.value) === x)?.label ?? x).join(', ')
    if (f.type === 'currency') shown = v.split(',').map(x => currencyLabel(x, locale.value)).join(', ')
    if (f.type === 'boolean') shown = v === 'true' || v === '1' ? t('list.filter.yes') : t('list.filter.no')
    if (f.type === 'money' && !['in', 'nin', 'between'].includes(op)) shown = (Number(v) / 10 ** moneyDigits(f)).toFixed(moneyDigits(f))
    if (op === 'between') shown = v.replace(',', ` ${t('list.filter.and')} `)

    return t('list.filter.chip', { field: label, operator: opLabel, value: shown })
}

const active = computed(() => chipsOf(model.value))
const anything = computed(() => active.value.length > 0 || groups.value.length > 0 || advanced.value.length > 0)
</script>

<template>
    <div class="d-flex align-center flex-wrap ga-2">
        <v-chip v-for="chip in active" :key="`${chip.name}.${chip.op}`" closable size="small" variant="tonal" color="primary" @click:close="remove(chip.name, chip.op)">
            {{ chipText(chip.name, chip.op, chip.value) }}
        </v-chip>
        <div
            v-for="(group, g) in groups"
            :key="`group-${g}`"
            class="d-inline-flex align-center flex-wrap ga-1 px-2 py-1 rounded border"
            role="group"
            :aria-label="t('list.filter.anyOfGroup', { n: g + 1 })"
        >
            <span class="text-caption text-medium-emphasis">{{ t('list.filter.anyOf') }}</span>
            <template v-for="(alternative, a) in group" :key="`alternative-${g}-${a}`">
                <span v-if="a > 0" class="text-caption font-weight-medium">{{ t('list.filter.or') }}</span>
                <span class="d-inline-flex align-center ga-1" role="group" :aria-label="t('list.filter.alternative', { n: a + 1 })">
                    <v-chip v-for="chip in chipsOf(alternative)" :key="`${chip.name}.${chip.op}`" closable size="small" variant="tonal" color="primary" @click:close="removeFromGroup(g, a, chip.name, chip.op)">
                        {{ chipText(chip.name, chip.op, chip.value) }}
                    </v-chip>
                    <v-btn icon="mdi-plus" size="x-small" variant="text" :aria-label="t('list.filter.addToAlternative', { n: a + 1 })" @click="openAdd({ group: g, alternative: a }, $event)" />
                </span>
            </template>
            <v-btn size="x-small" variant="text" prepend-icon="mdi-plus" @click="openAdd({ group: g, alternative: -1 }, $event)">{{ t('list.filter.orCondition') }}</v-btn>
            <v-btn icon="mdi-close" size="x-small" variant="text" :aria-label="t('list.filter.removeGroup', { n: g + 1 })" @click="groups = groups.filter((_, i) => i !== g)" />
        </div>
        <v-chip v-for="(node, i) in advanced" :key="`advanced-${i}`" closable size="small" variant="outlined" prepend-icon="mdi-code-braces" :title="JSON.stringify(node)" @click:close="advanced = advanced.filter((_, j) => j !== i)">
            {{ t('list.filter.advanced') }}
        </v-chip>
        <v-btn size="small" variant="text" prepend-icon="mdi-filter-variant-plus" :disabled="filterable.length === 0" @click="openAdd(null, $event)">{{ t('list.filter.add') }}</v-btn>
        <v-btn size="small" variant="text" prepend-icon="mdi-set-merge" :disabled="filterable.length === 0" @click="openAdd({ group: -1, alternative: -1 }, $event)">{{ t('list.filter.addAnyOf') }}</v-btn>
        <v-menu v-model="adding" :activator="menuActivator" :close-on-content-click="false" location="bottom start">
            <v-card min-width="360" class="pa-4">
                <v-select v-model="fieldName" :label="t('list.field')" :items="filterable" item-title="label" item-value="name" density="compact" hide-details class="mb-3" />
                <v-select v-if="field" v-model="operator" :label="t('list.filter.condition')" :items="field.operators.map(op => ({ title: operatorLabel(op), value: op }))" density="compact" hide-details class="mb-3" />
                <template v-if="field && needsValue">
                    <v-select v-if="field.type === 'enum' || field.type === 'currency'" v-model="value" :label="t('list.filter.value')" :items="field.type === 'currency' ? (field.options ?? []).map(o => ({ value: o.value, label: currencyLabel(String(o.value), locale) })) : (field.options ?? [])" item-title="label" item-value="value" density="compact" hide-details class="mb-3" :multiple="list" @update:model-value="(v: unknown) => value = Array.isArray(v) ? v.join(',') : String(v ?? '')" />
                    <v-select v-else-if="field.type === 'boolean'" v-model="value" :label="t('list.filter.value')" :items="[{ title: t('common.yes'), value: 'true' }, { title: t('common.no'), value: 'false' }]" density="compact" hide-details class="mb-3" />
                    <v-autocomplete v-else-if="field.type === 'belongsTo'" v-model:search="relationSearch" :label="t('list.filter.value')" :items="relationOptions" item-title="label" item-value="id" density="compact" hide-details class="mb-3" :multiple="list" no-filter @update:model-value="(v: unknown) => value = Array.isArray(v) ? v.join(',') : String(v ?? '')" />
                    <template v-else>
                        <v-text-field v-model="value" :label="operator === 'between' ? t('list.filter.from') : t('list.filter.value')" :type="list ? 'text' : inputType(field)" :hint="list ? t('list.filter.commaSeparated') : undefined" density="compact" hide-details class="mb-3" @keyup.enter="add" />
                        <v-text-field v-if="operator === 'between'" v-model="valueTo" :label="t('list.filter.to')" :type="inputType(field)" density="compact" hide-details class="mb-3" @keyup.enter="add" />
                    </template>
                </template>
                <div class="d-flex justify-end ga-2">
                    <v-btn variant="text" size="small" @click="adding = false">{{ t('common.cancel') }}</v-btn>
                    <v-btn color="primary" size="small" :disabled="!field || !operator" @click="add">{{ t('common.apply') }}</v-btn>
                </div>
            </v-card>
        </v-menu>
        <v-btn v-if="anything" size="small" variant="text" @click="clearAll">{{ t('list.filter.clear') }}</v-btn>
    </div>
</template>
