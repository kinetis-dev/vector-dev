<script setup lang="ts">
import type { VectorField, VectorResource } from '#vector/app/types/vector'

// A stored document as a download button, fetched with the API's credentials.
const props = defineProps<{ field: VectorField, value: unknown, record: Record<string, unknown>, resource: VectorResource, context: 'index' | 'show' }>()

const client = useVectorResource(() => props.resource.slug)
const notifications = useVectorNotifications()
const key = computed(() => (typeof props.value === 'string' && props.value !== '' ? props.value : null))
const name = computed(() => key.value?.split('/').pop() ?? '')
const busy = ref(false)

async function download() {
    if (!key.value) return
    busy.value = true
    try {
        await client.downloadFile(props.record.id as string | number, props.field.name, name.value)
    } catch (error) {
        notifications.error(vectorErrorMessage(error))
    } finally {
        busy.value = false
    }
}
const { t } = useVectorT()
</script>

<template>
    <span v-if="!key" class="text-disabled">{{ t('common.none') }}</span>
    <v-btn v-else size="small" variant="tonal" prepend-icon="mdi-download" :loading="busy" @click.stop="download">{{ context === 'show' ? name : t('common.download') }}</v-btn>
</template>
