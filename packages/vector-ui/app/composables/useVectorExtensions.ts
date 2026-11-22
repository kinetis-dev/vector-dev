import type { Component } from 'vue'
import type { AnyExtension } from '@tiptap/core'

/**
 * A custom page's title or menu section: a string, or a function returning
 * one, `() => t('stock.title')`, called each time it is shown, so it
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

/** Where an input addon renders: in a row under the input, or inside the input at its inline end. */
export type VectorInputAddonPlacement = 'below' | 'inner'

/** The placements defineInputAddon() accepts. */
export const INPUT_ADDON_PLACEMENTS: readonly VectorInputAddonPlacement[] = ['below', 'inner']

/** An addon rendered with every form input of the listed field types. */
export interface VectorInputAddon {
    /** Field types (`string`, `text`, `richtext`, …) whose inputs get the addon. */
    types: string[]
    component: Component
    /**
     * `below` (the default): in a row under the input, in the record form and a loose field list.
     * `inner`: inside the input itself, at its inline end (Vuetify's `append-inner`); only the
     * text and textarea inputs render it.
     */
    placement: VectorInputAddonPlacement
}

/** A command palette entry under "Commands": run() is given what was typed. */
export interface VectorPaletteCommand {
    title: VectorPageLabel
    icon?: string
    run: (query: string) => unknown
}

/**
 * The registries runtime extensions and layers fill through
 * `window.Vector` or useVectorExtend(): cells, inputs, widget renderers
 * and custom pages by name (v1), and the shell's own slots (v2): app-bar
 * actions, list-toolbar items, input and editor-toolbar addons, palette
 * commands and settings-group addons. Reactive, so something registered
 * after boot shows up without a reload. `ready` turns true once every
 * extension module has loaded (or failed to).
 */
export function useVectorExtensions() {
    const cells = useState<Map<string, Component>>('vector.ext.cells', () => shallowReactive(new Map()))
    const inputs = useState<Map<string, Component>>('vector.ext.inputs', () => shallowReactive(new Map()))
    const widgets = useState<Map<string, Component>>('vector.ext.widgets', () => shallowReactive(new Map()))
    const pages = useState<Map<string, VectorPageDefinition>>('vector.ext.pages', () => shallowReactive(new Map()))
    // rich-text node and mark types, by the feature name a field's `features` uses
    const richText = useState<Map<string, AnyExtension>>('vector.ext.richText', () => shallowReactive(new Map()))
    const shellActions = useState<Map<string, Component>>('vector.ext.shellActions', () => shallowReactive(new Map()))
    const listToolbar = useState<Map<string, Component>>('vector.ext.listToolbar', () => shallowReactive(new Map()))
    const inputAddons = useState<Map<string, VectorInputAddon>>('vector.ext.inputAddons', () => shallowReactive(new Map()))
    const editorToolbar = useState<Map<string, Component>>('vector.ext.editorToolbar', () => shallowReactive(new Map()))
    const paletteCommands = useState<Map<string, VectorPaletteCommand>>('vector.ext.paletteCommands', () => shallowReactive(new Map()))
    // by settings group name: one addon each, rendered under the group's form
    const settingsAddons = useState<Map<string, Component>>('vector.ext.settingsAddons', () => shallowReactive(new Map()))
    const ready = useState<boolean>('vector.ext.ready', () => false)

    return {
        cells: cells.value,
        inputs: inputs.value,
        widgets: widgets.value,
        pages: pages.value,
        richText: richText.value,
        shellActions: shellActions.value,
        listToolbar: listToolbar.value,
        inputAddons: inputAddons.value,
        editorToolbar: editorToolbar.value,
        paletteCommands: paletteCommands.value,
        settingsAddons: settingsAddons.value,
        ready,
    }
}

/** The input addons for one field type at one placement (`below` by default), in registration order. */
export function useVectorInputAddons(type: () => string, placement: () => VectorInputAddonPlacement = () => 'below') {
    const { inputAddons } = useVectorExtensions()

    return computed(() => [...inputAddons.entries()]
        .filter(([, addon]) => addon.types.includes(type()) && addon.placement === placement())
        .map(([name, addon]) => ({ name, component: addon.component })))
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
