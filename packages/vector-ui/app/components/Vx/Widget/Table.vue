<script setup lang="ts">
import type { VectorWidgetData } from '#vector/app/types/vector'

const props = defineProps<{ data: VectorWidgetData }>()
const appConfig = useAppConfig()
const { t } = useVectorT()
const rows = computed(() => props.data.rows ?? [])

function open(row: Record<string, unknown>) {
    if (typeof row._link === 'string') navigateTo(`${appConfig.vector.basePath}${row._link}`)
}
</script>

<template>
    <div>
        <v-table density="compact" hover>
            <thead>
                <tr><th v-for="col in data.columns ?? []" :key="col.key" :class="col.align === 'end' ? 'text-end' : ''">{{ col.label }}</th></tr>
            </thead>
            <tbody>
                <tr v-for="(row, i) in rows" :key="i" :style="typeof row._link === 'string' ? 'cursor: pointer' : ''" @click="open(row)">
                    <td v-for="col in data.columns ?? []" :key="col.key" :class="col.align === 'end' ? 'text-end' : ''">{{ row[col.key] }}</td>
                </tr>
                <tr v-if="rows.length === 0"><td :colspan="(data.columns ?? []).length" class="text-medium-emphasis">{{ t('dashboard.table.empty') }}</td></tr>
            </tbody>
        </v-table>
        <div v-if="data.link" class="text-end mt-2">
            <v-btn size="small" variant="text" :to="`${appConfig.vector.basePath}${data.link}`" append-icon="mdi-arrow-right">{{ t('dashboard.table.seeAll') }}</v-btn>
        </div>
    </div>
</template>
