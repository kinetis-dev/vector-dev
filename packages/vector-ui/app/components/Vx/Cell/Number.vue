<script setup lang="ts">
import { fieldProps, type VectorField } from '#vector/app/types/vector'

const props = defineProps<{ field: VectorField, value: unknown }>()
const text = computed(() => {
    if (typeof props.value !== 'number') return ''
    const decimals = fieldProps(props.field).decimals
    return formatNumber(props.value, typeof decimals === 'number' ? decimals : undefined)
})
const { t } = useVectorT()
</script>

<template>
    <span v-if="text === ''" class="text-disabled">{{ t('common.none') }}</span>
    <span v-else class="text-no-wrap">{{ text }}</span>
</template>
