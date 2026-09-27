<script setup lang="ts">
import type { VectorField, VectorLayoutNode, VectorRecord, VectorResource } from '#vector/app/types/vector'

const props = defineProps<{ resource: VectorResource, record: VectorRecord }>()

const fields = computed<VectorField[]>(() => props.resource.show.fields
    .map(name => props.resource.fields.find(f => f.name === name))
    .filter((f): f is VectorField => f !== undefined))
const fieldsByName = computed(() => new Map(fields.value.map(f => [f.name, f])))

const wide = (field: VectorField) => ['markdown', 'text', 'json'].includes(field.type)

// The declared layout, or every field in order; either way VxLayout draws it.
const layout = computed<VectorLayoutNode[]>(() => props.resource.layout?.show
    ?? fields.value.map(f => ({ kind: 'field', label: null, description: null, collapsible: false, collapsed: false, columns: null, span: null, name: f.name, children: null })))
const defaultSpan = (name: string) => {
    const field = fieldsByName.value.get(name)
    return field && wide(field) ? 'full' as const : 1 as const
}
</script>

<template>
    <v-card>
        <v-card-text>
            <VxLayout :nodes="layout" :shown="name => fieldsByName.has(name)" :default-span="defaultSpan">
                <template #field="{ field: name }">
                    <div class="text-caption text-medium-emphasis">{{ fieldsByName.get(name)!.label }}</div>
                    <div class="text-body-1">
                        <VxCell :field="fieldsByName.get(name)!" :value="record[name]" :record="record" :resource="resource" context="show" />
                    </div>
                </template>
            </VxLayout>
        </v-card-text>
    </v-card>
</template>
