<script setup lang="ts">
definePageMeta({ layout: 'vector', middleware: 'vector-auth', key: route => route.fullPath })

const route = useRoute()
const { load, resource: find } = useVectorSchema()
await load()

const { t } = useVectorT()
const appConfig = useAppConfig()
const slug = computed(() => String(route.params.resource))
const resource = computed(() => find(slug.value))

useHead({ title: () => resource.value?.label ?? '' })
</script>

<template>
    <div v-if="resource">
        <div class="d-flex align-center mb-4">
            <v-icon v-if="resource.icon" :icon="resource.icon" class="mr-2" />
            <h1 class="text-h4">{{ resource.label }}</h1>
            <v-spacer />
            <v-btn v-if="resource.permissions.create" :to="`${appConfig.vector.basePath}/r/${resource.slug}/create`" prepend-icon="mdi-plus" color="primary">
                {{ t('list.page.create', { noun: resource.labelSingular.toLowerCase() }) }}
            </v-btn>
        </div>
        <VxResourceTable :key="resource.slug" :resource="resource" />
    </div>
    <v-empty-state v-else icon="mdi-help-circle-outline" :title="t('list.page.unknown')" :text="t('list.page.unknownText', { slug })" />
</template>
