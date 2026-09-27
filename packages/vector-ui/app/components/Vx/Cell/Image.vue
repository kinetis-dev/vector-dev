<script setup lang="ts">
import type { VectorField, VectorResource } from '#vector/app/types/vector'

// A stored image as a thumbnail. The bytes come through the API client,
// so a bearer-authenticated admin sees them too; a key with no stored
// file (or no upload support) shows as its name.
const props = defineProps<{ field: VectorField, value: unknown, record: Record<string, unknown>, resource: VectorResource, context: 'index' | 'show' }>()

const client = useVectorResource(() => props.resource.slug)
const url = ref<string | null>(null)
const failed = ref(false)
const key = computed(() => (typeof props.value === 'string' && props.value !== '' ? props.value : null))
const size = computed(() => (props.context === 'show' ? 200 : 40))

watch([key, () => props.record.id], async () => {
    url.value = null
    failed.value = false
    if (!key.value || props.record.id === undefined || props.record.id === null) return

    try {
        url.value = await dataUrl(await client.fileBlob(props.record.id as string | number, props.field.name))
    } catch {
        failed.value = true
    }
}, { immediate: true })

const { t } = useVectorT()
</script>

<template>
    <span v-if="!key" class="text-disabled">{{ t('common.none') }}</span>
    <v-img v-else-if="url" :src="url" :width="size" :height="size" :alt="field.label" cover class="rounded" />
    <span v-else-if="failed" class="d-inline-flex align-center ga-1 text-medium-emphasis" :title="key">
        <v-icon icon="mdi-image-off-outline" size="small" />
        <span class="text-caption">{{ key.split('/').pop() }}</span>
    </span>
    <v-progress-circular v-else indeterminate size="16" width="2" :aria-label="t('fields.cell.loading', { label: field.label })" />
</template>
