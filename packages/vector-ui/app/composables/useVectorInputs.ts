import type { Component } from 'vue'
import type { VectorField } from '#vector/app/types/vector'

/**
 * The input side of the field registry: the component that edits a
 * field in a form. Built-ins are imported explicitly, like the cells;
 * `app.config.vector.inputs` names globally registered overrides.
 */
const inputs: Record<string, Component> = {
    string: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Text.vue')),
    email: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Text.vue')),
    url: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Text.vue')),
    slug: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Text.vue')),
    password: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Text.vue')),
    color: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Text.vue')),
    file: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Upload.vue')),
    image: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Upload.vue')),
    text: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Textarea.vue')),
    markdown: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Textarea.vue')),
    json: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Textarea.vue')),
    keyValue: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/KeyValue.vue')),
    number: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Number.vue')),
    money: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Money.vue')),
    boolean: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Boolean.vue')),
    enum: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Enum.vue')),
    currency: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Currency.vue')),
    date: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/DateTime.vue')),
    datetime: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/DateTime.vue')),
    belongsTo: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Relation.vue')),
    manyToMany: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Relation.vue')),
    hasMany: defineAsyncComponent(() => import('#vector/app/components/Vx/Input/Repeater.vue')),
}

export function useVectorInputs() {
    const appConfig = useAppConfig()

    function inputFor(field: VectorField): Component | string {
        const overrides = appConfig.vector.inputs

        if (field.component && overrides[field.component]) {
            return overrides[field.component]!
        }

        const runtime = useVectorExtensions().inputs

        if (field.component && runtime.has(field.component)) {
            return runtime.get(field.component)!
        }

        return overrides[field.type] ?? inputs[field.type] ?? inputs.string!
    }

    /** Whether a field is edited in this operation at all. */
    function editable(field: VectorField, operation: 'create' | 'update'): boolean {
        if (field.type === 'hasMany') return !!field.repeater && field.views.includes(operation)
        return !field.readonly && field.views.includes(operation) && !['id', 'hasOne'].includes(field.type)
    }

    return { inputFor, editable }
}
