<script setup lang="ts">
import { useLocale } from 'vuetify'

// The footer of a list that cannot say how many records it has: an
// uncounted (withoutCount) list shows the page it is on, a cursor list
// not even that. Previous and next only — no total, no last page.
defineProps<{
    perPage: number
    perPageOptions: number[]
    /** The page number to show; null shows none (a cursor list). */
    page: number | null
    hasPrevious: boolean
    hasNext: boolean
    disabled?: boolean
}>()

const emit = defineEmits<{
    'update:perPage': [value: number]
    previous: []
    next: []
}>()

const { t } = useVectorT()
// Vuetify's own footer strings, in every language it ships
const { t: vt } = useLocale()
</script>

<template>
    <div class="vx-list-pager d-flex align-center justify-end flex-wrap ga-4 px-4 py-2 text-body-2">
        <div class="d-flex align-center ga-2">
            <span>{{ vt('$vuetify.dataFooter.itemsPerPageText') }}</span>
            <v-select
                :model-value="perPage"
                :items="perPageOptions"
                :aria-label="vt('$vuetify.dataFooter.itemsPerPageText')"
                density="compact"
                variant="outlined"
                hide-details
                style="max-width: 100px"
                @update:model-value="(v: number) => emit('update:perPage', v)"
            />
        </div>
        <span v-if="page !== null" class="vx-list-pager-page">{{ t('list.pager.page', { page }) }}</span>
        <div class="d-flex align-center">
            <v-btn icon="$prev" variant="plain" density="comfortable" :disabled="disabled || !hasPrevious" :aria-label="vt('$vuetify.dataFooter.prevPage')" @click="emit('previous')" />
            <v-btn icon="$next" variant="plain" density="comfortable" :disabled="disabled || !hasNext" :aria-label="vt('$vuetify.dataFooter.nextPage')" @click="emit('next')" />
        </div>
    </div>
</template>
