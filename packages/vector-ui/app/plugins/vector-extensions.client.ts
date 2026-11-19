import * as vue from 'vue'
import { Extension as TiptapExtension, Mark as TiptapMark, Node as TiptapNode, mergeAttributes } from '@tiptap/core'
import type { VectorPageLabel, VectorPaletteCommand } from '../composables/useVectorExtensions'
import type { VectorHost } from '../composables/useVectorExtend'

/**
 * Runtime UI extensions. `window.Vector` is the registration API an
 * extension module calls; the URLs come from the hosted shell
 * (`VECTOR_UI_EXTENSIONS`) or, in standalone mode, from runtime config,
 * and are imported after the app is created. Vue and a curated set of
 * Vuetify components are handed over so an extension needs no bundler and
 * no copy of either library.
 *
 * Version 2 keeps every version 1 function as it was and adds the shell's
 * own slots (defineShellAction, defineListToolbarItem, defineInputAddon,
 * defineEditorToolbarItem, definePaletteCommand, defineSettingsAddon) and
 * `host`, the admin's services. A layer reaches the same points through
 * useVectorExtend() and useVectorHost().
 */
export default defineNuxtPlugin(() => {
    const registries = useVectorExtensions()
    const extend = useVectorExtend()
    const host = useVectorHost()
    const i18n = useNuxtApp().$vectorI18n
    const api$ = useVectorApi()
    const notifications = useVectorNotifications()
    const appConfig = useAppConfig()
    const config = useRuntimeConfig()

    const api = {
        version: 2,
        vue,
        vuetify: host.vuetify,
        // what a rich-text extension is built from, so one needs no bundler of its own
        tiptap: { Node: TiptapNode, Mark: TiptapMark, Extension: TiptapExtension, mergeAttributes },
        ...extend,
        /** Adds or overrides UI messages for a locale: defineMessages('de', { common: { save: 'Speichern' } }). */
        defineMessages: (locale: string, messages: Record<string, unknown>) => i18n.global.mergeLocaleMessage(locale, messages),
        /** The admin's API client, with its credentials, for extensions calling the application's own endpoints. */
        api: api$,
        notify: {
            success: (message: string) => notifications.success(message),
            error: (message: string) => notifications.error(message),
        },
        navigate: (path: string) => navigateTo(`${appConfig.vector.basePath}${path}`),
        /** The admin's translator, for an extension's own messages (defineMessages) or the admin's. */
        t: (key: string, params: Record<string, unknown> = {}, plural?: number) => vt(key, params, plural),
        host,
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
            /** 2: version 1's functions, unchanged, plus the shell slots and `host`. */
            version: number
            vue: typeof vue
            vuetify: Record<string, vue.Component>
            tiptap: { Node: typeof TiptapNode, Mark: typeof TiptapMark, Extension: typeof TiptapExtension, mergeAttributes: typeof mergeAttributes }
            defineCell: (name: string, component: vue.Component) => void
            defineInput: (name: string, component: vue.Component) => void
            defineWidget: (type: string, component: vue.Component) => void
            defineRichTextExtension: (name: string, extension: import('@tiptap/core').AnyExtension) => void
            defineMessages: (locale: string, messages: Record<string, unknown>) => void
            definePage: (name: string, page: { title: VectorPageLabel, icon?: string, group?: VectorPageLabel, component: vue.Component }) => void
            defineShellAction: (name: string, component: vue.Component) => void
            defineListToolbarItem: (name: string, component: vue.Component) => void
            defineInputAddon: (name: string, addon: { types: string[], component: vue.Component }) => void
            defineEditorToolbarItem: (name: string, component: vue.Component) => void
            definePaletteCommand: (name: string, command: VectorPaletteCommand) => void
            defineSettingsAddon: (group: string, component: vue.Component) => void
            host: VectorHost
            api: import('nitropack').$Fetch
            notify: { success: (message: string) => void, error: (message: string) => void }
            navigate: (path: string) => unknown
            t: (key: string, params?: Record<string, unknown>, plural?: number) => string
        }
    }
}
