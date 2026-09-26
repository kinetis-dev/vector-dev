<script setup lang="ts">
definePageMeta({ layout: 'vector', middleware: 'vector-auth' })

// The home page: the `main` dashboard when the application declares
// one, then the resource cards.
const appConfig = useAppConfig()
const { t } = useVectorT()
const { load, menu, schema } = useVectorSchema()
await load()

const main = computed(() => schema.value?.dashboards.find(d => d.name === 'main') ?? schema.value?.dashboards[0] ?? null)

useHead({ title: () => t('shell.home.title') })
</script>

<template>
    <div>
        <VxDashboard v-if="main" :name="main.name" />
        <template v-else>
            <h1 class="text-h4 mb-4">{{ t('shell.home.title') }}</h1>
        </template>
        <template v-for="group in menu" :key="group.label">
            <h2 class="text-subtitle-1 text-medium-emphasis mt-6 mb-2">{{ group.label }}</h2>
            <v-row density="compact">
                <v-col v-for="resource in group.items" :key="resource.slug" cols="12" sm="6" md="4" lg="3">
                    <v-card :to="`${appConfig.vector.basePath}/r/${resource.slug}`" link hover>
                        <v-card-item>
                            <template #prepend>
                                <v-avatar color="primary" variant="tonal" :icon="resource.icon ?? 'mdi-table'" />
                            </template>
                            <v-card-title>{{ resource.label }}</v-card-title>
                            <v-card-subtitle>{{ t('shell.home.fieldCount', { count: resource.fields.length }, resource.fields.length) }}</v-card-subtitle>
                        </v-card-item>
                    </v-card>
                </v-col>
            </v-row>
        </template>
    </div>
</template>
