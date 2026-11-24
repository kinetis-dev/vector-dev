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
| `Textarea` | `text` | Long text, edited in a wide input. |
| `Json` | `json` | A JSON object or array in a JSON column (an `array` or `?array` property); see [JSON columns](#json-columns). Edited as JSON text; on the wire it is the document itself. Never sorted, filtered, searched or summarized. |
| `RichText` | `richtext` | Formatted content (headings, lists, links, tables, images) stored as a structured document; see below. |
| `Markdown` | `markdown` | Markdown text for technical content, edited visually or as source; `toolbar([...])`. |
| `Number` | `number` | Numbers; `decimals(n)`, `step()`. |
| `Money` | `money` | An amount in the currency's minor units (cents, whole yen); see [Money and currencies](#money-and-currencies). |
| `Currency` | `currency` | An ISO 4217 code in a string column, chosen from a searchable list that names each code in the UI's language (`EUR · Euro`). The list holds every code, or `allowed(['EUR', 'USD'])`. Codes are stored upper-case, anything else is refused, and it filters like a single select. It starts from `default('EUR')`, else `VECTOR_CURRENCY`. |
| `Boolean` | `boolean` | True or false. |
| `Date`, `DateTime` | `date`, `datetime` | A calendar day, `YYYY-MM-DD` on the wire with no time or zone; or an instant, ISO 8601 on the wire in UTC. See {doc}`databases` for `DATE` columns. |
| `Select` | `select` | A choice among fixed options, one value or several with `multiple()`; see [Select](#select). |
| `BooleanGroup` | `booleanGroup` | Named on/off options shown as one cell and edited as one group of checkboxes, in a bool column each or in one JSON column; see [BooleanGroup](#booleangroup). |
| `Country` | `country` | An ISO 3166-1 alpha-2 country code, or several: a select over every assigned code; see [Country](#country). |
| `KeyValue` | `keyValue` | A JSON object of text values in a JSON column (an `array` or `?array` property), edited as key/value rows; `columns('Attribute', 'Value')`, `maxEntries(n)` (50 by default). On the wire it is an object; a stored number or boolean reads back as text, and an empty object stores `null` where the column allows it. Keys are trimmed and at most 100 characters. A JSON column may reorder the keys. It cannot be sorted, filtered, searched or summarized, and is imported as JSON text. |
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
| `meta('vendor.key', $value)` | Metadata Vector publishes verbatim and never reads, for a package that builds on it; see {ref}`resource-meta`. |

Conditions (`visibleWhen()`, `requiredWhen()`, `disabledWhen()`),
dependent choices and repeaters are on {doc}`forms`.

## Passwords

A `Password` field stores what is written, so the resource's `create()`
or `apply()` hashes it, or the field is `hashed()`: the value is then
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
filled from the record. It copies every value the create form writes (a
many-to-many's membership, a repeater's rows as new rows) except a
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
| Select, currency, belongs-to | `eq ne in nin` |
| Multiple select | `any all` (a bare value is `any`) |
| Flag group | `any all` over option keys (a bare value is `any`); in columns mode each flag also takes `eq` as `group.key` |

A nullable column adds `null` and `nnull`. Collections are never
filterable. The list grammar and "any of" groups are on {doc}`rest-api`.

## Select

A select stores one of a fixed set of options, or several:

```php
Select::make('status')->of(OrderStatus::class),                    // a backed enum's cases
Select::make('size')->options(['s' => 'Small', 'm' => 'Medium']),  // value => label
Select::make('region')->options(fn () => Regions::all()),          // run once, when the resource compiles
Select::make('channels')->options(['web' => 'Web', 'retail' => 'Retail'])->multiple()->filterable(),
```

- **Options** come from an array of `value => label`, a closure
  returning one (called when the resource compiles, never per request),
  or a backed enum, labelled from its case names. `labels([...])`
  renames options and `colors([...])` gives each a colour. A select on a
  backed enum property with no options of its own offers the enum's
  cases; derivation makes one for every enum property.
- **`searchable()`** gives the input a search box over the options, for
  a long list. Unlike other fields, it does not make the list's search
  match the column.
- **`badge([value => colour])`** shows the values as coloured chips in
  lists and on the record. Without it, a value shows as its label, as a
  chip when its option has a colour.
- **`multiple()`** holds several options. They are stored as a JSON list
  of option values, without duplicates, in the order the options are
  declared, whatever order the input sent them in.

### Storage

The property must match the mode; the build refuses a mismatch and
converts nothing.

| Mode | Property | Column |
|---|---|---|
| Single | `string`, `int`, or a backed enum | `VARCHAR`, an integer, or the enum's backing type |
| Multiple | `array` or `?array` | `JSON` on MySQL and MariaDB, `jsonb` on PostgreSQL |

A single select on a string property stores every option value as text
(`404 => 'Not found'` is `"404"`); on an int property every option value
must be an integer; on an enum property every option must be one of its
cases, and the entity receives the case. A multiple select stores the
option values as declared (an int-backed enum's as numbers). An empty
list is `null` where the property allows it.

A multiple select is never sortable, grouped, summarized or edited in a
list cell; the build refuses each. Its `default()` is a list of option
values.

### Strict options

A value outside the options is refused on write: `invalid_choice` on the
field, and for a multiple select on the offending value's index
(`channels/1`). A JSON body must send an option's own type: `2`, not
`"2"`, for an int option. A stored value outside the options throws
`Kinetis\Vector\Exception\StoredValueException` on read rather than
vanishing from the record. **Removing an option therefore needs a data
migration first**: rewrite or clear the rows that still hold it, then
remove it from the field.

### Filters

A single select filters with `eq ne in nin`, like any value. A multiple
select filters with `any` (holding at least one of the listed options,
the default) and `all` (holding every one of them), over a
comma-separated list of option values:

```
GET /vector/r/products?filter[channels][any]=web,retail
GET /vector/r/products?filter[channels][all]=web,retail
GET /vector/r/products?filter[channels]=web        # a bare value is "any"
```

A nullable one adds `null` and `nnull`. The SQL is written per dialect
with every value bound: `JSON_CONTAINS` on MySQL and MariaDB, `?|` and
`@>` on PostgreSQL's `jsonb`. An index can help on PostgreSQL
(`CREATE INDEX ... USING GIN (channels)`); on MySQL a multi-valued index
over the column can.

A CSV import reads a multiple select's cell as the JSON list an export
writes (`["web","retail"]`) or as values separated by `|`.

## BooleanGroup

A flag group holds several named on/off options as one value: a list
shows the labels of the ones that are on, a form a group of checkboxes.

```php
BooleanGroup::make('contact', 'Contact by')
    ->options(['newsletter' => 'Newsletter', 'acceptsSms' => 'Text message', 'acceptsPost' => 'Post'])
    ->sortable()->filterable(),                                     // columns mode: three bool properties

BooleanGroup::make('flags')
    ->options(['vip' => 'VIP customer', 'legalHold' => 'Legal hold'])
    ->json()->filterable(),                                         // JSON mode: one array property
```

On the wire the value is an object of every option key to `true` or
`false`: `{"newsletter": true, "acceptsSms": false, "acceptsPost": true}`.
A key a write leaves out is `false`, so a write sets every flag. An
option key is a name of letters, digits and underscores.

### Storage

| Mode | Property | Column |
|---|---|---|
| Columns (the default) | one non-null `bool` property per option key; the field's own name is no property | a boolean column each (`TINYINT(1)` on MySQL, `BOOLEAN` on PostgreSQL) |
| JSON (`json()`) | the field's own `array` or `?array` property | `JSON` on MySQL and MariaDB, `jsonb` on PostgreSQL, holding the object |

The build refuses a mismatch and converts nothing: an option key that is
not a non-null bool property in columns mode, a group named after a
property in columns mode, a flag that is also declared as a field of its
own (the group writes it), JSON mode on a property that is not an array,
a group without options, and a `default()` that is not an object of
some of its keys to booleans. A columns-mode group also counts as
writing its flags' properties when the build checks that a record can be
created. Bit masks are not supported.

### Strict keys

A write naming a key that is no option is refused with `invalid_choice`
on that key (`contact/acceptsFax`), and a value that is not a boolean
with `type_mismatch`. In JSON mode a stored key missing from the object
reads as `false`, and a stored key that is no option throws
`Kinetis\Vector\Exception\StoredValueException` on read. **Removing an
option therefore needs a data migration first**: remove the key from the
stored objects (or drop its column in columns mode), then remove it from
the field.

### Filters and sorting

Both modes filter the group with `any` (at least one of the listed keys
is on, the default) and `all` (every one of them is on):

```
GET /vector/r/customer-profiles?filter[contact][any]=acceptsSms,acceptsPost
GET /vector/r/tickets?filter[flags][all]=vip,legalHold
GET /vector/r/tickets?filter[flags]=vip                       # a bare value is "any"
```

A nullable JSON column adds `null` and `nnull`. In JSON mode each key
travels inside a bound `{"key": true}` object matched by containment
(`JSON_CONTAINS` on MySQL and MariaDB, `@>` on PostgreSQL), and only
keys the field declares are accepted.

In columns mode each flag is also a boolean of its own, named
`group.key`: it filters with `eq` when the group is `filterable()` and
sorts when the group is `sortable()`. The group itself is not sortable.

```
GET /vector/r/customer-profiles?filter[contact.acceptsSms]=true
GET /vector/r/customer-profiles?filter[contact.newsletter][eq]=false&sort=-contact.acceptsPost
```

A JSON-mode group is never sortable; the build refuses `sortable()` on
it. No flag group is grouped, summarized, searched or edited inline, and
a form condition cannot test one. A CSV import reads the group's cell as
the JSON object an export writes.

## Country

`Country` is a select preset over the assigned ISO 3166-1 alpha-2 codes,
searchable by default:

```php
Country::make('country')->filterable(),                             // one code, a string property
Country::make('countries', 'Countries served')->multiple(),         // several, an array property
Country::make('market')->only(['NL', 'DE', 'AT']),                  // just these codes, in this order
```

It is a `Select` in every other respect: the same storage rules (a
string property for one code, an `array` or `?array` property for
several), `badge()`, the same filters (`eq ne in nin`, or `any all` for
a multiple one) and the same strictness. Codes are upper case: `de`,
`DEU` and `Germany` are refused with `invalid_choice`. `only()` narrows
the list, and the build refuses a code that is not assigned. The server
names each code in English (in `vector_describe`, OpenAPI and exports);
the admin shows the name in the UI's language, for each shipped locale,
from data generated with `scripts/generate-countries.mjs`. The codes
and names live in `Kinetis\Vector\Support\Countries`.

## JSON columns

`Json` and `KeyValue` live in JSON columns: an `array` or `?array`
property, which the ORM decodes to arrays (a JSON object becomes an
associative array, a JSON array a list). Declare the column `JSON` on
MySQL and MariaDB and `jsonb` on PostgreSQL. The build refuses either
field on any other property.

- `Json` reads as the stored document, an object staying an object even
  when empty, and writes a JSON object or array; a bare string, number
  or boolean is refused. A CSV cell is the document's JSON text.
- `KeyValue` reads and writes an object of text values, as before.

Neither is sorted, filtered, searched, summarized or grouped.

## Rich text

`RichText` gives authors a word-processor-like editor (Tiptap, on
ProseMirror) and stores what they write as the editor's **JSON
document** (a tree, not markup) in a string property on a `TEXT` or
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
  `mention`: all but images and mentions by default.
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
configured with exactly the given features, never its highlighting code
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
only what HTML carries: prefer the document for new columns. The demo's
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

- `as()`: how it is shown: `text`, `number` (`decimals()`), `money`
  (`currency()` or `currencyField()`), `boolean`, `date`, `datetime`,
  or `select` with `options()`. The value is typed by it and exported like
  any field.
- `from()`: the fields the method reads: every list and record that
  shows the computed value carries them too, whatever the `columns`
  asked for.
- `method()`: a public instance method of the resource, built by the
  request container and called once per record with the record already
  serialized. It runs no query of its own, so a list's statement count
  does not change.

A computed field is read-only, and never sorted, filtered, searched,
summarized, written or imported. The build refuses one that asks for
any of those, one without a method, one whose method is not a public
instance method, a select without options, and a source that is not a
field holding a value. If a user may not see a field it reads
(`viewField()`), they do not see the computed field either, so a
derived value never shows what the user may not see. The descriptor
publishes `computed: {as, from}`; the method's name stays on the server.

## Relations

- `BelongsTo` shows the target's title and edits with a searchable
  picker. `titleField()` overrides which field names the target.
  `searchable()` makes the list's search match that title.
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

- {doc}`forms`: conditions, dependent choices, repeaters, inline edits.
- {doc}`files`: uploads.
- {doc}`authorization`: hiding fields per user.
