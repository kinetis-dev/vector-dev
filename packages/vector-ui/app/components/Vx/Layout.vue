<script setup lang="ts">
import type { VectorLayoutNode } from '#vector/app/types/vector'

// A resource's layout (sections, tabs, column spans) around the fields
// the owner renders through the `field` slot: the record form's inputs,
// the show page's values. Layout only groups — it never decides what is
// edited or shown; `shown` does, per field. A section or tab none of
// whose fields is shown is not drawn. A field with an error opens the
// section holding it and activates its tab, which carries a badge.
const props = withDefaults(defineProps<{
    nodes: VectorLayoutNode[]
    /** The grid columns at this level. */
    columns?: number
    shown: (name: string) => boolean
    /** Names of the fields holding an error. */
    errors?: string[]
    /** A field's width when its node does not say. */
    defaultSpan: (name: string) => 1 | 2 | 'full'
}>(), { columns: 2, errors: () => [] })

// the slot prop is `field`, not `name`: on <slot>, `name` picks which slot renders
defineSlots<{ field(props: { field: string }): unknown }>()

function names(node: VectorLayoutNode): string[] {
    return node.kind === 'field' ? [node.name!] : (node.children ?? []).flatMap(names)
}

const visible = (node: VectorLayoutNode) => names(node).some(name => props.shown(name))
const hasError = (node: VectorLayoutNode) => names(node).some(name => props.errors.includes(name))
const visibleNodes = computed(() => props.nodes.map((node, index) => ({ node, key: String(index) })).filter(({ node }) => visible(node)))

/** The md width of a field node on this level's grid. */
function width(node: VectorLayoutNode): number {
    const span = node.span ?? props.defaultSpan(node.name!)
    if (span === 'full') return 12
    return Math.round((12 * Math.min(span, props.columns)) / props.columns)
}

// Sections open unless declared collapsed; tabs start on their first visible tab.
const open = reactive<Record<string, boolean>>(Object.fromEntries(props.nodes.map((node, i) => [String(i), !node.collapsed])))
const active = reactive<Record<string, string | undefined>>({})
const tabsOf = (node: VectorLayoutNode) => (node.children ?? []).filter(visible)

// An error inside a closed section opens it; one in another tab switches to it.
watch(() => props.errors, () => {
    for (const { node, key } of visibleNodes.value) {
        if (node.kind === 'section' && hasError(node)) open[key] = true

        if (node.kind === 'tabs') {
            const tabs = tabsOf(node)
            const current = tabs.find(tab => tab.label === (active[key] ?? tabs[0]?.label))
            const failing = tabs.find(hasError)
            if (failing && (!current || !hasError(current))) active[key] = failing.label ?? undefined
        }
    }
}, { deep: true, immediate: true })
</script>

<template>
    <v-row density="compact">
        <template v-for="{ node, key } in visibleNodes" :key="key">
            <v-col v-if="node.kind === 'field'" cols="12" :md="width(node)" :data-layout-field="node.name">
                <slot name="field" :field="node.name!" />
            </v-col>

            <v-col v-else-if="node.kind === 'section'" cols="12" class="vx-layout-section" :data-layout-section="node.label">
                <v-expansion-panels v-if="node.collapsible" :model-value="open[key] ? [0] : []" variant="accordion" @update:model-value="(v: unknown) => open[key] = Array.isArray(v) && v.length > 0">
                    <v-expansion-panel :value="0">
                        <v-expansion-panel-title>
                            <span class="font-weight-medium">{{ node.label }}</span>
                            <v-icon v-if="hasError(node)" icon="mdi-alert-circle" color="error" size="small" class="ms-2" />
                        </v-expansion-panel-title>
                        <v-expansion-panel-text eager>
                            <p v-if="node.description" class="text-body-2 text-medium-emphasis mb-4">{{ node.description }}</p>
                            <VxLayout :nodes="node.children ?? []" :columns="node.columns ?? 2" :shown="shown" :errors="errors" :default-span="defaultSpan">
                                <template #field="{ field }"><slot name="field" :field="field" /></template>
                            </VxLayout>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
                <template v-else>
                    <div class="text-subtitle-1 font-weight-medium mt-2" :class="node.description ? 'mb-1' : 'mb-4'">{{ node.label }}</div>
                    <p v-if="node.description" class="text-body-2 text-medium-emphasis mb-4">{{ node.description }}</p>
                    <VxLayout :nodes="node.children ?? []" :columns="node.columns ?? 2" :shown="shown" :errors="errors" :default-span="defaultSpan">
                        <template #field="{ field }"><slot name="field" :field="field" /></template>
                    </VxLayout>
                </template>
            </v-col>

            <v-col v-else-if="node.kind === 'tabs'" cols="12" class="vx-layout-tabs">
                <div v-if="node.label" class="text-subtitle-1 font-weight-medium mt-2">{{ node.label }}</div>
                <v-tabs :model-value="active[key] ?? tabsOf(node)[0]?.label ?? undefined" density="compact" color="primary" @update:model-value="(v: unknown) => active[key] = String(v)">
                    <v-tab v-for="tab in tabsOf(node)" :key="tab.label ?? ''" :value="tab.label" :data-layout-tab="tab.label">
                        {{ tab.label }}
                        <v-badge v-if="hasError(tab)" dot inline color="error" class="ms-1" />
                    </v-tab>
                </v-tabs>
                <v-divider class="mb-2" />
                <!-- eager: an inactive tab's inputs stay mounted, so the form validates and keeps them -->
                <v-tabs-window :model-value="active[key] ?? tabsOf(node)[0]?.label ?? undefined">
                    <!-- padded: the window clips its content, and an outlined field's label sits above its border -->
                    <v-tabs-window-item v-for="tab in tabsOf(node)" :key="tab.label ?? ''" :value="tab.label" eager class="pt-3">
                        <VxLayout :nodes="tab.children ?? []" :columns="tab.columns ?? 2" :shown="shown" :errors="errors" :default-span="defaultSpan">
                            <template #field="{ field }"><slot name="field" :field="field" /></template>
                        </VxLayout>
                    </v-tabs-window-item>
                </v-tabs-window>
            </v-col>
        </template>
    </v-row>
</template>
