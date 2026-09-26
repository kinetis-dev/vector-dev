<script setup lang="ts">
import type { VectorField } from '#vector/app/types/vector'

// Edited as a decimal amount, held as minor units — the wire value, in as
// many decimals as the currency has (none for yen, three for dinars). The
// text is what was typed until the field is left; only then, when the
// value changes from outside, or when the currency changes, is it written
// out in full.
const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record: Record<string, unknown> }>()
const model = defineModel<number | null>()

const currency = computed(() => currencyOf(props.field, props.record))

const digits = computed(() => minorDigits(currency.value))
const format = (value: number | null | undefined) => (typeof value === 'number' ? (value / 10 ** digits.value).toFixed(digits.value) : '')
const text = ref(format(model.value))
let typed: number | null | undefined = model.value

watch(model, (value) => {
    if (value !== typed) text.value = format(value)
    typed = value
})
// the same minor units read in another currency's decimals
watch(digits, () => { text.value = format(model.value) })

function input(value: string) {
    text.value = value
    const parsed = Number.parseFloat(String(value ?? '').replace(',', '.'))
    typed = Number.isFinite(parsed) ? Math.round(parsed * 10 ** digits.value) : null
    model.value = typed
}
</script>

<template>
    <v-text-field
        :model-value="text"
        :label="field.label"
        :rules="rules"
        :error-messages="errorMessages"
        :suffix="currency ?? undefined"
        :inputmode="digits > 0 ? 'decimal' : 'numeric'"
        :hint="field.help ?? undefined"
        :persistent-hint="!!field.help"
        @update:model-value="input"
        @blur="text = format(model)"
    />
</template>
