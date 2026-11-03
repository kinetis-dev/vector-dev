<script setup lang="ts">
definePageMeta({ layout: 'vector', middleware: 'vector-auth', key: route => route.fullPath })

// A custom page: the component a runtime extension or the application
// registered under this name, handed the admin's API client so it can
// call the application's own endpoints with the admin's credentials.
const route = useRoute()
const { t } = useVectorT()
const pages = useVectorPages()
const { ready } = useVectorExtensions()
const appConfig = useAppConfig()
const api = useVectorApi()
const notifications = useVectorNotifications()

const name = computed(() => String(route.params.name))
const page = computed(() => pages.value[name.value] ?? null)
const component = computed(() => (typeof page.value?.component === 'string' ? resolveComponent(page.value.component) : page.value?.component))
// a title given as a function is called here, so the heading and the tab title follow the language
const title = computed(() => pageLabel(page.value?.title))
const context = {
    api,
    notify: { success: (m: string) => notifications.success(m), error: (m: string) => notifications.error(m) },
    navigate: (path: string) => navigateTo(`${appConfig.vector.basePath}${path}`),
}

useHead({ title: () => title.value ?? t('shell.page.unknown') })
</script>

<template>
    <div v-if="page">
        <h1 class="text-h4 mb-4 d-flex align-center ga-2">
            <v-icon v-if="page.icon" :icon="page.icon" />
            {{ title }}
        </h1>
        <component :is="component" :vector="context" />
    </div>
    <v-progress-linear v-else-if="!ready" indeterminate :aria-label="t('shell.page.loading')" />
    <v-empty-state v-else icon="mdi-help-circle-outline" :title="t('shell.page.unknown')" />
</template>
