<script setup lang="ts">
import type { VectorAction, VectorResource } from '#vector/app/types/vector'

// What a selection can do: the built-in edit, delete, restore and
// force-delete of the resource and every index action. "All matching" widens the
// selection from the checked rows to every record the list matches.
defineProps<{
    resource: VectorResource
    selected: number
    total: number
    allMatching: boolean
    trashedOnly: boolean
    actions: VectorAction[]
}>()

const emit = defineEmits<{
    'update:allMatching': [value: boolean]
    clear: []
    operation: [op: 'delete' | 'restore' | 'forceDelete']
    action: [action: VectorAction]
    edit: []
}>()

const { t } = useVectorT()
</script>

<template>
    <v-sheet color="primary" rounded class="d-flex align-center flex-wrap ga-2 px-4 py-2 mb-2">
        <span class="font-weight-medium">{{ allMatching ? t('list.bulk.allMatching', { total }) : t('list.bulk.selected', { count: selected }) }}</span>
        <v-btn v-if="!allMatching && total > selected" size="small" variant="outlined" @click="emit('update:allMatching', true)">{{ t('list.bulk.selectAllMatching', { total }) }}</v-btn>
        <v-btn v-else-if="allMatching" size="small" variant="outlined" @click="emit('update:allMatching', false)">{{ t('list.bulk.onlyChecked') }}</v-btn>
        <v-spacer />
        <template v-if="resource.softDelete && trashedOnly">
            <v-btn size="small" variant="flat" color="white" prepend-icon="mdi-restore" @click="emit('operation', 'restore')">{{ t('common.restore') }}</v-btn>
            <v-btn size="small" variant="flat" color="error" prepend-icon="mdi-delete-forever-outline" @click="emit('operation', 'forceDelete')">{{ t('common.deleteForever') }}</v-btn>
        </template>
        <template v-else>
            <v-btn v-if="resource.permissions.update" size="small" variant="flat" color="white" prepend-icon="mdi-pencil-box-multiple-outline" @click="emit('edit')">{{ t('common.edit') }}</v-btn>
            <v-btn v-if="resource.permissions.delete" size="small" variant="flat" color="white" prepend-icon="mdi-delete-outline" @click="emit('operation', 'delete')">{{ t('common.delete') }}</v-btn>
        </template>
        <v-btn v-for="action in actions" :key="action.name" size="small" variant="flat" color="white" :prepend-icon="action.icon ?? undefined" @click="emit('action', action)">{{ action.label }}</v-btn>
        <v-btn size="small" variant="text" icon="mdi-close" :aria-label="t('list.bulk.clearSelection')" @click="emit('clear')" />
    </v-sheet>
</template>
