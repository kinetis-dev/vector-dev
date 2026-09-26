<script setup lang="ts">
import { fieldProps, type VectorSearchResult } from '#vector/app/types/vector'

// Ctrl/Cmd+K: search every globally searchable resource, jump to a
// record or a resource. A dialog and a list — no Labs dependency.
const appConfig = useAppConfig()
const api = useVectorApi()
const { menu, resource: resourceOf } = useVectorSchema()
const open = ref(false)
const query = ref<string | null>('')
const results = ref<VectorSearchResult[]>([])
const loading = ref(false)
let requestId = 0
const { t } = useVectorT()

const search = useDebounceFn(async (q: string) => {
    const id = ++requestId
    const term = q.trim()

    if (term === '') {
        results.value = []
        return
    }

    loading.value = true
    try {
        const response = await api<{ results: VectorSearchResult[] }>('/vector/search', { query: { q: term } })
        if (id === requestId) results.value = response.results
    } finally {
        if (id === requestId) loading.value = false
    }
}, 200)

watch(query, q => search(q ?? ''))
watch(open, (o) => { if (!o) { query.value = ''; results.value = [] } })

const keyOf = (r: VectorSearchResult) => `${r.resource}-${r.id}`

/** The subtitle as its field shows it — an enum by its label — then the resource. */
function subtitleOf(r: VectorSearchResult): string {
    const resource = resourceOf(r.resource)
    const field = resource?.fields.find(f => f.name === resource.searchResult?.subtitle)
    let text = r.subtitle ?? null
    if (text !== null && field?.type === 'enum') text = field.options?.find(o => String(o.value) === text)?.label ?? text
    else if (text !== null && field?.type === 'number' && text !== '' && !Number.isNaN(Number(text))) {
        const decimals = fieldProps(field).decimals
        text = formatNumber(Number(text), typeof decimals === 'number' ? decimals : undefined)
    }
    return [text, r.resourceLabel].filter(Boolean).join(' · ')
}

// Thumbnails come through the API client, like the image cells, so a
// bearer-authenticated admin sees them too; a missing file keeps the icon.
const thumbs = reactive<Record<string, string>>({})
watch(results, (list) => {
    for (const [key, url] of Object.entries(thumbs)) {
        URL.revokeObjectURL(url)
        delete thumbs[key]
    }
    for (const r of list) {
        if (!r.image) continue
        api<Blob>(`/vector/r/${encodeURIComponent(r.resource)}/${encodeURIComponent(String(r.id))}/files/${encodeURIComponent(r.image)}`, { responseType: 'blob' })
            .then((blob) => { if (results.value.includes(r)) thumbs[keyOf(r)] = URL.createObjectURL(blob) })
            .catch(() => {})
    }
})
onBeforeUnmount(() => Object.values(thumbs).forEach(url => URL.revokeObjectURL(url)))

const pages = useVectorPages()
const pageItems = computed(() => Object.entries(pages.value).filter(([, p]) => (query.value ?? '').trim() === '' || p.title.toLowerCase().includes((query.value ?? '').trim().toLowerCase())))
const resources = computed(() => menu.value.flatMap(g => g.items).filter(r => (query.value ?? '').trim() === '' || r.label.toLowerCase().includes((query.value ?? '').trim().toLowerCase())))

function go(path: string) {
    open.value = false
    navigateTo(`${appConfig.vector.basePath}${path}`)
}

if (import.meta.client) {
    useEventListener(window, 'keydown', (event: KeyboardEvent) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault()
            open.value = !open.value
        }
    })
}

defineExpose({ open })
</script>

<template>
    <v-dialog v-model="open" max-width="600" scrollable>
        <v-card>
            <v-text-field v-model="query" autofocus variant="solo" flat :placeholder="t('shell.palette.placeholder')" :aria-label="t('common.search')" prepend-inner-icon="mdi-magnify" :loading="loading" hide-details clearable class="flex-grow-0" />
            <v-divider />
            <v-card-text class="pa-0" style="max-height: 420px">
                <v-list density="compact">
                    <template v-if="results.length">
                        <v-list-subheader>{{ t('shell.palette.records') }}</v-list-subheader>
                        <v-list-item v-for="r in results" :key="keyOf(r)" :title="r.title" :subtitle="subtitleOf(r)" @click="go(`/r/${r.resource}/${encodeURIComponent(String(r.id))}`)">
                            <template #prepend>
                                <v-avatar v-if="thumbs[keyOf(r)]" rounded size="32" class="mr-4">
                                    <v-img :src="thumbs[keyOf(r)]" alt="" cover />
                                </v-avatar>
                                <v-icon v-else icon="mdi-file-document-outline" />
                            </template>
                        </v-list-item>
                    </template>
                    <v-list-subheader>{{ t('shell.palette.resources') }}</v-list-subheader>
                    <v-list-item v-for="r in resources" :key="r.slug" :title="r.label" :prepend-icon="r.icon ?? 'mdi-table'" @click="go(`/r/${r.slug}`)" />
                    <template v-if="pageItems.length">
                        <v-list-subheader>{{ t('shell.nav.pages') }}</v-list-subheader>
                        <v-list-item v-for="[name, p] in pageItems" :key="name" :title="p.title" :prepend-icon="p.icon ?? 'mdi-file-document-outline'" @click="go(`/p/${name}`)" />
                    </template>
                    <v-list-item v-if="(query ?? '').trim() && !loading && results.length === 0" :title="t('shell.palette.noMatch')" disabled />
                </v-list>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>
