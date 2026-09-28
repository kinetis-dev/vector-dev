export default defineAppConfig({
    vector: {
        brand: {
            title: 'Vector',
        },
        // A path prefix inside the router; the deployment's URL prefix is
        // Nuxt's app.baseURL ('/' standalone, '/vector/ui/' hosted).
        basePath: '',
        // Field components a consuming app adds or replaces, keyed by the
        // descriptor's field type or explicit component name (Phase 2).
        fields: {} as Record<string, string>,
        // Input components, the same way (Phase 3).
        inputs: {} as Record<string, string>,
        // The Ctrl/Cmd+K global search palette (Phase 4).
        commandPalette: true,
        // Widget renderers by type, the same way (Phase 5).
        widgets: {} as Record<string, string>,
        // The resource whose records are users, for the Impersonate button (Phase 5).
        usersResource: null as string | null,
        // The UI's language before the descriptor names the user's, when neither the hosted shell nor this browser does.
        locale: 'en',
        // Minutes without a pointer, key, touch or scroll after which the admin signs the user out; 0 never does.
        idleMinutes: 30,
        // Messages an application adds or overrides, by locale and area: { de: { common: { save: 'Speichern' } } }.
        messages: {} as Record<string, Record<string, unknown>>,
        // Custom pages at /p/{name}: { stock: { title: 'Stock', icon: 'mdi-warehouse', component: 'StockPage' } },
        // the component registered globally by the application.
        pages: {} as Record<string, { title: string, icon?: string, group?: string, component: string }>,
    },
})

declare module 'nuxt/schema' {
    interface AppConfigInput {
        vector?: {
            brand?: { title?: string }
            basePath?: string
            fields?: Record<string, string>
            inputs?: Record<string, string>
            commandPalette?: boolean
            widgets?: Record<string, string>
            usersResource?: string | null
            locale?: string
            idleMinutes?: number
            messages?: Record<string, Record<string, unknown>>
            pages?: Record<string, { title: string, icon?: string, group?: string, component: string }>
        }
    }
    interface AppConfig {
        vector: {
            brand: { title: string }
            basePath: string
            fields: Record<string, string>
            inputs: Record<string, string>
            commandPalette: boolean
            widgets: Record<string, string>
            usersResource: string | null
            locale: string
            idleMinutes: number
            messages: Record<string, Record<string, unknown>>
            pages: Record<string, { title: string, icon?: string, group?: string, component: string }>
        }
    }
}
