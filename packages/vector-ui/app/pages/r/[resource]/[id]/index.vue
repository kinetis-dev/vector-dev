<script setup lang="ts">
import type { VectorAction } from '#vector/app/types/vector'

definePageMeta({ layout: 'vector', middleware: 'vector-auth', key: route => route.fullPath })

const { t } = useVectorT()
const route = useRoute()
const appConfig = useAppConfig()
const { load, resource: find } = useVectorSchema()
await load()

const slug = computed(() => String(route.params.resource))
const id = computed(() => String(route.params.id))
const resource = computed(() => find(slug.value))
const client = useVectorResource(slug)
const base = computed(() => `${appConfig.vector.basePath}/r/${slug.value}`)
const { confirm } = useConfirm()
const notifications = useVectorNotifications()

const { data: response, status, error, refresh } = await useAsyncData(() => `vector.${slug.value}.${id.value}`, () => client.show(id.value))

const title = computed(() => {
    const value = response.value?.data?.[resource.value?.titleField ?? 'id']
    return value === undefined || value === null ? `#${id.value}` : String(value)
})

const trashed = computed(() => response.value?.data?._trashed === true)
const { schema } = useVectorSchema()
const impersonation = useVectorImpersonation()
const canImpersonate = computed(() => appConfig.vector.usersResource === slug.value && schema.value?.capabilities.impersonation === true && String(response.value?.data.id) !== String(useVectorAuthStore().user?.id))

async function impersonate() {
    if (!resource.value || !response.value) return

    if (!await confirm({ title: t('record.impersonate.title', { title: title.value }), text: t('record.impersonate.text'), confirmText: t('record.impersonate.action') })) return

    try {
        await impersonation.start(response.value.data.id)
        notifications.success(t('record.impersonate.started', { title: title.value }))
        await navigateTo(appConfig.vector.basePath || '/')
    } catch (caught) {
        notifications.error(vectorErrorMessage(caught))
    }
}
// Every collection the show page shows gets a relation panel.
const collections = computed(() => (resource.value?.fields ?? []).filter(f => (f.type === 'hasMany' || f.type === 'manyToMany') && f.views.includes('show')))
const actions = computed<VectorAction[]>(() => (resource.value?.actions ?? []).filter(a => a.showOnDetail && !a.standalone && (response.value?.permissions.actions ?? []).includes(a.name)))
const dialogAction = ref<VectorAction | null>(null)

useHead({ title: () => resource.value ? `${title.value} · ${resource.value.label}` : 'Vector' })

async function remove(force = false) {
    if (!resource.value) return
    const soft = resource.value.softDelete !== null && !force

    const ok = await confirm({
        title: t(force ? 'record.deleteForeverTitle' : 'record.deleteTitle', { noun: resource.value.labelSingular.toLowerCase(), title: title.value }),
        text: soft ? t('record.deleteSoftText') : t('record.deleteHardText'),
        confirmText: t('common.delete'),
        destructive: true,
    })

    if (!ok) return

    try {
        if (force) await client.forceRemove(id.value)
        else await client.remove(id.value)
        notifications.success(t(soft ? 'record.trashed' : 'record.deleted', { label: resource.value.labelSingular }))

        if (soft) await refresh()
        else await navigateTo(base.value)
    } catch (caught) {
        notifications.error(vectorErrorMessage(caught))
    }
}

async function restore() {
    if (!resource.value) return

    try {
        await client.restore(id.value)
        notifications.success(t('record.restored', { label: resource.value.labelSingular }))
        await refresh()
    } catch (caught) {
        notifications.error(vectorErrorMessage(caught))
    }
}
</script>

<template>
    <div v-if="resource">
        <v-breadcrumbs class="pa-0 mb-2" :items="[{ title: resource.label, to: base }, { title }]" />
        <div class="d-flex align-center flex-wrap ga-2 mb-4">
            <h1 class="text-h4">{{ title }}</h1>
            <v-chip size="small" variant="tonal">{{ resource.labelSingular }}</v-chip>
            <v-chip v-if="trashed" size="small" color="error" variant="tonal" prepend-icon="mdi-delete-clock-outline">{{ t('record.inTrash') }}</v-chip>
            <v-spacer />
            <template v-if="response">
                <v-menu v-if="actions.length">
                    <template #activator="{ props: activator }">
                        <v-btn v-bind="activator" variant="tonal" append-icon="mdi-menu-down">{{ t('common.actions') }}</v-btn>
                    </template>
                    <v-list density="compact">
                        <v-list-item v-for="action in actions" :key="action.name" :title="action.label" :prepend-icon="action.icon ?? undefined" :base-color="action.destructive ? 'error' : undefined" @click="dialogAction = action" />
                    </v-list>
                </v-menu>
                <v-btn v-if="canImpersonate" prepend-icon="mdi-account-switch-outline" variant="tonal" @click="impersonate">{{ t('record.impersonate.action') }}</v-btn>
                <v-btn v-if="response.permissions.update" :to="`${base}/${id}/edit`" prepend-icon="mdi-pencil-outline" color="primary">{{ t('common.edit') }}</v-btn>
                <v-btn v-if="response.permissions.restore" prepend-icon="mdi-restore" color="primary" variant="tonal" @click="restore">{{ t('common.restore') }}</v-btn>
                <v-btn v-if="response.permissions.forceDelete" prepend-icon="mdi-delete-forever-outline" color="error" variant="tonal" @click="remove(true)">{{ t('common.deleteForever') }}</v-btn>
                <v-btn v-if="response.permissions.delete" prepend-icon="mdi-delete-outline" color="error" variant="tonal" @click="remove()">{{ t('common.delete') }}</v-btn>
            </template>
        </div>

        <v-skeleton-loader v-if="status === 'pending'" type="article" />
        <v-alert v-else-if="error" type="error" variant="tonal" :text="vectorErrorMessage(error)" />
        <template v-else-if="response">
            <VxResourceShow :resource="resource" :record="response.data" />
            <VxResourceRelationPanel
                v-for="field in collections"
                :key="field.name"
                :owner="resource"
                :record="response.data"
                :field="field"
                :can-update="response.permissions.update"
                @changed="refresh()"
            />
            <VxResourceAudit :resource="resource" :id="id" :can-update="response.permissions.update" @reverted="refresh()" />
        </template>

        <VxActionDialog
            v-if="dialogAction && response"
            :resource="resource"
            :action="dialogAction"
            :selection="{ ids: [response.data.id] }"
            :count="1"
            @done="dialogAction = null; refresh()"
            @cancel="dialogAction = null"
        />
    </div>
    <v-empty-state v-else icon="mdi-help-circle-outline" :title="t('record.unknownResource')" />
</template>
