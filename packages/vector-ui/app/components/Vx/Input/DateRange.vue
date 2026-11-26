<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'
import { controlLimits, dateBoundsOf, localMinute } from '#vector/app/utils/internal/dateBounds'

// A date range: two native controls side by side, the inputs the filter
// bar uses, editing `{from, to}`. A range of days edits days; a range of
// instants edits local time, sent as ISO 8601 in UTC. The end's control
// starts no earlier than the start; the form's rules check both are set
// or neither, and the field's minDate() and maxDate().
const props = withDefaults(defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string, disabled?: boolean }>(), { record: undefined, resource: undefined, disabled: undefined })
const model = defineModel<{ from: string | null, to: string | null } | null>()
const { t } = useVectorT()

const days = computed(() => props.field.range?.kind !== 'datetime')
const now = ref(new Date())
const limits = computed(() => controlLimits(dateBoundsOf(props.field), now.value))

/** One end as its control shows it. */
function shown(value: string | null | undefined): string {
    if (!value) return ''
    if (days.value) return value.slice(0, 10)
    const moment = new Date(value)
    return Number.isNaN(moment.getTime()) ? '' : localMinute(moment)
}

const from = computed(() => shown(model.value?.from))
const to = computed(() => shown(model.value?.to))

function set(end: 'from' | 'to', typed: string | null) {
    let value: string | null = null
    if (typed) value = days.value ? typed : new Date(typed).toISOString()
    model.value = { from: model.value?.from ?? null, to: model.value?.to ?? null, [end]: value }
}
</script>

<template>
    <v-input :model-value="model" :rules="rules" :error-messages="errorMessages" :hint="field.help ?? undefined" :persistent-hint="!!field.help" :disabled="props.disabled">
        <template #default="{ isDisabled }">
            <fieldset class="vx-fieldset" :data-date-range="field.name">
                <legend class="text-body-2 text-medium-emphasis mb-1">{{ field.label }}</legend>
                <div class="d-flex flex-wrap ga-3">
                    <v-text-field
                        :model-value="from"
                        :label="t('fields.dateRange.from')"
                        :type="days ? 'date' : 'datetime-local'"
                        :min="limits.min"
                        :max="limits.max"
                        :disabled="isDisabled.value"
                        :readonly="field.readonly"
                        :clearable="field.nullable === true"
                        hide-details
                        class="vx-date-range-end"
                        @focus="now = new Date()"
                        @update:model-value="(v: string | null) => set('from', v)"
                    />
                    <v-text-field
                        :model-value="to"
                        :label="t('fields.dateRange.to')"
                        :type="days ? 'date' : 'datetime-local'"
                        :min="from || limits.min"
                        :max="limits.max"
                        :disabled="isDisabled.value"
                        :readonly="field.readonly"
                        :clearable="field.nullable === true"
                        hide-details
                        class="vx-date-range-end"
                        @focus="now = new Date()"
                        @update:model-value="(v: string | null) => set('to', v)"
                    />
                </div>
            </fieldset>
        </template>
    </v-input>
</template>

<style scoped>
/* a fieldset that groups without a fieldset's own border, padding and margin */
.vx-fieldset { border: 0; margin: 0; padding: 0; min-width: 0; width: 100%; }
.vx-date-range-end { flex: 1 1 12rem; }
</style>
