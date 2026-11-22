<script setup lang="ts">
// The provider keys of the `ai` settings group: what is set, by whom and
// when, and which keys the environment manages. A key is entered here and
// sent once; the panel never shows a stored one, and no answer carries it.
// Nothing renders without the descriptor's `capabilities.ai`.

interface KeyStatus {
    set: boolean
    managedBy: 'environment' | 'admin' | null
    storable: boolean
    updatedAt?: string
    updatedBy?: string
    sealedWithAnotherKey?: boolean
    // why a stored key cannot be used: sealed with another key, or no VECTOR_AI_KEY to open it
    problem?: string
}

defineProps<{ group: string }>()

const { schema } = useVectorSchema()
const api = useVectorApi()
const notifications = useVectorNotifications()
const { t } = useVectorT()

const enabled = computed(() => schema.value?.capabilities?.ai !== undefined)
const statuses = ref<Record<string, KeyStatus> | null>(null)
const failure = ref<string | null>(null)
const entered = reactive<Record<string, string>>({})
const busy = ref<string | null>(null)

async function load() {
    try {
        statuses.value = await api<Record<string, KeyStatus>>('/vector/ai/credentials')
        failure.value = null
    } catch (error) {
        failure.value = vectorErrorMessage(error)
    }
}

watch(enabled, (on) => { if (on) void load() }, { immediate: true })

async function save(provider: string) {
    const key = (entered[provider] ?? '').trim()
    if (key === '' || statuses.value === null) return
    busy.value = provider

    try {
        statuses.value[provider] = await api<KeyStatus>(`/vector/ai/credentials/${encodeURIComponent(provider)}`, { method: 'PUT', body: { key } })
        notifications.success(t('ai.credentials.saved', { provider }))
    } catch (error) {
        notifications.error(vectorErrorMessage(error))
    } finally {
        // the key leaves the page either way
        entered[provider] = ''
        busy.value = null
    }
}

async function remove(provider: string) {
    if (statuses.value === null) return
    busy.value = provider

    try {
        statuses.value[provider] = await api<KeyStatus>(`/vector/ai/credentials/${encodeURIComponent(provider)}`, { method: 'DELETE' })
        notifications.success(t('ai.credentials.removed', { provider }))
    } catch (error) {
        notifications.error(vectorErrorMessage(error))
    } finally {
        busy.value = null
    }
}
</script>

<template>
    <v-card-text v-if="enabled" class="vx-ai-credentials" data-ai-credentials>
        <h2 class="text-subtitle-1 font-weight-medium mb-1">{{ t('ai.credentials.title') }}</h2>
        <p class="text-body-2 text-medium-emphasis mb-3">{{ t('ai.credentials.intro') }}</p>
        <v-alert v-if="failure" type="error" variant="tonal" density="compact" :text="failure" />
        <p v-else-if="statuses && Object.keys(statuses).length === 0" class="text-body-2" data-ai-no-provider>{{ t('ai.credentials.none') }}</p>
        <v-list v-else-if="statuses" density="compact" lines="two">
            <v-list-item v-for="(status, provider) in statuses" :key="provider" :data-ai-provider="provider">
                <v-list-item-title class="d-flex align-center ga-2">
                    <strong>{{ provider }}</strong>
                    <v-chip v-if="status.managedBy === 'environment'" size="x-small" variant="tonal" data-ai-managed="environment">{{ t('ai.credentials.environment') }}</v-chip>
                    <v-chip v-else-if="status.managedBy === null" size="x-small" variant="tonal">{{ t('ai.credentials.noKey') }}</v-chip>
                    <v-chip v-else size="x-small" variant="tonal" :color="status.set && status.storable && !status.sealedWithAnotherKey ? 'success' : undefined" :data-ai-set="status.set">{{ status.set ? t('ai.credentials.set') : t('ai.credentials.unset') }}</v-chip>
                </v-list-item-title>
                <v-list-item-subtitle v-if="status.managedBy === 'admin'">
                    <span v-if="status.sealedWithAnotherKey" class="text-warning">{{ t('ai.credentials.sealedWithAnotherKey') }}</span>
                    <!-- without VECTOR_AI_KEY nothing is stored, and a key stored before cannot be opened -->
                    <span v-else-if="!status.storable" :class="{ 'text-warning': status.set }" data-ai-cannot-store>{{ t('ai.credentials.cannotStore') }}</span>
                    <span v-else-if="status.set && status.updatedAt">{{ t('ai.credentials.updated', { by: status.updatedBy ?? '?', at: formatDateTime(status.updatedAt) }) }}</span>
                </v-list-item-subtitle>
                <div v-if="status.managedBy === 'admin' && status.storable" class="d-flex align-center ga-2 mt-2">
                    <v-text-field
                        v-model="entered[provider]"
                        type="password"
                        autocomplete="off"
                        density="compact"
                        hide-details
                        :label="t('ai.credentials.enter', { provider })"
                        style="max-width: 360px"
                        @keydown.enter.prevent="save(String(provider))"
                    />
                    <v-btn size="small" color="primary" :loading="busy === provider" :disabled="!(entered[provider] ?? '').trim()" @click="save(String(provider))">{{ t('ai.credentials.save') }}</v-btn>
                    <v-btn v-if="status.set" size="small" variant="text" :disabled="busy === provider" @click="remove(String(provider))">{{ t('ai.credentials.remove') }}</v-btn>
                </div>
            </v-list-item>
        </v-list>
    </v-card-text>
</template>
