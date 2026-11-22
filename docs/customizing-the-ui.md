# Customizing the UI

Three tiers, from no code to your own build. Use the lowest one that
does the job.

1. **Descriptor and settings.** Fields, filters, actions, widgets,
   navigation and the **Branding** settings. No JavaScript.
2. **Runtime extensions.** ES modules the admin loads at startup, adding
   cells, inputs, widget renderers, pages and the shell's own slots
   (app-bar actions, list-toolbar items, input and editor addons,
   palette commands, settings addons). JavaScript, no rebuild.
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
`defineWidget`, then the built-in card. A renderer registered for a
built-in type (`stat`, `trend`, `partition`, `progress`, `table` or
`queues`) replaces that card. A widget can also declare an extension
type, a kebab-case name Vector has no card for, such as `sparkline`
("Extension widget types" in {doc}`dashboards`). The application must
register its renderer one of those two ways. Without a renderer the
card says that none is registered for the type, rather than drawing it
as a `stat`.

## Runtime extensions

An extension is a plain ES module. List it in `VECTOR_UI_EXTENSIONS`
(hosted) or `runtimeConfig.public.vector.extensions` (standalone). The
admin imports the modules after boot, fetched at once but run in the
order listed, so the custom pages they define keep one menu order; a
module that fails to load is logged and never blocks the admin.
Its default export receives `window.Vector`, which hands it Vue, a
curated set of Vuetify components and the registration functions. The
Vuetify components are the only ones an extension can reach, since the
bundle keeps no other:

- `VAlert`, `VAutocomplete`, `VBadge`, `VBtn`, `VCard`, `VCheckbox`,
  `VChip`, `VDivider`, `VIcon`, `VProgressCircular`, `VProgressLinear`,
  `VRating`, `VSelect`, `VSkeletonLoader`, `VSwitch`, `VTable`,
  `VTextField`, `VTextarea`, `VToolbar`;
- `VDialog`, `VMenu`, `VNavigationDrawer`, `VTooltip`;
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

`window.Vector` also offers `defineInput`, `defineWidget`, `definePage`,
`defineMessages`, `defineRichTextExtension`, `api` (the admin's API
client, with its credentials), `notify`, `navigate`, `t` (the admin's
translator) and `tiptap` (`Node`, `Mark`, `Extension`, `mergeAttributes`,
to build a rich-text node without a bundler), and, from version 2, the
shell slots and `host` below.

## Shell slots (`window.Vector` version 2)

`window.Vector.version` is `2`. Every version 1 function keeps its
signature and behaviour; version 2 adds slots in the admin's own chrome.
Each takes a name (lowercase letters, digits and dashes; registering a
name again replaces what it named), renders in registration order, and
renders nothing while nothing is registered.

| Function | Where it renders | What the component gets |
|---|---|---|
| `defineShellAction(name, component)` | the app bar, before the notifications inbox | no props; it may teleport its own dialog or end-side `VNavigationDrawer` |
| `defineListToolbarItem(name, component)` | a list's toolbar row, beside the search, above the filter bar | `resource`, `filters`, `groups`, `advanced`, `search`; emits `update:filters`, `update:groups`, `update:advanced`, `update:search` (the filter bar's own models) to change the list |
| `defineInputAddon(name, { types, component, placement? })` | under each form input whose field type is in `types`, in record forms, action dialogs and settings groups; with `placement: 'inner'` (the default is `'below'`), inside the input itself at its inline end: right in a left-to-right language, left in a right-to-left one, which only the text and textarea inputs (`string`, `email`, `url`, `slug`, `password`, `color`, `text`, `json`) render, wherever they are used, in a list's inline edit and a repeater's rows too | `field`, `resource` (the slug; empty in a settings group), `record`, `modelValue`, `disabled`; emits `update:modelValue` to change the field |
| `defineEditorToolbarItem(name, component)` | the rich-text and Markdown editors' toolbars, before the undo/redo end | `editor` (the live Tiptap `Editor`), `field` |
| `definePaletteCommand(name, { title, icon, run })` | the command palette (<kbd>Ctrl</kbd>/<kbd>⌘</kbd>+<kbd>K</kbd>), under *Commands* | `run(query)` is called with what was typed, and the palette closes; `title` may be a function, like a page's |
| `defineSettingsAddon(group, component)` | the Settings page, under that group's form, outside it | `group`, the group's name |

```js
export default function ({ vue, vuetify, defineListToolbarItem }) {
    defineListToolbarItem('only-active', {
        props: { resource: Object, filters: Object, groups: Array, advanced: Array, search: String },
        emits: ['update:filters'],
        setup: (props, { emit }) => () => props.resource.slug !== 'products' ? null : vue.h(vuetify.VBtn, {
            size: 'small', variant: 'tonal',
            onClick: () => emit('update:filters', { ...props.filters, status: 'active' }),
        }, () => 'Active only'),
    })
}
```

### Host services

`window.Vector.host` lends what the admin knows, for an extension that
needs more than its props:

- `schema`: the loaded descriptor, a read-only ref (`null` before the
  first load);
- `context()`: where the admin is, `{ path, resource?, recordId? }`, the
  path under the admin's base path;
- `locale()`: the language the admin is shown in;
- `markdownHtml(text)` and `sanitizeHtml(html)`: the admin's own
  Markdown renderer and HTML sanitizer, so an extension's markup is held
  to the same rules as a field's;
- `vuetify`: the curated Vuetify set above.

A package's own capabilities reach the UI through the descriptor: a
`#[ShellContributor(name: 'acme')]` on the server ({ref}`shell-contributors`)
publishes its object as `host.schema.value.capabilities.acme`, absent
when the package is not installed: what a slot checks before it
renders.

### From a layer

A Nuxt layer over `@kinetis/vector-ui` reaches the same points without
`window.Vector`: `useVectorExtend()` returns every `define*` function,
`useVectorHost()` the host services. Call them in a client plugin:

```ts
// app/plugins/acme.client.ts
import AcmePanel from '../components/AcmePanel.vue'

export default defineNuxtPlugin(() => {
    useVectorExtend().defineSettingsAddon('acme', AcmePanel)
})
```

The AI features ({doc}`ai`) use these points too: their provider-key
panel is a settings addon of the `ai` group.

## Rich-text extensions

A node or mark type (a callout, an embed) joins the rich-text schema
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

The demo's *Stock overview* page is a fuller one, in `demo/backend/public/vector-extensions/`:

```{image} _static/screenshots/stock-overview-light.png
:alt: The demo's Stock overview page under Catalog: active products, units in stock and low-stock counts per category
:class: only-light
```

```{image} _static/screenshots/stock-overview-dark.png
:alt: The demo's Stock overview page under Catalog: active products, units in stock and low-stock counts per category
:class: only-dark
```

`group` places the page under a menu section (a resource group's name,
or its own); without one it goes under *Pages*. `title` and `group` may
also be functions returning the text, which the admin calls each time it
shows them, so a translated label follows a change of language without
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
(`dirty`, exposed with `reset()`), and with `variants` (as the create
and edit pages use it) offers the save variants (back to the record,
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

- {doc}`languages`: translating the admin.
- {doc}`deployment`: where extensions are served from.
- {doc}`ai`: the AI features, built on these points.
