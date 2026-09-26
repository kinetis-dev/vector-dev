import type { Component } from 'vue'

/** A custom page: a menu entry and a route, `/p/{name}`, rendering the component. */
export interface VectorPageDefinition {
    title: string
    icon?: string
    /** The menu section it appears under; the "Pages" section when omitted. */
    group?: string
    component: Component | string
}

/**
 * The registries runtime extensions fill through `window.Vector`:
 * cells, inputs, widget renderers and custom pages by name. Reactive, so
 * something registered after boot shows up without a reload. `ready`
 * turns true once every extension module has loaded (or failed to).
 */
export function useVectorExtensions() {
    const cells = useState<Map<string, Component>>('vector.ext.cells', () => shallowReactive(new Map()))
    const inputs = useState<Map<string, Component>>('vector.ext.inputs', () => shallowReactive(new Map()))
    const widgets = useState<Map<string, Component>>('vector.ext.widgets', () => shallowReactive(new Map()))
    const pages = useState<Map<string, VectorPageDefinition>>('vector.ext.pages', () => shallowReactive(new Map()))
    const ready = useState<boolean>('vector.ext.ready', () => false)

    return { cells: cells.value, inputs: inputs.value, widgets: widgets.value, pages: pages.value, ready }
}

/**
 * Every custom page: the application's own from app config
 * `vector.pages` (a globally registered component by name) and the ones
 * runtime extensions defined.
 */
export function useVectorPages() {
    const appConfig = useAppConfig()
    const { pages } = useVectorExtensions()

    return computed<Record<string, VectorPageDefinition>>(() => ({
        ...(appConfig.vector.pages ?? {}),
        ...Object.fromEntries(pages.entries()),
    }))
}
