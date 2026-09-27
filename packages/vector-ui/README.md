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
components — `VAlert`, `VAutocomplete`, `VBtn`, `VCard`, `VCheckbox`,
`VChip`, `VIcon`, `VProgressLinear`, `VRating`, `VSelect`, `VSwitch`,
`VTable` (a custom page's tables: `thead` and `tbody` as its content),
`VTextField`, `VTextarea`:

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

## Forms

`VxResourceForm` tracks unsaved changes (`dirty`, exposed with
`reset()`), and with `variants` — as the create and edit pages use it —
offers the save variants (back to the record, continue editing, add
another), remembers the last one as the user preference
`form.saveVariant`, and saves with Ctrl/Cmd+S. The pages guard leaving a
changed form through `useLeaveGuard()`, the same confirm dialog the
list's unsaved inline edits use. `saved` emits the record and the
variant.

## Rich text and Markdown

`RichText` fields edit in a Tiptap editor (`@tiptap/*`, MIT) over exactly
the field's `features`, with a toolbar built from them; the value is the
document as JSON text. Images upload through the field's image field;
`@` mentions search its `mentions` relation. `Markdown` fields edit the
same way through `@tiptap/markdown`, with a source view.

On the record page a value is shown as HTML — the layer's only `v-html`,
in `Vx/Cell/RichText.vue` and `Vx/Cell/Markdown.vue` — and only after it
was generated from the document through the schema (or rendered by
markdown-it with HTML off) and passed through **DOMPurify** with an
allow-list matching the schema. DOMPurify is dual-licensed MPL-2.0 OR
Apache-2.0; the layer uses it **under Apache-2.0**. If the sanitizer
fails its canary check in some environment, values show as text instead.

A runtime extension adds a node or mark type with
`window.Vector.defineRichTextExtension(name, extension)`, built from
`window.Vector.tiptap` (`Node`, `Mark`, `Extension`, `mergeAttributes`);
the server must register the same name (`Document::extend()`).

## Theming

Vuetify `defaults` and the two themes are set in the layer's
`nuxt.config.ts` and overridable in the consuming app. At runtime the
`Branding` settings group (title, logo, primary colours, default theme,
footer) applies over them, rendered escaped.

## Languages

Each user sees the admin in their own language. The layer ships English
(the base and the fallback, key by key) and German, French, Dutch,
Italian, Spanish, Portuguese (`pt`, European, and `pt-BR`), Polish,
Turkish, Russian, Ukrainian, Hebrew, Arabic, Korean, Japanese and
Simplified Chinese (`zh-Hans`) — **machine translations**, which an
application can override. Labels the descriptor carries (resources,
fields, actions, enum options, widgets, settings) arrive translated by
the server for the same language.

The descriptor's `i18n` names the user's language and the languages on
offer (the ones the administrator enabled in the Languages settings).
The language menu in the app bar lists them in the server's order — the
languages' own names by Unicode root collation, the same in every
language — each named in itself with its name in the current language
beneath (`languageName()`, from `Intl.DisplayNames`), and switches it: `useVectorLocale().choose(code)` stores the user's `locale`
preference and reloads the descriptor. Before the descriptor loads, the
UI starts in the hosted shell's language, else the one this browser last
used, else `app.config` `vector.locale`. Applying a language switches the
catalog, Vuetify's component strings, the `Intl` formatting of dates,
numbers and money, and `<html lang dir>`: Hebrew and Arabic render right to left,
and the layer's own components use logical spacing (`ms-`/`me-`) so they
mirror.

Add or override messages per locale and area in `app.config`:

```ts
export default defineAppConfig({
    vector: {
        messages: {
            de: { common: { save: 'Sichern' }, list: { filter: { add: 'Filter hinzufügen' } } },
        },
    },
})
```

A runtime extension adds them without a build:
`window.Vector.defineMessages('de', { common: { save: 'Sichern' } })`.
The English catalog in `app/i18n/en/` names every key, and a test holds
every shipped locale to the same keys and placeholders. Messages use
`{name}` placeholders and are never rendered as HTML. A plural message
lists its language's own forms, `a | b | …`, in CLDR order — zero, one,
two, few, many, other — keeping the ones whole counts reach there
(`pluralCategories()`): one form in Japanese, two in English (one,
other), three in Hebrew (one, two, other) and Russian (one, few, many),
six in Arabic. `pluralRule()` picks the form by the browser's
`Intl.PluralRules`; a two-form message an application adds is read the
English way in any language.

## Authentication modes

`useVectorRuntime()` reads `window.__VECTOR__`, which the hosted shell
injects. Present, the layer is in **cookie mode**: same-origin API,
`credentials: same-origin`, `X-CSRF-Token` on every unsafe method, a
401 sends the browser to the application's login URL. Absent, the layer
is in **bearer mode**: `POST /auth/login` on `apiBase`, the token in
memory (mirrored to `sessionStorage` for reloads). When the login answers
a `refreshToken`, the store renews both tokens through
`POST /auth/refresh` — once at a time, when the access token has under
two minutes left and once on a 401, after which the request is repeated —
and signs out when the renewal is refused; logout revokes it with
`POST /auth/logout`. Without one, the session ends with its token and a
401 goes to `/login`. Impersonation swaps the token in bearer mode (and
is never renewed) and reloads in cookie mode.

## Testing

`pnpm typecheck` runs `vue-tsc --strict` over the layer, its tests
included.

`pnpm test` runs the layer's Vitest suite in `test/nuxt/`: each file
runs inside a Nuxt app built from this layer (`@nuxt/test-utils`,
happy-dom), so auto-imports, app config, the i18n plugin, Vuetify and
Pinia behave as they do in the admin. It covers the formatters and
client rules, the API client in both authentication modes, the auth
store, the schema and resource clients, and the generated form
(`VxResourceForm`) with its 422 mapping. API calls go to endpoints
the test registers (`test/nuxt/endpoints.ts`); nothing needs a server.

Browser flows are the demo's end-to-end suite, `demo/e2e`: Playwright
against the running stack, in both deployment modes. See its README.
