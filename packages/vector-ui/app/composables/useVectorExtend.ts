import { markRaw, readonly, type Component, type DeepReadonly, type Ref } from 'vue'
import type { AnyExtension } from '@tiptap/core'
import {
    VAlert, VAutocomplete, VBadge, VBtn, VCard, VCheckbox, VChip, VDialog, VDivider, VExpansionPanel, VExpansionPanels, VExpansionPanelText,
    VExpansionPanelTitle, VIcon, VList, VListItem, VMenu, VNavigationDrawer, VPagination, VProgressCircular, VProgressLinear, VRating, VSelect,
    VSkeletonLoader, VSwitch, VTab, VTable, VTabs, VTabsWindow, VTabsWindowItem, VTextField, VTextarea, VToolbar, VTooltip,
} from 'vuetify/components'
import type { VectorSchema } from '../types/vector'
import { INPUT_ADDON_PLACEMENTS, type VectorInputAddonPlacement, type VectorPageLabel, type VectorPaletteCommand } from './useVectorExtensions'
import { markdownHtml, sanitizeHtml } from '../utils/richtext'

/**
 * Every Vuetify component an extension can reach without a bundler of its
 * own: tree-shaking keeps only what is imported here. VTable for a custom
 * page's tables, VNavigationDrawer, VToolbar, VProgressCircular and VBadge
 * for a shell action's side panel.
 */
export const VECTOR_VUETIFY: Record<string, Component> = {
    VAlert, VAutocomplete, VBadge, VBtn, VCard, VCheckbox, VChip, VDialog, VDivider, VExpansionPanel, VExpansionPanels, VExpansionPanelText,
    VExpansionPanelTitle, VIcon, VList, VListItem, VMenu, VNavigationDrawer, VPagination, VProgressCircular, VProgressLinear, VRating, VSelect,
    VSkeletonLoader, VSwitch, VTab, VTable, VTabs, VTabsWindow, VTabsWindowItem, VTextField, VTextarea, VToolbar, VTooltip,
}

/** Where the admin is: the path under its base path, and the resource and record a page is about. */
export interface VectorContext {
    path: string
    resource?: string
    recordId?: string
}

/** What the admin lends an extension: its descriptor, where it is, its language and its HTML helpers. */
export interface VectorHost {
    /** The loaded descriptor, null before the first load; read-only. */
    schema: DeepReadonly<Ref<VectorSchema | null>>
    context: () => VectorContext
    /** The language the admin is shown in, a BCP 47 tag. */
    locale: () => string
    /** Markdown rendered to sanitized HTML, as the admin renders a markdown field. */
    markdownHtml: (text: string) => string
    /** HTML with everything the admin would not render removed. */
    sanitizeHtml: (html: string) => string
    vuetify: Record<string, Component>
}

const NAME = /^[a-z0-9][a-z0-9-]*$/

function checkName(kind: string, name: string): void {
    if (!NAME.test(name)) throw new Error(`Vector ${kind} name "${name}" must be lowercase letters, digits and dashes.`)
}

/**
 * The admin's host services, for a layer or a runtime extension. Call it
 * where Nuxt composables work (setup, a plugin); what it returns may then
 * be used anywhere.
 */
export function useVectorHost(): VectorHost {
    const { schema } = useVectorSchema()
    const router = useRouter()
    const appConfig = useAppConfig()
    const language = useVectorLocale()

    return {
        schema: readonly(schema),
        context: () => {
            const route = router.currentRoute.value
            const base = appConfig.vector.basePath
            const path = base !== '' && route.path.startsWith(base) ? route.path.slice(base.length) || '/' : route.path
            const param = (name: string) => {
                const value = route.params[name]
                return typeof value === 'string' && value !== '' ? value : undefined
            }
            const resource = param('resource')
            const recordId = resource === undefined ? undefined : param('id')

            return { path, ...(resource === undefined ? {} : { resource }), ...(recordId === undefined ? {} : { recordId }) }
        },
        locale: () => language.current.value,
        markdownHtml,
        sanitizeHtml,
        vuetify: VECTOR_VUETIFY,
    }
}

/**
 * The registration points of `window.Vector` version 2, for a layer's
 * own code as much as for a runtime extension: the same registries, the
 * same rules. Every name is lowercase letters, digits and dashes;
 * registering a name again replaces what it named.
 */
export function useVectorExtend() {
    const registries = useVectorExtensions()

    return {
        // version 1
        defineCell: (name: string, component: Component) => registries.cells.set(name, markRaw(component)),
        defineInput: (name: string, component: Component) => registries.inputs.set(name, markRaw(component)),
        defineWidget: (type: string, component: Component) => registries.widgets.set(type, markRaw(component)),
        /**
         * A rich-text node or mark type (a Tiptap extension) a field's `features` may then name. The server
         * accepts it only when the application registered the same name with Document::extend().
         */
        defineRichTextExtension: (name: string, extension: AnyExtension) => registries.richText.set(name, markRaw(extension)),
        /**
         * A custom page at `/p/{name}`, listed in the menu: definePage('stock', { title: 'Stock', icon: 'mdi-warehouse', component }).
         * `title` and `group` may be functions, `() => t('stock.title')`, called each time they are shown, so they follow the language.
         */
        definePage: (name: string, page: { title: VectorPageLabel, icon?: string, group?: VectorPageLabel, component: Component }) => {
            checkName('page', name)
            registries.pages.set(name, { ...page, component: markRaw(page.component) })
        },
        // version 2
        /**
         * A component in the app bar, before the notifications inbox. It takes no props; it may teleport a
         * dialog or an end-side navigation drawer of its own.
         */
        defineShellAction: (name: string, component: Component) => {
            checkName('shell action', name)
            registries.shellActions.set(name, markRaw(component))
        },
        /**
         * A component in a list's toolbar, beside the search: props `{ resource, filters, groups, advanced, search }`,
         * emitting `update:filters`, `update:groups`, `update:advanced` and `update:search` to change the list.
         */
        defineListToolbarItem: (name: string, component: Component) => {
            checkName('list toolbar item', name)
            registries.listToolbar.set(name, markRaw(component))
        },
        /**
         * A component with every form input of the given field types: props `{ field, resource, record, modelValue,
         * disabled }`, emitting `update:modelValue` to change the field's value. `placement` is `below` (the default),
         * in a row under the input, or `inner`, inside a text or textarea input at its inline end.
         */
        defineInputAddon: (name: string, addon: { types: string[], component: Component, placement?: VectorInputAddonPlacement }) => {
            checkName('input addon', name)
            if (!Array.isArray(addon.types) || addon.types.some(t => typeof t !== 'string')) throw new Error(`Vector input addon "${name}" needs types: string[].`)
            const placement = addon.placement ?? 'below'
            if (!INPUT_ADDON_PLACEMENTS.includes(placement)) throw new Error(`Vector input addon "${name}" needs placement: ${INPUT_ADDON_PLACEMENTS.join(' | ')}.`)
            registries.inputAddons.set(name, { types: [...addon.types], component: markRaw(addon.component), placement })
        },
        /** A component in the toolbar of the rich-text and Markdown editors: props `{ editor, field }`, the live Tiptap editor. */
        defineEditorToolbarItem: (name: string, component: Component) => {
            checkName('editor toolbar item', name)
            registries.editorToolbar.set(name, markRaw(component))
        },
        /** An entry in the command palette, under "Commands": run(query) is called with what was typed. */
        definePaletteCommand: (name: string, command: VectorPaletteCommand) => {
            checkName('palette command', name)
            if (typeof command.run !== 'function') throw new Error(`Vector palette command "${name}" needs run(query).`)
            registries.paletteCommands.set(name, { ...command })
        },
        /** A component under a settings group's form, on the Settings page: props `{ group }`, the group's name. */
        defineSettingsAddon: (group: string, component: Component) => {
            checkName('settings group', group)
            registries.settingsAddons.set(group, markRaw(component))
        },
    }
}
