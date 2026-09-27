<script setup lang="ts">
definePageMeta({ layout: 'vector', middleware: 'vector-auth' })

// The home page: the `main` dashboard when the application declares one.
// Every resource is in the navigation drawer, so the page repeats none.
const { t } = useVectorT()
const { load, schema } = useVectorSchema()
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
    </div>
</template>
