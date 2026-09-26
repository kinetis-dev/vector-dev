<script setup lang="ts">
import type { VectorDashboard } from '#vector/app/types/vector'

// One dashboard page: its cards in a responsive grid, each loading its own data.
const props = defineProps<{ name: string }>()
const dashboards = useVectorDashboards()
const { t } = useVectorT()
const { data, error } = await useAsyncData(() => `vector.dashboard.${props.name}`, () => dashboards.show(props.name))
const dashboard = computed<VectorDashboard | null>(() => data.value ?? null)
</script>

<template>
    <div>
        <div class="d-flex align-center mb-4">
            <v-icon v-if="dashboard?.icon" :icon="dashboard.icon" class="mr-2" />
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
