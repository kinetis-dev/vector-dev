<script setup lang="ts">
import type { VectorField, VectorRecord, VectorResource } from '#vector/app/types/vector'

const props = defineProps<{ resource: VectorResource, record: VectorRecord }>()

const fields = computed<VectorField[]>(() => props.resource.show.fields
    .map(name => props.resource.fields.find(f => f.name === name))
    .filter((f): f is VectorField => f !== undefined))

const wide = (field: VectorField) => ['markdown', 'text', 'json'].includes(field.type)
</script>

<template>
    <v-card>
        <v-card-text>
            <v-row density="compact">
                <v-col v-for="field in fields" :key="field.name" cols="12" :md="wide(field) ? 12 : 6">
                    <div class="text-caption text-medium-emphasis">{{ field.label }}</div>
                    <div class="text-body-1">
                        <VxCell :field="field" :value="record[field.name]" :record="record" :resource="resource" context="show" />
                    </div>
                </v-col>
            </v-row>
        </v-card-text>
    </v-card>
</template>
