# Customizing the UI

Three tiers, from no code to your own build. Use the lowest one that
does the job.

1. **Descriptor and settings.** Fields, filters, actions, widgets,
   navigation and the **Branding** settings. No JavaScript.
2. **Runtime extensions.** ES modules the admin loads at startup, adding
   cells, inputs, widget renderers and pages. JavaScript, no rebuild.
3. **Your own build.** A Nuxt application extending the layer,
   overriding any of its files.

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
        fields: {}, inputs: {}, widgets: {}, // component overrides
        locale: 'en', messages: {},    // see Languages
        pages: {},                     // custom pages, by name
    },
})
```

## Cells, inputs and widget renderers

Three registries decide what renders a value: **cells** (lists and
record pages), **inputs** (forms) and **widget renderers**. For a field,
the lookup order is:

1. an `app.config` override by the field's `component('name')`;
2. a runtime extension registered under that name;
3. an `app.config` override by field type;
4. the built-in for the type.

A cell receives `value`, `field`, `record`, `resource` and `context`
(`index` or `show`). An input receives `modelValue`, `field`, `rules`,
`errorMessages`, `record` and `resource` and emits `update:modelValue`.
A widget renderer receives `data` and `widget`.

## Runtime extensions

An extension is a plain ES module. List it in `VECTOR_UI_EXTENSIONS`
(hosted) or `runtimeConfig.public.vector.extensions` (standalone). Its
default export receives `window.Vector`, which hands it Vue, a curated
set of Vuetify components (`VAlert`, `VAutocomplete`, `VBtn`, `VCard`,
`VCheckbox`, `VChip`, `VIcon`, `VProgressLinear`, `VRating`, `VSelect`,
`VSwitch`, `VTable`, `VTextField`, `VTextarea`) and the registration
functions. For a table, render `VTable` with your `thead` and `tbody` as
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

A field then lists `callout` in its `features()`. The server accepts the
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

`group` places the page under a menu section; without one it goes under
*Pages*. The component receives `vector`, with `api`, `notify` and
`navigate`. An endpoint the page calls is your own code: put it in the
`vector` middleware group (`#[Middleware('@vector')]`) and the admin's
authentication and access policy apply to it.

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
