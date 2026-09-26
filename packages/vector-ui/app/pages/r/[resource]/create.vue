<script setup lang="ts">
import type { VectorRecord } from '#vector/app/types/vector'

definePageMeta({ layout: 'vector', middleware: 'vector-auth', key: route => route.fullPath })

const { t } = useVectorT()
const route = useRoute()
const appConfig = useAppConfig()
const { load, resource: find } = useVectorSchema()
await load()

const slug = computed(() => String(route.params.resource))
const resource = computed(() => find(slug.value))
const base = computed(() => `${appConfig.vector.basePath}/r/${slug.value}`)

useHead({ title: () => resource.value ? `${t('record.newTitle', { label: resource.value.labelSingular.toLowerCase() })} · ${resource.value.label}` : 'Vector' })

function saved(record: VectorRecord) {
    navigateTo(`${base.value}/${encodeURIComponent(String(record.id))}`)
}
</script>

<template>
    <div v-if="resource">
        <v-breadcrumbs class="pa-0 mb-2" :items="[{ title: resource.label, to: base }, { title: t('record.new') }]" />
        <h1 class="text-h4 mb-4">{{ t('record.newTitle', { label: resource.labelSingular.toLowerCase() }) }}</h1>
        <v-alert v-if="!resource.permissions.create" type="warning" variant="tonal" :text="t('record.createNotAllowed')" />
        <VxResourceForm v-else :resource="resource" operation="create" @saved="saved" @cancel="navigateTo(base)" />
    </div>
    <v-empty-state v-else icon="mdi-help-circle-outline" :title="t('record.unknownResource')" />
</template>
