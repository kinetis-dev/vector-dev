# Fields

A field maps one entity property to a column in lists, a value on the
record page and an input in forms. `Text::make('sku', 'SKU')` names the
property and, optionally, the label; without one the label is derived
from the name.

## Catalog

The descriptor names each field by its type, the identifier in the
second column.

| Class | Type | Holds |
|---|---|---|
| `Id` | `id` | The identifier. Read-only, sortable, never on a form. |
| `Text`, `Email`, `Url`, `Password`, `Slug`, `Color` | `string`, `email`, `url`, `password`, `slug`, `color` | String columns; `searchable()` allowed. `Slug::from('name')` derives a slug. `Email` must be an address and `Url` an absolute http(s) URL (the admin shows it as a link), unless the field declares an e-mail, URL or pattern rule of its own. `Password`: see [Passwords](#passwords). |
| `Textarea`, `Json` | `text`, `json` | Long text, edited in wide inputs. A JSON value is a string column. |
| `RichText` | `richtext` | Formatted content — headings, lists, links, tables, images — stored as a structured document; see below. |
| `Markdown` | `markdown` | Markdown text for technical content, edited visually or as source; `toolbar([...])`. |
| `Number` | `number` | Numbers; `decimals(n)`, `step()`. |
| `Money` | `money` | An amount in the currency's minor units (cents, whole yen); see [Money and currencies](#money-and-currencies). |
| `Currency` | `currency` | An ISO 4217 code in a string column, chosen from a searchable list that names each code in the UI's language (`EUR · Euro`). The list holds every code, or `allowed(['EUR', 'USD'])`. Codes are stored upper-case, anything else is refused, and it filters like an enum. It starts from `default('EUR')`, else `VECTOR_CURRENCY`. |
| `Boolean` | `boolean` | True or false. |
| `Date`, `DateTime` | `date`, `datetime` | A calendar day, `YYYY-MM-DD` on the wire with no time or zone; or an instant, ISO 8601 on the wire in UTC. See {doc}`databases` for `DATE` columns. |
| `Enum` | `enum` | A backed enum: `of(Status::class)`, `labels()`, `colors()`; its options are published in the descriptor. |
| `KeyValue` | `keyValue` | A JSON object of text values in a string column, edited as key/value rows; `columns('Attribute', 'Value')`, `maxEntries(n)` (50 by default). On the wire it is an object; a stored number or boolean reads back as text, and an empty object stores `null` where the column allows it. Keys are trimmed and at most 100 characters. A JSON column may reorder the keys. It cannot be sorted, filtered, searched or summarized, and is imported as JSON text. |
| `File`, `Image` | `file`, `image` | An uploaded file's storage key; see {doc}`files`. |
| `Computed` | `computed` | A read-only value a method of the resource computes from the record; see below. |
| `BelongsTo`, `HasOne`, `HasMany`, `ManyToMany` | `belongsTo`, `hasOne`, `hasMany`, `manyToMany` | Relations to another resource, made with `::to('name', TargetResource::class)`. `titleField()` overrides the target's title; `BelongsTo::dependsOn()` and `HasMany::repeater()` are on {doc}`forms`. |

## Common modifiers

Every field takes:

| Modifier | Effect |
|---|---|
| `label()`, `help()` | Its label, and a hint under the input. |
| `required()`, `min()`, `max()`, `rule(Constraint::class, ...)` | Validation, checked by the server and mirrored by the form. Any framework constraint may be used. |
| `default()` | The value a new record starts with: the create form shows it pre-filled, and a create that leaves the field out takes it, validated like a value that was sent. Action dialogs and settings pages start from defaults too. |
| `readonly()` | Shown, never written. |
| `sortable()`, `searchable()`, `filterable()` | Allowed in the list grammar. |
| `hideFromIndex()`, `hideFromShow()`, `hideFromForms()`, `onlyOn*()` | Where the field appears. |
| `summarize('sum'\|'avg'\|'min'\|'max'\|'count')` | A totals row under the list. |
| `inlineEditable()` | Editable in its list cell; see {doc}`forms`. |
| `unique()` | The value is unique (as its column's index enforces; the ORM's metadata does not carry indexes, so the field says so): a duplicated record starts without it, and an import can match rows by it. The descriptor publishes `unique` on every field. |
| `component('name')` | Rendered by a custom cell or input; see {doc}`customizing-the-ui`. |
| `props([...])` | Extra settings passed to the UI. |

Conditions (`visibleWhen()`, `requiredWhen()`, `disabledWhen()`),
dependent choices and repeaters are on {doc}`forms`.

## Passwords

A `Password` field stores what is written, so the resource's `create()`
or `apply()` hashes it — or the field is `hashed()`: the value is then
hashed through the application's
`Kinetis\Vector\Auth\PasswordHasherInterface`, bound on `AppScope` in
`bootstrap.php`, or through the implementation `hashed(OtherHasher::class)`
names, for a table hashed differently. When a field hashes through the
interface and nothing binds it, the first request refuses with the
reason rather than storing plain text. An empty value on a form keeps
the stored hash.

## Duplicating

A record's page offers **Duplicate** when the user may create and the
resource is `replicable()` (true unless it returns false): a create form
filled from the record. It copies every value the create form writes — a
many-to-many's membership, a repeater's rows as new rows — except a
field marked `unique()`, which its column's unique index would refuse,
and file or image keys, since a stored key belongs to its record. The
copy is saved through the ordinary create. The descriptor publishes
`replicable` on the resource.

## Filter operators

A filterable field offers the operators its value admits:

| Value | Operators |
|---|---|
| Text | `eq ne like nlike in nin` |
| Number, money, id | `eq ne lt lte gt gte between in nin` |
| Boolean | `eq` |
| Date, datetime | `eq ne lt lte gt gte between` (a day against a timestamp spans the day) |
| Enum, belongs-to | `eq ne in nin` |

A nullable column adds `null` and `nnull`. Collections are never
filterable. The list grammar and "any of" groups are on {doc}`rest-api`.

## Rich text

`RichText` gives authors a word-processor-like editor (Tiptap, on
ProseMirror) and stores what they write as the editor's **JSON
document** — a tree, not markup — in a string property on a `TEXT` or
`JSON` column. The server validates every write against a fixed schema,
so sanitizing becomes schema validation:

```php
RichText::make('description')
    ->features(['bold', 'italic', 'heading', 'list', 'link', 'table'])  // default: all but image and mention
    ->imageField('imageKey')        // images upload through this file/image field and store its key
    ->mentions('assignee')          // @ offers the records of this relation
    ->textField('descriptionText'), // search reads the plain text the writer fills here
```

- **Features** are what the editor offers and the server accepts:
  `bold`, `italic`, `underline`, `strike`, `code`, `heading`, `list`,
  `blockquote`, `codeBlock`, `horizontalRule`, `link`, `table`, `image`,
  `mention` — all but images and mentions by default.
- **Validation.** The value must be valid JSON with a
  `{"type": "doc", "content": [...]}` root; every node and mark type must
  be one of the field's features, and every attribute one that type
  defines; a link goes only to `http(s):`, `mailto:` or a relative
  place; the document is at most `maxBytes()` (256 KiB by default), 32
  levels deep and 20 000 nodes. A refusal is a `422` on the field, with
  the path into the document
  (`description/content/2/marks/0/attrs/href`). An empty document is no
  value, so `required()` works.
- **Images** store a **storage key, never a URL**: one the image field's
  upload endpoint issued, or one already in the stored document. The
  admin fetches it with its credentials through
  `GET /vector/r/{resource}/{id}/files/{imageField}?key=…`, which serves
  only keys a document of that record shows.
- **Search** needs `textField('bodyText')`: a second string property the
  writer fills with `Document::toPlainText()` on every write. The build
  refuses `searchable()` without one. A rich-text field is never sorted
  or summarized, and filtered only by `null` and `nnull`.

### On your own pages

`Kinetis\Vector\RichText\Document` renders a stored value without
knowing the editor:

```php
public function __construct(private Document $document) {}

$html = $this->document->toHtml($product['description'], Document::DEFAULT_FEATURES,
    static fn (string $key): ?string => "/media/{$key}");   // or null to leave an image out
```

`toHtml()` renders through
[`ueberdosis/tiptap-php`](https://github.com/ueberdosis/tiptap-php),
configured with exactly the given features — never its highlighting code
blocks, which reach the filesystem or a process. What they do not cover
is dropped, and text and attributes are escaped. `toPlainText()` is the
projection CSV exports and search use. The demo's shop page,
`GET /shop/products/{id}`, renders the product description this way.

An application node type, such as a callout or an embed, is registered
on both sides; see {doc}`customizing-the-ui`.

### HTML storage

`RichText::make('body')->storesHtml()` is an opt-in for an existing HTML
column: the admin still sends the document, and the server stores
`toHtml()` of it. The admin reads the stored HTML back through the same
schema, so anything outside it is dropped on the next save. Fidelity is
only what HTML carries — prefer the document for new columns. The demo's
ticket body is one.

### Markdown

`Markdown` is for technical text. The stored value is the Markdown
source; the admin edits it visually through `@tiptap/markdown`, with a
view of the source, and shows it through markdown-it (HTML in the source
off, bare URLs linked) and the same sanitizer. The descriptor publishes
`richtext: {features, imageField, mentions, storesHtml, maxBytes}` and
`markdown: {toolbar}`.

### In the browser

The admin renders HTML only from a document it generated itself through
the field's schema (or from markdown-it with HTML off), then passes it
through DOMPurify with an allow-list matching that schema: no `style`,
no event handlers, no `src`. That happens in the layer's only `v-html`,
in `Vx/Cell/RichText.vue` and `Vx/Cell/Markdown.vue`. A sanitizer that
fails its canary check is not trusted, and values are then shown as
text. DOMPurify is dual-licensed MPL-2.0 OR Apache-2.0; the layer uses
it under Apache-2.0. Under the hosted shell's strict CSP the editor's
own style element carries the response nonce, table resizing (which
writes `style` attributes) is off, and images display as `data:` URLs:
the editor and the record page run without a single violation.

## Computed fields

A computed field shows a value your resource calculates, such as a line
total or a full name, on the list and the record page:

```php
Computed::make('lineTotal', 'Total')->as('money')->currencyField('order.currency')
    ->from(['quantity', 'unitPriceCents'])->method('lineTotal'),

/** @param array<string, mixed> $line the serialized record */
public function lineTotal(array $line): ?int
{
    return $line['quantity'] * $line['unitPriceCents'];
}
```

- `as()` — how it is shown: `text`, `number` (`decimals()`), `money`
  (`currency()` or `currencyField()`), `boolean`, `date`, `datetime`,
  or `enum` with `options()`. The value is typed by it and exported like
  any field.
- `from()` — the fields the method reads: every list and record that
  shows the computed value carries them too, whatever the `columns`
  asked for.
- `method()` — a public instance method of the resource, built by the
  request container and called once per record with the record already
  serialized. It runs no query of its own, so a list's statement count
  does not change.

A computed field is read-only, and never sorted, filtered, searched,
summarized, written or imported. The build refuses one that asks for
any of those, one without a method, one whose method is not a public
instance method, an enum without options, and a source that is not a
field holding a value. If a user may not see a field it reads
(`viewField()`), they do not see the computed field either, so a
derived value never shows what the user may not see. The descriptor
publishes `computed: {as, from}`; the method's name stays on the server.

## Relations

- `BelongsTo` shows the target's title and edits with a searchable
  picker. `titleField()` overrides which field names the target.
- `HasMany` and `ManyToMany` show a count in lists and a panel on the
  record page. A many-to-many is edited in the form, or attached and
  detached from the panel.
- A relation never crosses databases; a value pointing at another
  database is an ordinary field. See {doc}`databases`.

## Money and currencies

Money is stored in minor units, following the currency's ISO 4217
exponent: hundredths for most currencies, whole units for JPY,
thousandths for BHD. The schema publishes the exceptions to hundredths
as `minorUnits`, so the UI types and formats each amount correctly. The
input keeps what is typed and writes two decimals only when the field
is left.

The currency comes from one of three places:

- `currency('EUR')`, fixed;
- `currencyField('currency')`, a field of the same record, serialized
  with every view that shows the money field;
- `currencyField('order.currency')`, a field of a related record reached
  through a belongs-to. That relation then carries the value as
  `{id, label, currency}`, and inside a repeater a row reads the parent
  being edited.

A filter on a money field priced per record reads the typed amount in
hundredths. `VECTOR_CURRENCY` sets the starting code of a `Currency`
field that declares no `default()` (and allows the code).

## See also

- {doc}`forms` — conditions, dependent choices, repeaters, inline edits.
- {doc}`files` — uploads.
- {doc}`authorization` — hiding fields per user.
