import type { Component } from 'vue'
import type { AnyExtension } from '@tiptap/core'

/**
 * A custom page's title or menu section: a string, or a function returning
 * one — `() => t('stock.title')` — called each time it is shown, so it
 * follows a change of language.
 */
export type VectorPageLabel = string | (() => string)

/** A custom page: a menu entry and a route, `/p/{name}`, rendering the component. */
export interface VectorPageDefinition {
    title: VectorPageLabel
    icon?: string
    /** The menu section it appears under; the "Pages" section when omitted. */
    group?: VectorPageLabel
    component: Component | string
}

/** The text of a page's title or group: a function is called now, inside whatever computed or render reads it. */
export function pageLabel(label: VectorPageLabel): string
export function pageLabel(label: VectorPageLabel | undefined): string | undefined
export function pageLabel(label: VectorPageLabel | undefined): string | undefined {
    return typeof label === 'function' ? label() : label
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
    // rich-text node and mark types, by the feature name a field's `features` uses
    const richText = useState<Map<string, AnyExtension>>('vector.ext.richText', () => shallowReactive(new Map()))
    const ready = useState<boolean>('vector.ext.ready', () => false)

    return { cells: cells.value, inputs: inputs.value, widgets: widgets.value, pages: pages.value, richText: richText.value, ready }
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
        ...appConfig.vector.pages,
        ...Object.fromEntries(pages.entries()),
    }))
}
