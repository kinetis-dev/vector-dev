<script setup lang="ts">
import type { VectorDashboard } from '#vector/app/types/vector'

// One dashboard page: its cards in a responsive grid, each loading its own data.
// The server translates its labels, so it is fetched again when the language changes.
const props = defineProps<{ name: string }>()
const dashboards = useVectorDashboards()
const { t } = useVectorT()
const { current: locale } = useVectorLocale()
const { data, error } = await useAsyncData(() => `vector.dashboard.${props.name}`, () => dashboards.show(props.name), { watch: [locale] })
const dashboard = computed<VectorDashboard | null>(() => data.value ?? null)
</script>

<template>
    <div>
        <div class="d-flex align-center mb-4">
            <v-icon v-if="dashboard?.icon" :icon="dashboard.icon" class="me-2" />
            <h1 class="text-h4">{{ dashboard?.label ?? t('dashboard.title') }}</h1>
        </div>
        <v-alert v-if="error" type="error" variant="tonal" :text="vectorErrorMessage(error)" />
        <v-row v-else-if="dashboard" density="compact">
            <v-col v-for="widget in dashboard.widgets" :key="widget.name" cols="12" :md="widget.width">
                <VxWidgetCard :widget="widget" :load="(range: string | undefined) => dashboards.widget(props.name, widget.name, range)" />
            </v-col>
        </v-row>
    </div>
</template>
