import * as vue from 'vue'
import { Extension as TiptapExtension, Mark as TiptapMark, Node as TiptapNode, mergeAttributes } from '@tiptap/core'
import {
    VAlert, VAutocomplete, VBtn, VCard, VCheckbox, VChip, VDialog, VDivider, VExpansionPanel, VExpansionPanels, VExpansionPanelText,
    VExpansionPanelTitle, VIcon, VList, VListItem, VMenu, VPagination, VProgressLinear, VRating, VSelect, VSkeletonLoader, VSwitch,
    VTab, VTable, VTabs, VTabsWindow, VTabsWindowItem, VTextField, VTextarea, VTooltip,
} from 'vuetify/components'
import type { VectorPageLabel } from '../composables/useVectorExtensions'

/**
 * Runtime UI extensions (§4.15 tier 2). `window.Vector` is the
 * registration API an extension module calls; the URLs come from the
 * hosted shell (`VECTOR_UI_EXTENSIONS`) or, in standalone mode, from
 * runtime config, and are imported after the app is created. Vue and a
 * curated set of Vuetify components are handed over so an extension
 * needs no bundler and no copy of either library.
 */
export default defineNuxtPlugin(() => {
    const registries = useVectorExtensions()
    const i18n = useNuxtApp().$vectorI18n
    const api$ = useVectorApi()
    const notifications = useVectorNotifications()
    const appConfig = useAppConfig()
    const config = useRuntimeConfig()
    // Tree-shaking keeps only what is imported here, so this is every Vuetify component an extension can reach.
    // VTable for a custom page's tables: its wrapper, not a bare <table>, carries Vuetify's table styles.
    const vuetify = {
        VAlert, VAutocomplete, VBtn, VCard, VCheckbox, VChip, VDialog, VDivider, VExpansionPanel, VExpansionPanels, VExpansionPanelText,
        VExpansionPanelTitle, VIcon, VList, VListItem, VMenu, VPagination, VProgressLinear, VRating, VSelect, VSkeletonLoader, VSwitch,
        VTab, VTable, VTabs, VTabsWindow, VTabsWindowItem, VTextField, VTextarea, VTooltip,
    }

    const api = {
        version: 1,
        vue,
        vuetify,
        // what a rich-text extension is built from, so one needs no bundler of its own
        tiptap: { Node: TiptapNode, Mark: TiptapMark, Extension: TiptapExtension, mergeAttributes },
        defineCell: (name: string, component: vue.Component) => registries.cells.set(name, vue.markRaw(component)),
        defineInput: (name: string, component: vue.Component) => registries.inputs.set(name, vue.markRaw(component)),
        defineWidget: (type: string, component: vue.Component) => registries.widgets.set(type, vue.markRaw(component)),
        /**
         * A rich-text node or mark type (a Tiptap extension) a field's `features` may then name. The server
         * accepts it only when the application registered the same name with Document::extend().
         */
        defineRichTextExtension: (name: string, extension: import('@tiptap/core').AnyExtension) => registries.richText.set(name, vue.markRaw(extension)),
        /** Adds or overrides UI messages for a locale: defineMessages('de', { common: { save: 'Speichern' } }). */
        defineMessages: (locale: string, messages: Record<string, unknown>) => i18n.global.mergeLocaleMessage(locale, messages),
        /**
         * A custom page at `/p/{name}`, listed in the menu: definePage('stock', { title: 'Stock', icon: 'mdi-warehouse', component }).
         * `title` and `group` may be functions, `() => t('stock.title')`, called each time they are shown, so they follow the language.
         */
        definePage: (name: string, page: { title: VectorPageLabel, icon?: string, group?: VectorPageLabel, component: vue.Component }) => {
            if (!/^[a-z0-9][a-z0-9-]*$/.test(name)) throw new Error(`Vector page name "${name}" must be lowercase letters, digits and dashes.`)
            registries.pages.set(name, { ...page, component: vue.markRaw(page.component) })
        },
        /** The admin's API client, with its credentials, for extensions calling the application's own endpoints. */
        api: api$,
        notify: {
            success: (message: string) => notifications.success(message),
            error: (message: string) => notifications.error(message),
        },
        navigate: (path: string) => navigateTo(`${appConfig.vector.basePath}${path}`),
        /** The admin's translator, for an extension's own messages (defineMessages) or the admin's. */
        t: (key: string, params: Record<string, unknown> = {}, plural?: number) => vt(key, params, plural),
    }

    window.Vector = api

    const vectorConfig = config.public.vector as { extensions?: string[] }

    // see loadVectorExtensions: the hosted shell's list, or runtime config's, at once
    void loadVectorExtensions({
        initial: [...(window.__VECTOR__?.extensions ?? []), ...(vectorConfig.extensions ?? [])],
        ready: registries.ready,
        importModule: url => import(/* @vite-ignore */ url),
        run: module => (typeof (module as { default?: unknown }).default === 'function' ? (module as { default: (vector: typeof api) => unknown }).default(api) : undefined),
    })
})

declare global {
    interface Window {
        Vector?: {
            version: number
            vue: typeof vue
            vuetify: Record<string, vue.Component>
            tiptap: { Node: typeof TiptapNode, Mark: typeof TiptapMark, Extension: typeof TiptapExtension, mergeAttributes: typeof mergeAttributes }
            defineCell: (name: string, component: vue.Component) => void
            defineInput: (name: string, component: vue.Component) => void
            defineWidget: (type: string, component: vue.Component) => void
            defineRichTextExtension: (name: string, extension: import('@tiptap/core').AnyExtension) => void
            defineMessages: (locale: string, messages: Record<string, unknown>) => void
            definePage: (name: string, page: { title: string | (() => string), icon?: string, group?: string | (() => string), component: vue.Component }) => void
            api: import('nitropack').$Fetch
            notify: { success: (message: string) => void, error: (message: string) => void }
            navigate: (path: string) => unknown
            t: (key: string, params?: Record<string, unknown>, plural?: number) => string
        }
    }
}
