import type { Component } from 'vue'
import type { VectorField } from '#vector/app/types/vector'

/**
 * The field registry: a descriptor field resolves to the component that
 * renders it as a table cell or a show-page value.
 *
 * Built-in cells are imported explicitly — a dynamic `<component :is>`
 * defeats template-time auto-import, so nothing here relies on it. A
 * consuming app adds or replaces entries through `app.config.vector.fields`
 * (keyed by a field's explicit `component` name or by its `type`), naming
 * a component it registered globally in a plugin.
 */
const cells: Record<string, Component> = {
    id: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Text.vue')),
    string: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Text.vue')),
    text: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Text.vue')),
    markdown: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Markdown.vue')),
    richtext: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/RichText.vue')),
    number: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Number.vue')),
    money: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Money.vue')),
    boolean: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Boolean.vue')),
    enum: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Enum.vue')),
    date: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Date.vue')),
    datetime: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/DateTime.vue')),
    email: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Email.vue')),
    url: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Url.vue')),
    password: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Text.vue')),
    slug: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Text.vue')),
    color: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Color.vue')),
    json: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Json.vue')),
    keyValue: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/KeyValue.vue')),
    file: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/File.vue')),
    image: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Image.vue')),
    belongsTo: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Relation.vue')),
    hasOne: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Relation.vue')),
    hasMany: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Count.vue')),
    manyToMany: defineAsyncComponent(() => import('#vector/app/components/Vx/Cell/Count.vue')),
}

export function useVectorFields() {
    const appConfig = useAppConfig()

    /** The cell for a field: an app override by name, then the built-in for its type. */
    function cellFor(field: VectorField): Component | string {
        const overrides = appConfig.vector.fields
        const runtime = useVectorExtensions().cells

        if (field.component && overrides[field.component]) {
            return overrides[field.component]!
        }

        if (field.component && runtime.has(field.component)) {
            return runtime.get(field.component)!
        }

        if (overrides[field.type]) {
            return overrides[field.type]!
        }

        // a computed value is shown by the cell of what it is computed as
        const type = field.type === 'computed' ? (field.computed?.as === 'text' ? 'string' : field.computed?.as ?? 'string') : field.type

        return cells[type] ?? cells.string!
    }

    return { cellFor }
}
