# @kinetis/vector-ui

The admin UI for [`kinetis/vector`](../vector/README.md): a Nuxt 4
layer on Vuetify 4 that renders the descriptor the API publishes —
navigation, lists, forms, relation panels, actions, dashboards, jobs,
settings — and contains no application knowledge. A consuming
application is a Nuxt app that extends the layer.

## Installation

```sh
pnpm add @kinetis/vector-ui vuetify vuetify-nuxt-module nuxt
```

```ts
// nuxt.config.ts
export default defineNuxtConfig({
    extends: ['@kinetis/vector-ui'],
    runtimeConfig: { public: { vector: { apiBase: 'http://localhost:8080', extensions: [] } } },
})
```

```ts
// app/app.config.ts
export default defineAppConfig({
    vector: {
        brand: { title: 'My Admin' },  // the fallback before Branding settings load
        basePath: '',                  // a router prefix inside the app, if any
        fields: {}, inputs: {}, widgets: {}, // component overrides by field type, component name or widget type
        commandPalette: true,
        usersResource: 'users',        // the resource whose records are users, for Impersonate
    },
})
```

`pnpm nuxt dev` for development, `pnpm nuxt generate` for a static
site any web server hosts (standalone mode), and
`NUXT_APP_BASE_URL=/vector/ui/ nuxt generate` for the hosted bundle the
PHP application serves (see [deployment modes](../vector/docs/deployment-modes.md)).

## Keys

| Where | Key | Meaning |
|---|---|---|
| `runtimeConfig.public.vector` | `apiBase` | The API origin in standalone mode (`NUXT_PUBLIC_VECTOR_API_BASE`). |
| | `extensions` | ES-module URLs to import after boot (standalone mode). |
| `app.config.vector` | `brand.title`, `basePath`, `commandPalette`, `usersResource` | As above. |
| | `fields`, `inputs`, `widgets` | Globally registered component names by field type, field `component` name or widget type. |

## Registries and overrides

Three registries resolve what renders a field: cells (`VxCell/*`),
inputs (`VxInput/*`) and widget renderers (`VxWidget/*`). Lookup order
is an `app.config` override by the field's `component` name, a runtime
extension registered under that name, an `app.config` override by type,
then the built-in for the type. Layer files can also be overridden by
placing the same path in the consuming app (Nuxt layer semantics).

Runtime extensions are plain ES modules that need no build of this
layer. `window.Vector` hands them Vue and a curated set of Vuetify
components:

```js
export default function ({ vue, vuetify, defineCell, defineInput, defineWidget }) {
    defineCell('stock-level', {
        props: { value: {}, field: {}, record: {}, resource: {}, context: {} },
        setup: props => () => vue.h(vuetify.VChip, { size: 'small' }, () => String(props.value)),
    })
}
```

A cell receives `value`, `field`, `record`, `resource` and `context`
(`index` or `show`); an input receives `modelValue`, `field`, `rules`,
`errorMessages`, `record` and `resource` and emits `update:modelValue`;
a widget renderer receives `data` and `widget`.

### Custom pages

A custom page is a menu entry and a route, `/p/{name}`, rendering a
component. A runtime extension defines one with
`definePage(name, { title, icon, group, component })`; an application
extending the layer lists it in `app.config` `vector.pages`, naming a
component it registered globally. `group` places it under a menu section
(a resource group's name, or its own); without one it goes under
"Pages". The page component receives one prop, `vector`, with `api` (the
admin's API client, carrying the bearer token or the session cookie and
CSRF token), `notify.success/error` and `navigate(path)`:

```js
export default function ({ vue, definePage }) {
    definePage('stock-overview', {
        title: 'Stock overview', icon: 'mdi-warehouse', group: 'Catalog',
        component: {
            props: { vector: Object },
            setup(props) {
                const data = vue.ref(null)
                vue.onMounted(async () => { data.value = await props.vector.api('/vector-demo/stock-overview') })
                return () => vue.h('pre', JSON.stringify(data.value, null, 2))
            },
        },
    })
}
```

An endpoint a custom page calls is ordinary application code. Put it in
Vector's `vector` middleware group (`#[Middleware('@vector')]`) and the
admin's authentication and Vector's access policy apply to it as to
Vector's own routes. `window.Vector` also hands extensions `api`,
`notify`, `navigate`, `t` (the admin's translator) and
`defineMessages()`.

## Theming

Vuetify `defaults` and the two themes are set in the layer's
`nuxt.config.ts` and overridable in the consuming app. At runtime the
`Branding` settings group (title, logo, primary colours, default theme,
footer) applies over them, rendered escaped.

## Languages

The admin's own strings — buttons, menus, dialogs, validation and
notification texts — come from a message catalog through `vue-i18n`;
labels the descriptor carries (resources, fields, actions, enum options)
are the application's and arrive in its own language. English ships
with the layer and is the fallback, string by string, for every other
locale. Vuetify's component strings (pagination, empty tables) follow the
same locale from Vuetify's own translations.

The locale is the hosted shell's `VECTOR_UI_LOCALE` when set, otherwise
`app.config` `vector.locale`. Add or override messages per locale and
area in `app.config`:

```ts
export default defineAppConfig({
    vector: {
        locale: 'de',
        messages: {
            de: { common: { save: 'Speichern', cancel: 'Abbrechen' }, list: { exportCsv: 'CSV exportieren', filter: { add: 'Filter hinzufügen' } } },
        },
    },
})
```

A runtime extension adds them without a build:
`window.Vector.defineMessages('de', { common: { save: 'Speichern' } })`.
The English catalog in `app/i18n/en/` names every key. Messages use
`{name}` placeholders and `a | b | c` plural forms, and are never
rendered as HTML.

## Authentication modes

`useVectorRuntime()` reads `window.__VECTOR__`, which the hosted shell
injects. Present, the layer is in **cookie mode**: same-origin API,
`credentials: same-origin`, `X-CSRF-Token` on every unsafe method, a
401 sends the browser to the application's login URL. Absent, the layer
is in **bearer mode**: `POST /auth/login` on `apiBase`, the token in
memory (mirrored to `sessionStorage` for reloads), a 401 goes to
`/login`. Impersonation swaps the token in bearer mode and reloads in
cookie mode.

## Testing

`pnpm typecheck` runs `vue-tsc --strict` over the layer. Browser flows
run against the reference application with Playwright; the reference
repository's scripts cover login, lists with filters, views, columns,
bulk actions, forms with 422 mapping, hosted mode under a strict CSP,
dashboards, jobs, import, the inbox, audit and impersonation.
