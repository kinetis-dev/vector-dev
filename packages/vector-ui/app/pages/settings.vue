<script setup lang="ts">
import type { SubmitEventPromise } from 'vuetify'
import type { VectorSettingsGroup } from '#vector/app/types/vector'

definePageMeta({ layout: 'vector', middleware: 'vector-auth' })

// Every settings group this user may manage, one tab each, rendered
// from the group's field list through the same registry as a form.
const api = useVectorApi()
const notifications = useVectorNotifications()
const { t } = useVectorT()
const { load: loadSchema } = useVectorSchema()

const { data, refresh } = await useAsyncData('vector.settings', () => api<{ data: VectorSettingsGroup[] }>('/vector/settings'))
const groups = computed(() => data.value?.data ?? [])
const tab = ref<string | null>(null)
const values = reactive<Record<string, Record<string, unknown>>>({})
const serverErrors = reactive<Record<string, Record<string, string[]>>>({})
const formError = ref<string | null>(null)
const saving = ref(false)

watch(groups, (list) => {
    for (const group of list) {
        values[group.name] = { ...group.values }
        serverErrors[group.name] ??= {}
    }
    tab.value ??= list[0]?.name ?? null
}, { immediate: true })

useHead({ title: () => t('settings.pageTitle') })

async function save(group: VectorSettingsGroup, event: SubmitEventPromise) {
    const { valid } = await event
    if (!valid) return

    saving.value = true
    formError.value = null
    const errors = serverErrors[group.name]!
    for (const key of Object.keys(errors)) delete errors[key]

    try {
        await api(`/vector/settings/${encodeURIComponent(group.name)}`, { method: 'PUT', body: values[group.name] })
        notifications.success(t('settings.saved', { label: group.label }))
        await refresh()
        await loadSchema(true) // branding rides with the descriptor
    } catch (error) {
        formError.value = applyViolations(error, values[group.name]!, errors)
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div>
        <h1 class="text-h4 mb-4">{{ t('settings.heading') }}</h1>
        <v-empty-state v-if="groups.length === 0" icon="mdi-cog-off-outline" :title="t('settings.empty.title')" :text="t('settings.empty.text')" />
        <v-card v-else>
            <v-tabs v-model="tab" color="primary">
                <v-tab v-for="group in groups" :key="group.name" :value="group.name" :prepend-icon="group.icon ?? undefined">{{ group.label }}</v-tab>
            </v-tabs>
            <v-divider />
            <v-tabs-window v-model="tab">
                <v-tabs-window-item v-for="group in groups" :key="group.name" :value="group.name">
                    <v-form validate-on="blur lazy" @submit.prevent="(e: SubmitEventPromise) => save(group, e)">
                        <v-card-text>
                            <v-alert v-if="formError && tab === group.name" type="error" variant="tonal" class="mb-4" :text="formError" />
                            <VxFieldsForm v-if="values[group.name]" :fields="group.fields" :values="values[group.name]!" :server-errors="serverErrors[group.name] ?? {}" @clear="(name: string) => delete serverErrors[group.name]![name]" />
                        </v-card-text>
                        <v-card-actions>
                            <v-spacer />
                            <v-btn type="submit" color="primary" :loading="saving">{{ t('common.save') }}</v-btn>
                        </v-card-actions>
                    </v-form>
                </v-tabs-window-item>
            </v-tabs-window>
        </v-card>
    </div>
</template>
