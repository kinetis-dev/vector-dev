# Customizing the UI

Three tiers, from no code to your own build. Use the lowest one that
does the job.

1. **Descriptor and settings.** Fields, filters, actions, widgets,
   navigation and the **Branding** settings. No JavaScript.
2. **Runtime extensions.** ES modules the admin loads at startup, adding
   cells, inputs, widget renderers and pages. JavaScript, no rebuild.
3. **Your own build.** A Nuxt application extending the layer,
   overriding any of its files (Nuxt layer semantics: the same path in
   your application wins), registering global components named in
   `app.config`, and generating its own bundle; in hosted mode, point
   `VECTOR_UI_DIR` at it ({doc}`deployment`).

## Keeping the layer at the pinned release

Each `kinetis/vector` release pins the `@kinetis/vector-ui` release it
expects, and publishes it in the descriptor as `ui.expected`. The layer
bakes its own `package.json` version into the build
(`runtimeConfig.public.vector.uiVersion`). When the two differ, the
admin shows a banner naming both and the remedy: upgrade
`@kinetis/vector-ui` to the pinned release and rebuild. It does not
block the admin, and once dismissed it stays away for the browser
session. A descriptor of another contract version is a hard stop, with
the same remedy.

## Application config

A Nuxt application extending `@kinetis/vector-ui` sets:

```ts
// app/app.config.ts
export default defineAppConfig({
    vector: {
        brand: { title: 'My Admin' },  // before the Branding settings load
        basePath: '',                  // a router prefix inside the app
        commandPalette: true,          // Ctrl/⌘+K search
        usersResource: 'users',        // enables Impersonate on those records
        idleMinutes: 30,               // sign out after this long unused; 0 never
        fields: {}, inputs: {}, widgets: {}, // component overrides
        locale: 'en', messages: {},    // see Languages
        pages: {},                     // custom pages, by name
    },
})
```

## Cells, inputs and widget renderers

Three registries decide what renders a value: **cells** (lists and
record pages, the layer's `VxCell/*`), **inputs** (forms, `VxInput/*`)
and **widget renderers** (`VxWidget/*`). The `app.config` overrides are
globally registered component names, keyed by field type, by a field's
`component` name or by widget type. For a field, the lookup order is:

1. an `app.config` override by the field's `component('name')`;
2. a runtime extension registered under that name;
3. an `app.config` override by field type;
4. the built-in for the type.

A cell receives `value`, `field`, `record`, `resource` and `context`
(`index` or `show`). An input receives `modelValue`, `field`, `rules`,
`errorMessages`, `record` and `resource` and emits `update:modelValue`.
A widget renderer receives `data` and `widget`. For a widget type, the
lookup order is the `app.config` override, then a runtime extension's
`defineWidget`, then the built-in card. A widget can also declare an
extension type, a kebab-case name Vector has no card for, such as
`kinetis/vector-queues`' `vector-queues` ("Extension widget types" in
{doc}`dashboards`). Its renderer must be registered one of those two
ways. Without a renderer the card says that none is registered for the
type, rather than drawing it as a `stat`.

## Runtime extensions

An extension is a plain ES module. List it in `VECTOR_UI_EXTENSIONS`
(hosted) or `runtimeConfig.public.vector.extensions` (standalone), or
ship it in a Composer package (see "Extensions in a Composer package"
below).
Its default export receives `window.Vector`, which hands it Vue, a
curated set of Vuetify components and the registration functions. The
Vuetify components are the only ones an extension can reach, since the
bundle keeps no other:

- `VAlert`, `VAutocomplete`, `VBtn`, `VCard`, `VCheckbox`, `VChip`,
  `VDivider`, `VIcon`, `VProgressLinear`, `VRating`, `VSelect`,
  `VSkeletonLoader`, `VSwitch`, `VTable`, `VTextField`, `VTextarea`;
- `VDialog`, `VMenu`, `VTooltip`;
- `VList`, `VListItem`, `VPagination`;
- `VTabs`, `VTab`, `VTabsWindow`, `VTabsWindowItem`;
- `VExpansionPanels`, `VExpansionPanel`, `VExpansionPanelTitle`,
  `VExpansionPanelText`.

For a table, render `VTable` with your `thead` and `tbody` as
its content: Vuetify's table styles belong to its wrapper, so the class
on a bare `<table>` leaves the columns unaligned.

```js
export default function ({ vue, vuetify, defineCell }) {
    defineCell('stock-level', {
        props: { value: {}, field: {}, record: {}, resource: {}, context: {} },
        setup: props => () => vue.h(vuetify.VChip, { size: 'small' }, () => String(props.value)),
    })
}
```

```php
Number::make('stock')->component('stock-level'),
```

An extension is served under `public/`, same-origin or CORS-enabled. The
demo's `public/vector-extensions/stock-level.js` is this one.

### Extensions in a Composer package

A Composer package can ship its own extensions, so installing it is the
whole step: list the modules in its `composer.json`, relative to the
package's root.

```json
{
    "extra": {
        "kinetis": {
            "vector": { "extensions": ["resources/ui/queues.js"] }
        }
    }
}
```

Vector reads these declarations from Composer's `installed.json` when
it compiles its registry (`kinetis build`, or every boot in
development), never from a bootstrap, so it does not matter whether the
package boots before or after Vector. Each worker reads the modules
into memory once, at boot, and serves them itself at
`/vector/extensions/{vendor}/{package}/{hash}/{file}`, where `hash` is a
digest of the file's bytes. The hosted shell lists them first, in
package-name order, then the `VECTOR_UI_EXTENSIONS` entries.
`kinetis/vector-queues` ships its admin page this way ({doc}`queues`).

- **What is served:** an entry must be a `.js` or `.mjs` file inside the
  package: a relative path with no `.`, `..` or hidden segment, no
  symlink out of the package, and a file name unique within it. Vector
  skips any other entry and writes the reason to the PHP error log.
  Only declared files are served; every other path under
  `/vector/extensions` answers `404`.
- **Caching:** a module answers as `text/javascript; charset=utf-8`
  with `Cache-Control: public, max-age=31536000, immutable`. When a
  package upgrade changes a module, its hash and its URL change with
  it, so a browser never runs a stale copy. A request naming an old hash
  gets a `404`, not the new bytes. Restart workers after an upgrade, as
  after any deploy; in production, rebuild with `kinetis build` too.
- **Access:** the modules are public, like the hosted bundle's own
  scripts, which the web server serves from `public/vector/ui` to anyone.
  A module is code from a package, not data. Keep secrets out of it, and
  fetch data through `api`, which is behind the `vector` group.
- **CSP:** the modules are same-origin scripts, and the shell imports
  them with `import()`. The `SECURITY_CSP` in {doc}`deployment` has
  `script-src 'self' 'nonce-{nonce}'`, and its `'self'` admits them, as
  it admits the bundle's own scripts. A policy whose `script-src` holds
  only a nonce blocks them; a `VECTOR_SHELL_CSP` like that must add
  `'self'`. No documented policy uses `'strict-dynamic'`: the shell
  nonces its inline scripts only, not the bundle's own `src` scripts.
- **Standalone mode:** the descriptor (`GET /vector/schema`) lists the
  same modules under `ui.extensions`, as same-origin paths of the API.
  The standalone UI imports them once signed in, as soon as the
  descriptor arrives, each resolved against its `apiBase`. An entry that
  resolves anywhere but the API's own origin under `/vector/extensions/`
  is logged and skipped. A module that fails to load, or a descriptor
  that fails, is logged and never blocks the admin. Each module loads
  from the API's origin as a cross-origin module script, so the API's CORS
  must also cover `/vector/extensions/*` for the UI's origin
  ({doc}`deployment`), and a `script-src` on the UI's own pages must
  admit the API's origin. The hosted admin ignores the descriptor's list,
  because its shell already lists the same modules. Either way each
  module is imported once per page load: signing out and in again loads
  nothing more. A custom page a package declares, opened straight from
  a link, shows a loading bar until the modules have settled, and only
  then reads "unknown page". The deployment's `VECTOR_UI_EXTENSIONS`
  stay the hosted shell's alone.

`window.Vector` also offers `defineInput`, `defineWidget`, `definePage`,
`defineMessages`, `defineRichTextExtension`, `api` (the admin's API
client, with its credentials), `notify`, `navigate`, `t` (the admin's
translator) and `tiptap` (`Node`, `Mark`, `Extension`, `mergeAttributes`,
to build a rich-text node without a bundler).

## Rich-text extensions

A node or mark type — a callout, an embed — joins the rich-text schema
on both sides:

```js
export default function ({ tiptap, defineRichTextExtension }) {
    defineRichTextExtension('callout', tiptap.Node.create({
        name: 'callout', group: 'block', content: 'paragraph+',
        addAttributes: () => ({ tone: { default: 'info' } }),
        parseHTML: () => [{ tag: 'aside[data-callout]' }],
        renderHTML: ({ HTMLAttributes }) => ['aside', tiptap.mergeAttributes(HTMLAttributes, { 'data-callout': '' }), 0],
    }))
}
```

```php
$app->instance(Document::class, (new Document())->extend(new NodeSpec('callout', ['tone'])));
```

Bind the extended `Document` in `bootstrap.php`. A `NodeSpec` may name
its own HTML renderer for `Document::toHtml()`
(`new NodeSpec('callout', ['tone'], renderer: CalloutNode::class)`). A
field then lists `callout` in its `features()`. The server accepts the
node only once registered, so both sides agree.

## Custom pages

A custom page is a menu entry and a route, `/p/{name}`:

```js
export default function ({ vue, definePage }) {
    definePage('stock-overview', {
        title: 'Stock overview', icon: 'mdi-warehouse', group: 'Catalog',
        component: {
            props: { vector: Object },
            setup(props) {
                const data = vue.ref(null)
                vue.onMounted(async () => { data.value = await props.vector.api('/stock-overview') })
                return () => vue.h('pre', JSON.stringify(data.value, null, 2))
            },
        },
    })
}
```

`group` places the page under a menu section (a resource group's name,
or its own); without one it goes under *Pages*. `title` and `group` may
also be functions returning the text, which the admin calls each time it
shows them — so a translated label follows a change of language without
a reload:

```js
export default function ({ definePage, defineMessages, t }) {
    defineMessages('en', { stock: { title: 'Stock overview', group: 'Catalog' } })
    defineMessages('de', { stock: { title: 'Lagerübersicht', group: 'Katalog' } })
    definePage('stock-overview', {
        title: () => t('stock.title'), group: () => t('stock.group'), icon: 'mdi-warehouse',
        component: { /* … */ },
    })
}
```

A string computed once with `t()` keeps the language of the moment the
module loaded. The component receives
one prop, `vector`, with `api` (the admin's API client, carrying the
bearer token or the session cookie and CSRF token),
`notify.success/error` and `navigate(path)`. An application extending
the layer can instead list the page in `app.config` `vector.pages`,
naming a component it registered globally:
`{ stock: { title: 'Stock', icon: 'mdi-warehouse', component: 'StockPage' } }`.

An endpoint the page calls is your own code: put it in the `vector`
middleware group (`#[Middleware('@vector')]`) and the admin's
authentication and access policy apply to it as to Vector's own
routes.

## Forms in your own pages

`VxResourceForm` is the generated form. It tracks unsaved changes
(`dirty`, exposed with `reset()`), and with `variants` — as the create
and edit pages use it — offers the save variants (back to the record,
continue editing, add another), remembers the last one as the user
preference `form.saveVariant`, and saves with <kbd>Ctrl</kbd>/<kbd>⌘</kbd>+<kbd>S</kbd>.
`saved` emits the record and the variant. The pages guard leaving a
changed form through `useLeaveGuard()`, the same confirm dialog the
list's unsaved inline edits use.

## Theming

The layer sets Vuetify's defaults and both themes in its own
`nuxt.config.ts`; your application overrides any of them. At runtime,
the **Branding** settings apply over them.

## Safety

Script authority is the deployer's: `VECTOR_SHELL_HEAD` and the
extension list are configuration. No setting, field or descriptor value
is ever rendered as markup or executed.

## See also

- {doc}`languages` — translating the admin.
- {doc}`deployment` — where extensions are served from.
