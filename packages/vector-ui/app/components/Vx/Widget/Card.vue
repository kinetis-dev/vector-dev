<script setup lang="ts">
import type { VectorWidget, VectorWidgetData } from '#vector/app/types/vector'

// One widget card: loads its data for the selected range and renders
// the shape its type declares. A consuming app replaces a type through
// app.config.vector.widgets, like fields and inputs.
const props = defineProps<{
    widget: VectorWidget
    load: (range: string | undefined) => Promise<VectorWidgetData>
}>()

const appConfig = useAppConfig()
const { t } = useVectorT()
const range = ref<string | undefined>(Object.keys(props.widget.ranges)[0])
const data = ref<VectorWidgetData | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)
let requestId = 0

async function refresh() {
    const id = ++requestId
    loading.value = true
    error.value = null

    try {
        const result = await props.load(range.value)
        if (id === requestId) data.value = result
    } catch (caught) {
        if (id === requestId) error.value = vectorErrorMessage(caught)
    } finally {
        if (id === requestId) loading.value = false
    }
}

watch(range, refresh)
onMounted(refresh)

const builtIn: Record<string, Component> = {
    stat: defineAsyncComponent(() => import('#vector/app/components/Vx/Widget/Stat.vue')),
    trend: defineAsyncComponent(() => import('#vector/app/components/Vx/Widget/Trend.vue')),
    partition: defineAsyncComponent(() => import('#vector/app/components/Vx/Widget/Partition.vue')),
    progress: defineAsyncComponent(() => import('#vector/app/components/Vx/Widget/Progress.vue')),
    table: defineAsyncComponent(() => import('#vector/app/components/Vx/Widget/Table.vue')),
}

const component = computed(() => {
    const override = appConfig.vector.widgets[props.widget.type] ?? useVectorExtensions().widgets.get(props.widget.type)
    return override ? (typeof override === 'string' ? resolveComponent(override) : override) : builtIn[props.widget.type] ?? builtIn.stat!
})
</script>

<template>
    <v-card class="h-100 d-flex flex-column">
        <v-card-item class="pb-0">
            <template v-if="widget.icon" #prepend>
                <v-icon :icon="widget.icon" color="primary" />
            </template>
            <v-card-title class="text-subtitle-1">{{ widget.label }}</v-card-title>
            <template #append>
                <v-select
                    v-if="Object.keys(widget.ranges).length > 1"
                    v-model="range"
                    :items="Object.entries(widget.ranges).map(([value, title]) => ({ value, title }))"
                    density="compact"
                    variant="plain"
                    hide-details
                    :aria-label="t('dashboard.range')"
                    style="min-width: 110px"
                />
            </template>
        </v-card-item>
        <v-card-text class="flex-grow-1 d-flex flex-column justify-center">
            <v-progress-linear v-if="loading && !data" indeterminate color="primary" :aria-label="t('dashboard.loading')" />
            <v-alert v-else-if="error" type="error" variant="tonal" density="compact" :text="error" />
            <component :is="component" v-else-if="data" :data="data" :widget="widget" />
        </v-card-text>
    </v-card>
</template>
