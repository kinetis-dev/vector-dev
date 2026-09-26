<script setup lang="ts">
import type { VectorListState, VectorResource, VectorSavedView } from '#vector/app/types/vector'

// Saved views: pick one, save the current list as a new one, update or
// delete your own, share, set your default — or, where the policy allows,
// the default of everyone who has none of their own.
const props = defineProps<{ resource: VectorResource, current: VectorListState, activeId: number | null }>()
const emit = defineEmits<{ apply: [view: VectorSavedView | null], 'update:activeId': [id: number | null] }>()

const api = useVectorViews(() => props.resource.slug)
const { t } = useVectorT()
const notifications = useVectorNotifications()
const { confirm } = useConfirm()
const views = ref<VectorSavedView[]>([])
const loading = ref(false)

async function load() {
    loading.value = true
    try {
        views.value = await api.list()
    } finally {
        loading.value = false
    }
}

defineExpose({ views, load })
await load()

const saving = ref(false)
const name = ref('')
const shared = ref(false)
const isDefault = ref(false)
const resourceDefault = ref(false)
const mayDefaultForEveryone = computed(() => props.resource.permissions.setDefaultView === true)
const editing = ref<VectorSavedView | null>(null)
watch(shared, (on) => { if (!on) resourceDefault.value = false })

function openSave(view: VectorSavedView | null) {
    editing.value = view
    name.value = view?.name ?? ''
    shared.value = view?.shared ?? false
    isDefault.value = view?.isDefault ?? false
    resourceDefault.value = view?.resourceDefault ?? false
    saving.value = true
}

async function save() {
    if (name.value.trim() === '') return

    try {
        const view = editing.value
            ? await api.update(editing.value.id, name.value.trim(), props.current, shared.value, isDefault.value, resourceDefault.value)
            : await api.create(name.value.trim(), props.current, shared.value, isDefault.value, resourceDefault.value)
        notifications.success(t('list.views.saved', { name: view.name }))
        saving.value = false
        await load()
        emit('update:activeId', view.id)
    } catch (error) {
        notifications.error(vectorErrorMessage(error))
    }
}

async function remove(view: VectorSavedView) {
    if (!await confirm({ title: t('list.views.deleteTitle', { name: view.name }), confirmText: t('common.delete'), destructive: true })) return

    await api.remove(view.id)
    await load()

    if (props.activeId === view.id) emit('apply', null)
}

const active = computed(() => views.value.find(v => v.id === props.activeId) ?? null)
</script>

<template>
    <v-menu>
        <template #activator="{ props: activator }">
            <v-btn v-bind="activator" size="small" variant="tonal" prepend-icon="mdi-view-list-outline" append-icon="mdi-menu-down">
                {{ active?.name ?? t('list.views.menu') }}
            </v-btn>
        </template>
        <v-list density="compact" min-width="260">
            <v-list-item :title="t('list.views.allRecords')" :active="activeId === null" prepend-icon="mdi-table" @click="emit('apply', null)" />
            <v-divider v-if="views.length" />
            <v-list-item v-for="view in views" :key="view.id" :title="view.name" :active="view.id === activeId" :prepend-icon="view.shared ? 'mdi-account-group-outline' : 'mdi-account-outline'" @click="emit('apply', view)">
                <template #subtitle>
                    <span v-if="view.isDefault">{{ t('list.views.default') }}</span>
                    <span v-else-if="view.resourceDefault">{{ t('list.views.everyoneDefault') }}</span>
                </template>
                <template v-if="view.mine" #append>
                    <v-btn icon="mdi-pencil-outline" size="x-small" variant="text" :aria-label="t('list.views.updateWithCurrent')" @click.stop="openSave(view)" />
                    <v-btn icon="mdi-delete-outline" size="x-small" variant="text" :aria-label="t('list.views.delete')" @click.stop="remove(view)" />
                </template>
            </v-list-item>
            <v-divider />
            <v-list-item :title="t('list.views.saveCurrent')" prepend-icon="mdi-content-save-plus-outline" @click="openSave(null)" />
        </v-list>
    </v-menu>

    <v-dialog v-model="saving" max-width="420">
        <v-card :title="editing ? t('list.views.updateTitle', { name: editing.name }) : t('list.views.saveTitle')">
            <v-card-text>
                <v-text-field v-model="name" :label="t('list.views.name')" autofocus :rules="[(v: string) => v.trim() !== '' || t('common.required')]" @keyup.enter="save" />
                <v-switch v-model="shared" :label="t('list.views.share')" color="primary" hide-details />
                <v-switch v-model="isDefault" :label="t('list.views.openWith')" color="primary" hide-details />
                <v-switch v-if="mayDefaultForEveryone" v-model="resourceDefault" :disabled="!shared" :label="t('list.views.openWithForEveryone')" :hint="shared ? undefined : t('list.views.shareFirst')" persistent-hint color="primary" />
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="saving = false">{{ t('common.cancel') }}</v-btn>
                <v-btn color="primary" @click="save">{{ t('common.save') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
