<script setup lang="ts">
import type { VectorRecord } from '#vector/app/types/vector'
import type { SaveVariant } from '#vector/app/components/Vx/Resource/Form.vue'

definePageMeta({ layout: 'vector', middleware: 'vector-auth', key: route => route.fullPath })

const { t, noun } = useVectorT()
const route = useRoute()
const appConfig = useAppConfig()
const { load, resource: find } = useVectorSchema()
await load()

const slug = computed(() => String(route.params.resource))
const resource = computed(() => find(slug.value))

// ?from={id}: a copy of that record (Duplicate on its page)
const from = computed(() => typeof route.query.from === 'string' && route.query.from !== '' ? route.query.from : null)
const client = useVectorResource(slug)
const { data: source, error: sourceError } = await useAsyncData(() => `vector.${slug.value}.copy.${from.value}`, () => from.value ? client.show(from.value) : Promise.resolve(null))
const base = computed(() => `${appConfig.vector.basePath}/r/${slug.value}`)

useHead({ title: () => resource.value ? `${t('record.newTitle', { label: noun(resource.value.labelSingular) })} · ${resource.value.label}` : '' })

const form = ref<{ dirty: boolean, reset: () => void } | null>(null)
useLeaveGuard(() => form.value?.dirty === true)

function saved(record: VectorRecord, variant: SaveVariant) {
    if (variant === 'another') {
        // another one starts empty, even after a copy
        if (from.value) return navigateTo(`${base.value}/create`)
        form.value?.reset()
        window.scrollTo({ top: 0 })
        return
    }

    const at = `${base.value}/${encodeURIComponent(String(record.id))}`
    navigateTo(variant === 'continue' ? `${at}/edit` : at)
}
</script>

<template>
    <div v-if="resource">
        <v-breadcrumbs class="pa-0 mb-2" :items="[{ title: resource.label, to: base }, { title: t('record.new') }]" />
        <h1 class="text-h4 mb-4">{{ t('record.newTitle', { label: noun(resource.labelSingular) }) }}</h1>
        <v-alert v-if="!resource.permissions.create" type="warning" variant="tonal" :text="t('record.createNotAllowed')" />
        <v-alert v-else-if="sourceError" type="error" variant="tonal" :text="vectorErrorMessage(sourceError)" />
        <template v-else>
            <v-alert v-if="source" type="info" variant="tonal" density="compact" class="mb-4" :text="t('record.copyOf', { title: String(source.data[resource.titleField] ?? `#${source.data.id}`) })" />
            <VxResourceForm ref="form" :resource="resource" operation="create" :copy-of="source?.data" variants @saved="saved" @cancel="navigateTo(base)" />
        </template>
    </div>
    <v-empty-state v-else icon="mdi-help-circle-outline" :title="t('record.unknownResource')" />
</template>
