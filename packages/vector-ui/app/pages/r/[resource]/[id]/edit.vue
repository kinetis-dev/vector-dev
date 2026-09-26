<script setup lang="ts">
import type { VectorRecord } from '#vector/app/types/vector'

definePageMeta({ layout: 'vector', middleware: 'vector-auth', key: route => route.fullPath })

const { t } = useVectorT()
const route = useRoute()
const appConfig = useAppConfig()
const { load, resource: find } = useVectorSchema()
await load()

const slug = computed(() => String(route.params.resource))
const id = computed(() => String(route.params.id))
const resource = computed(() => find(slug.value))
const client = useVectorResource(slug)
const base = computed(() => `${appConfig.vector.basePath}/r/${slug.value}`)

const { data: response, status, error } = await useAsyncData(() => `vector.${slug.value}.${id.value}.edit`, () => client.show(id.value))

const title = computed(() => {
    const value = response.value?.data?.[resource.value?.titleField ?? 'id']
    return value === undefined || value === null ? `#${id.value}` : String(value)
})

useHead({ title: () => resource.value ? `${t('record.editTitle', { title: title.value })} · ${resource.value.label}` : 'Vector' })

function saved(record: VectorRecord) {
    navigateTo(`${base.value}/${encodeURIComponent(String(record.id))}`)
}
</script>

<template>
    <div v-if="resource">
        <v-breadcrumbs class="pa-0 mb-2" :items="[{ title: resource.label, to: base }, { title, to: `${base}/${id}` }, { title: t('common.edit') }]" />
        <h1 class="text-h4 mb-4">{{ t('record.editTitle', { title }) }}</h1>
        <v-skeleton-loader v-if="status === 'pending'" type="article" />
        <v-alert v-else-if="error" type="error" variant="tonal" :text="vectorErrorMessage(error)" />
        <v-alert v-else-if="response && !response.permissions.update" type="warning" variant="tonal" :text="t('record.editNotAllowed')" />
        <VxResourceForm v-else-if="response" :resource="resource" operation="update" :record="response.data" @saved="saved" @cancel="navigateTo(`${base}/${id}`)" />
    </div>
    <v-empty-state v-else icon="mdi-help-circle-outline" :title="t('record.unknownResource')" />
</template>
