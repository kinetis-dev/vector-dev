# kinetis/vector

A schema-driven admin platform for [Kinetis](https://kinetis.dev)
applications. You declare *resources* over your `kinetis/orm` entities;
Vector compiles them into an immutable descriptor at `kinetis build`,
serves a REST admin API from it, runs actions and background jobs, and
exposes the same services as MCP tools. The admin UI is a separate
package, [`@kinetis/vector-ui`](../vector-ui/README.md), which renders
the descriptor and never contains application knowledge.

Everything a request may name — a field, a column, a sort, a filter, an
action, a widget — comes from the compiled descriptor, so no request
text ever reaches SQL as an identifier. Everything that runs per request
is built by the request container; nothing Vector holds on the
application scope carries request state.

## Installation

```sh
composer require kinetis/vector
vendor/bin/kinetis vector:install      # publishes migrations and, when built, the hosted UI
vendor/bin/kinetis migrate
vendor/bin/kinetis build               # production: compiles the registry into the cache
```

`vector:install` copies Vector's migrations, one per table, each under
its own timestamp: `vector_settings`, `vector_preferences`,
`vector_views`, `vector_action_events`, `vector_audit`, `vector_jobs`
and `vector_notifications`. It is idempotent. A later change to those
tables will ship as a new migration with a later timestamp, so run
`vector:install` again after an upgrade, then `migrate`. It publishes
the MySQL or the PostgreSQL version of each, as the connection's
`DB_CONNECTION` key says (`mysql` or `pgsql`).

### Multi-database applications

An entity may live on a named connection
(`#[Entity(table: 'tickets', connection: 'support')]`, with its
`DB_SUPPORT_*` keys and its migrations in `migrations/support/`), and a
resource over it needs nothing extra. The compiled descriptor records
each resource's connection; every read — lists, filters, search,
summaries, relation labels and counts, options, related lists, export,
global search — is built on that connection through the request's
`EntityManagerRegistry`, and every write — create, update, delete,
restore, batch, actions — flushes through that connection's manager. A
related record is loaded through the manager of the connection it
lives on. Relationships never span two connections (the ORM refuses
such a mapping at build time), so every relation Vector follows stays
on one database; a value that points at another database, such as a
shop customer's id on a support ticket, is an ordinary field.

Each connection commits on its own: an action or batch over one
resource is one flush, and Vector's own rows (action log, audit, jobs)
commit on Vector's connection after it.

### Vector's tables on another connection

By default Vector's own tables live on the application's default
connection. To keep them apart — another database, or another server
and dialect — name a connection:

```sh
vendor/bin/kinetis vector:install --connection=admin
vendor/bin/kinetis migrate            # or: migrate --connection=admin
```

```text
DB_ADMIN_CONNECTION=pgsql
DB_ADMIN_HOST=db.internal
DB_ADMIN_NAME=vector_admin
DB_ADMIN_USER=vector
DB_ADMIN_PASSWORD=secret
VECTOR_DB_CONNECTION=admin
```

The migrations go to `migrations/admin/`, the partition
`kinetis/migrations` runs on that connection with a ledger of its own.
`VECTOR_DB_CONNECTION` tells Vector at runtime where its tables are;
the installer prints it when it is missing. Vector uses the
application's `db.admin` binding when `bootstrap.php` makes one, and
otherwise builds a link from the `DB_ADMIN_*` keys and closes it with
the worker. Only Vector's tables move: the administered entities stay
on the connection their `#[Entity]` names, and the queue on
`QUEUE_CONNECTION_NAME`. A connection name is lowercase letters and
digits starting with a letter; `app` and `default` are reserved.

Both dialects are supported on every connection — Vector's own, the
default, and every named one an entity uses. On PostgreSQL the
two view flags are `SMALLINT` 0/1 as on MySQL, a job's file is stored
as base64 text (at most 12 MiB of file, MySQL's `MEDIUMBLOB`), and
search, the `contains` filter and relation option search use `ILIKE`,
so they ignore case on both dialects. A unique violation is mapped to
its field from PostgreSQL's `Key (column)=` detail or MySQL's index
name. A calendar day is a `Kinetis\Orm\Date` property on a `DATE`
column: its `Date` field reads and writes `YYYY-MM-DD` with no time or
zone, and a `DateTime` field on one is refused at build time. A `Date`
field on a timestamp property shows the instant's date part. Background jobs additionally need `kinetis/queue` 1.7 or later with a
backend (the connection's `QUEUE_CONNECTION` selector and whatever that
backend needs: the SQL backend's table, a Redis server, …) and a
`kinetis queue:work` process.

The application supplies three things (see [Authentication and
authorization](#authentication-and-authorization)):

1. authentication middleware in the `vector` group that registers
   `Kinetis\Http\CurrentUserInterface`;
2. a `Kinetis\Vector\Auth\VectorAccessInterface` bound on `AppScope`;
3. optionally, `ActorResolverInterface` (jobs) and, per request,
   `ImpersonationInterface`.

## Resources

A resource is a class extending `Kinetis\Vector\VectorResource` carrying
`#[Resource(entity: ...)]`:

```php
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\{BelongsTo, Boolean, Enum, Id, ManyToMany, Money, Number, Text};
use Kinetis\Vector\VectorResource;

#[Resource(entity: Product::class, group: 'Catalog', icon: 'mdi-package-variant', title: 'name')]
final class ProductResource extends VectorResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('sku', 'SKU')->required()->max(40)->sortable()->searchable()->filterable(),
            Text::make('name')->required()->max(200)->sortable()->searchable()->filterable(),
            Enum::make('status')->of(ProductStatus::class)->colors(['draft' => 'warning', 'active' => 'success'])->sortable()->filterable(),
            BelongsTo::to('category', CategoryResource::class)->sortable()->filterable(),
            Money::make('priceCents', 'Price')->currencyField('currency')->sortable()->filterable()->summarize('avg'),
            Number::make('stock')->sortable()->filterable()->summarize('sum')->component('stock-level'),
            Boolean::make('isFeatured', 'Featured')->filterable(),
            ManyToMany::to('tags', TagResource::class),
        ];
    }

    public function defaultSort(): array { return ['-createdAt']; }
    public function softDeletes(): string { return 'deletedAt'; }
    public function actions(): array { return [PublishProducts::class]; }
    public function widgets(): array { return [ProductsByStatus::class]; }
}
```

Global search (`globallySearchable()`, on by default) lists a record by
its title. `searchSubtitle()` names a text, number or enum field to show
under the title, such as a SKU. `searchImage()` names an image field to
show as a thumbnail. Both are checked at compile time, and both drop out
for a user who may not see the field. A relation input's choice list
shows them too: each option is `{id, label, subtitle?, image?}`, read in
the options' one statement, and the UI fetches the thumbnail through the
target's authenticated file endpoint, so two customers with the same
name are told apart by their e-mail.

`badge()` puts a count beside the resource in the menu. It returns the
records matching a filter in the list grammar, counted as the user's own
list would count them, with scope and soft deletion applied. The filter
must name filterable fields with operators they take. The UI polls
`GET /vector/badges` every minute and after each navigation. The count
is left out for a user who may not list the resource or see a filtered
field.

```php
public function badge(): Badge
{
    return Badge::count(['status' => 'open'], 'info', 'open tickets');
}
```

An empty class derives every field from the entity's ORM metadata (a
string column becomes a searchable `Text`, an enum a filterable `Enum`,
a `#[BelongsTo]` a relation when its target has a resource, and so on).
`fields()` replaces derivation entirely. The definition methods —
`fields()`, `actions()`, `widgets()`, `policy()`, `defaultSort()`,
`perPage()`, `softDeletes()`, `timestamps()`, `exportLimit()`,
`bulkLimit()`, `searchSubtitle()`, `searchImage()`, `badge()` — run at compile time on an instance built without
arguments and return plain data. The request-time hooks —
`scope(Query, CurrentUserInterface)`, `create()`, `apply()`,
`before*`/`after*` — run on an instance the request container builds
and may inject anything a controller may.

Compile-time validation refuses, with the class and field named: a
field that maps no property, a `#[BelongsTo]` declared without
`BelongsTo::to()`, a relation whose target is not a registered resource,
a sortable field without a column, a searchable field that is not a
string column, a filterable collection, a summary on a non-numeric
column, a `defaultSort` over a non-sortable field, an unadministered
identifier, a `softDeletes()` property that is not a nullable
`DateTimeImmutable`, and a duplicate field, action or widget name.

### Field catalogue

| Class | Type | Notes |
|---|---|---|
| `Id` | `id` | Read-only, sortable, never on a form. |
| `Text`, `Email`, `Url`, `Password`, `Slug`, `Color` | `string` … `color` | String columns; `searchable()` allowed. `Slug::from('name')`. |
| `Textarea`, `Json` | `text`, `json` | Wide inputs; JSON is a string column. |
| `RichText` | `richtext` | Long-form content edited in a word-processor-like editor and stored as its JSON document; see [Rich text](#rich-text). |
| `Markdown` | `markdown` | Markdown source for technical content, edited WYSIWYG (with a source view) and shown rendered and sanitized; `toolbar([...])`. |
| `Currency` | `currency` | An ISO 4217 code in a string column, chosen from a searchable list that names each code in the UI's language (`EUR · Euro`). The list holds every code, or `allowed(['EUR', 'USD'])`. Codes are stored upper-case, anything else is refused, and it filters like an enum. Its default is `default('EUR')`, else `VECTOR_CURRENCY`. |
| `KeyValue` | `keyValue` | A JSON object of text values in a string column, edited as key/value rows. On the wire it is an object; a stored number or boolean reads back as text, and an empty object stores `null` where the column allows it. Keys are trimmed and at most 100 characters. `maxEntries(n)` (50 by default), `columns('Attribute', 'Value')`. A JSON column may reorder the keys. It cannot be sorted, filtered, searched or summarized. Imported as JSON text. |
| `Number` | `number` | `decimals(n)`, `step()`; `summarize()`. |
| `Money` | `money` | Minor units, in the currency's ISO 4217 exponent: cents for EUR, whole yen for JPY, thousandths for BHD. The schema publishes the exceptions to hundredths as `minorUnits`, and the UI types and formats with them. A filter on a money field priced per record reads the typed amount in hundredths. The currency can come from three places:<br>• `currency('EUR')`, fixed;<br>• `currencyField('currency')`, a field of the same record, serialized with every view that shows the money field;<br>• `currencyField('order.currency')`, a field of a related record reached through a belongsTo. That relation then carries the value as `{id, label, currency}`, and inside a repeater a row reads the parent being edited.<br>The input keeps what is typed and writes two decimals only when the field is left. |
| `Boolean` | `boolean` | |
| `Date`, `DateTime` | `date`, `datetime` | ISO 8601 on the wire, UTC. |
| `Enum` | `enum` | `of(BackedEnum::class)`, `labels()`, `colors()`; options published. |
| `File`, `Image` | `file`, `image` | Storage keys of uploaded files; see [Files and images](#files-and-images) for the upload and download endpoints. |
| `Computed` | `computed` | A read-only value a resource method computes from the record; see [Computed fields](#computed-fields). |
| `BelongsTo::to()`, `HasOne::to()`, `HasMany::to()`, `ManyToMany::to()` | relation | `titleField()` overrides the target's title; `BelongsTo::dependsOn()` narrows its choices by another field; `HasMany::repeater()` edits an owned relation's rows in the parent's form. |

A field's `default()` is where a new record starts: the create form shows
it pre-filled, and a create that leaves the field out takes it, validated
like a value that was sent. Action dialogs and settings pages start from
defaults too.

Every field takes `label()`, `help()`, `sortable()`, `searchable()`,
`filterable()`, `readonly()`, `hideFromIndex()/Show()/Forms()`,
`onlyOn*()`, `rule(Constraint::class, ...args)` (any framework
constraint), `required()`, `max()`, `min()`, `default()`, `props()`,
`component('name')` (a UI cell/input by name), `summarize('sum'|'avg'|'min'|'max'|'count')`,
`inlineEditable()` and `unique()`.

**Duplicate.** A record's page offers **Duplicate** when the user may
create and the resource is `replicable()` (true unless it returns
false): a create form filled from the record. It copies every value the
create form writes — a many-to-many's membership, a repeater's rows as
new rows — except a field marked `unique()` (the column's unique index
would refuse it; the ORM's metadata does not carry indexes, so the field
says so) and file or image keys (a stored key belongs to its record). The
copy is saved through the ordinary create. The descriptor publishes
`unique` on every field and `replicable` on the resource.

**Conditions.** `visibleWhen($field, $op, $value)`, `requiredWhen(...)`
and `disabledWhen(...)` make a field depend on another field of the same
form (a resource's, an action dialog's or a settings group's). The
operators are `eq`, `ne`, `in`, `nin` (a list), `null` and `nnull` (no
value); repeated calls of one kind must all hold. The form evaluates
them as values are edited, and the server evaluates the same conditions
against the stored record overlaid with the request: a field hidden or
disabled by its conditions has its submitted value ignored, and one
whose `requiredWhen` holds must end up non-empty. A relation compares by
its id. A condition on a field the form does not have, or on the field
itself, fails the build; a collection can only be tested with `null` or
`nnull`.

```php
DateTime::make('releasedAt')
    ->visibleWhen('status', 'ne', 'draft')
    ->requiredWhen('status', 'eq', 'active'),
Boolean::make('isFeatured')->disabledWhen('status', 'eq', 'archived'),
```

**Repeaters.** `HasMany::to('lines', OrderLineResource::class)->repeater(['product', 'quantity', 'unitPriceCents'], min: 1, max: 50)`
edits an owned relation's rows inside the parent's form. The whole list
is sent with the parent (`"lines": [{"id": 7, "quantity": 3, ...}, {"product": 4, ...}]`)
and written in one transaction:
- a row with an `id` updates that child;
- a row without one creates a child pointing at the parent;
- a child left out is deleted.

Each row is validated as the child resource validates a record, and a
violation names its row (`lines/1/quantity`). The child's policy
authorizes each create, update and delete. Its hooks run and its audit
trail records each change, as if the child had been written on its own.
The parent's hooks run after the rows are in place, so a total can be
recomputed there.
`GET /vector/r/{resource}/{id}/{relation}/rows` returns the current rows
for an edit form. Compilation refuses a repeater in these cases:
- it is over a relation that is not owned;
- it leaves out a child field a new row requires;
- the child resource soft-deletes;
- it carries conditions.

Repeaters are not written in bulk, inline, or by import.

**Dependent choices.** `BelongsTo::to('assignee', AgentResource::class)->dependsOn('team')`
offers only the agents whose `team` equals the form's `team`. The
second argument names the target's property when the names differ
(`dependsOn('team', on: 'squad')`). The input waits until the form has a
team, and it drops its choice when the team changes. The options
endpoint narrows by `depends[team]=…`; without that parameter it
searches every record, as a list filter does. A write that changes the
choice or the team must keep the choice among the agents the options
would offer. Otherwise the choice is refused with `invalid_choice`.
Unchanged values are not re-checked, so records stored before the rule
still save. A dependency on a field the form lacks, or on a property
the target does not map, fails the build.

```php
BelongsTo::to('team', TeamResource::class),
BelongsTo::to('assignee', AgentResource::class)->dependsOn('team'),
```

**Layout.** `fields()` may return sections and tabs among the fields to
group them on the form and the show page:

```php
use Kinetis\Vector\Fields\Layout\{Section, Tabs};

return [
    Id::make(),
    Section::make('Details')->fields(['sku', 'name', 'status']),
    Section::make('Pricing')->description('In the product\'s currency.')->collapsible()->columns(2)
        ->fields(['priceCents', 'currency']),
    Tabs::make()
        ->tab('Content', ['description', Section::make('Media')->fields(['imageKey'])])
        ->tab('Publishing', ['isFeatured', 'releasedAt']),
    Text::make('sku'), Text::make('name'), // … every field, in its own order
];
```

A section or tab holds fields, or the names of fields declared elsewhere
in `fields()`, which it then places; a tab may also hold sections, and a
section holds only fields. A top-level field keeps its place. A
section's grid has `columns(1..4)` (2 by default), and a field takes
`columnSpan(1|2|'full')` of it, or a width the UI picks by its type.
`collapsible()` folds a section open and closed; `collapsed()` starts it
closed. A field declaring `columnSpan()` gives the resource a layout even
without sections.

Layout never changes what is validated, serialized, filtered or
written: the compiler flattens it to the same field list and publishes
it apart, as the descriptor's `layout` — `{form, show}`, each a tree of
nodes (`section`, `tabs`, `tab`, `field`), with the fields that view
shows and without a section or tab left empty; `null` for a resource
without one. A field `viewField()` hides leaves it the same way. A name
the resource does not declare, or one placed twice, fails the build. The
form opens the section and activates the tab holding a field with an
error.

**Inline editing.** `inlineEditable()` lets the list edit a field in its
cell. Edits across rows collect into one changeset that is saved with
`POST /vector/r/{resource}/changes`. Each record in it is authorized,
validated and hooked as a `PATCH` would be, and checked against the
`_version` it was listed at. The whole changeset is flushed in one
transaction. A violation's path is `changes/{index}/{field}`, and a
stale record answers `409` for all of them. Only simple, editable
fields qualify: text, email, url, slug, color, number, money, boolean,
enum, date, datetime and `belongsTo`. Anything else fails the build.

### Computed fields

A computed field shows a value a method of the resource computes from
the record, on the list and the record page:

```php
Computed::make('lineTotal', 'Total')->as('money')->currencyField('order.currency')
    ->from(['quantity', 'unitPriceCents'])->method('lineTotal'),

/** @param array<string, mixed> $line the serialized record */
public function lineTotal(array $line): ?int
{
    return $line['quantity'] * $line['unitPriceCents'];
}
```

`as()` is how the UI shows it: `text`, `number` (`decimals()`), `money`
(`currency()` or `currencyField()`), `boolean`, `date`, `datetime`, or
`enum` with `options()`. `from()` names the fields the method reads:
every list and record that shows the computed value carries them too,
whatever the `columns` asked for. `method()` names a public instance
method of the resource, built by the request container, called once per
record with the record already serialized; it runs no query of its own,
so a list's statement count does not change. The value is typed by
`as()` and exported like any field.

A computed field is read-only, on the list and the record page only,
and never sorted, filtered, searched, summarized, written or imported;
the build refuses one that asks, one without a method, one whose method
is not a public instance method, an enum without options, and a source
that is not a field holding a value. Hiding one of its sources from a
user (`viewField()`) hides the computed field too, so a derived value
never shows what the user may not see. The descriptor publishes
`computed: {as, from}`; the method's name stays on the server.

### Rich text

`RichText` is the long-form field: a Tiptap (ProseMirror) editor in the
admin, **stored as the editor's JSON document** — a tree, not markup —
in a string property on a `TEXT` or `JSON` column. The server validates
every write against a fixed schema, so sanitizing becomes schema
validation:

- the value is valid JSON with a `{"type": "doc", "content": [...]}`
  root;
- every node and mark type is one of the field's `features()`, and every
  attribute is one that type defines;
- a link goes to `http(s):`, `mailto:` or a relative place;
- the document is at most `maxBytes()` (256 KiB by default), 32 levels
  deep and 20 000 nodes.

A refusal is a 422 on the field, with the path into the document
(`description/content/2/marks/0/attrs/href`). An empty document is no
value, so `required()` works.

```php
RichText::make('description')
    ->features(['bold', 'italic', 'heading', 'list', 'link', 'table'])  // default: all but image and mention
    ->imageField('imageKey')        // images upload through this file/image field and store its key
    ->mentions('assignee')          // @ offers the records of this relation
    ->textField('descriptionText'), // search reads the plain text the writer fills here
```

The features are `bold`, `italic`, `underline`, `strike`, `code`,
`heading`, `list`, `blockquote`, `codeBlock`, `horizontalRule`, `link`,
`table`, `image` and `mention`. An image stores a **storage key, never a
URL**: it must be a key the image field's upload endpoint issued (or one
already in the stored document), and the admin fetches it with its
credentials through `GET /vector/r/{resource}/{id}/files/{imageField}?key=…`,
which serves only keys a document of that record shows. A rich-text
field is never sorted or summarized, filtered only by `null`/`nnull`,
and searched only through `textField()` — a second string property the
writer fills with `Document::toPlainText()` on every write; the build
refuses `searchable()` without one.

**On the application's own pages** `Kinetis\Vector\RichText\Document`
renders a stored value without knowing the editor:

```php
public function __construct(private Document $document) {}

$html = $this->document->toHtml($product['description'], Document::DEFAULT_FEATURES,
    static fn (string $key): ?string => "/media/{$key}");   // or null to leave an image out
```

`toHtml()` renders through [`ueberdosis/tiptap-php`](https://github.com/ueberdosis/tiptap-php)
configured with exactly the given features (never its highlighting code
blocks, which reach the filesystem or a process): what they do not cover
is dropped, and text and attributes are escaped. `toPlainText()` is the
projection CSV exports and search use. The demo's shop page,
`GET /shop/products/{id}`, renders the product description this way.

**An application node type** — a callout, an embed — is registered on
both sides: `Document::extend(new NodeSpec('callout', ['tone'], renderer: CalloutNode::class))`
on the server (bind the extended `Document` in `bootstrap.php`), and
`window.Vector.defineRichTextExtension('callout', extension)` in the
admin; a field then names `callout` in its features. The server accepts
the node only once registered, so client and server agree on the schema.

**HTML storage** is an opt-in for an existing HTML column,
`RichText::make('body')->storesHtml()`: the admin still sends the
document, and the server stores `toHtml()` of it; the admin reads the
stored HTML back through the same schema, so anything outside it is
dropped on the next save. Fidelity is only what HTML carries — prefer
the document for new columns. The demo's ticket body is one.

`Markdown` stays the field for technical text: the stored value is the
Markdown source; the admin edits it WYSIWYG through `@tiptap/markdown`
with a view of the source, and shows it through markdown-it (HTML in the
source off, bare URLs linked) and the same sanitizer. The descriptor
publishes `richtext: {features, imageField, mentions, storesHtml,
maxBytes}` and `markdown: {toolbar}`.

**In the browser** the admin renders HTML only from a document it
generated itself through the field's schema, then DOMPurify with an
allow-list matching it (no `style`, no event handlers, no `src`), and a
sanitizer that fails its canary check is not trusted — values are then
shown as text. Under the hosted shell's strict CSP the editor's own style
element carries the response nonce, table resizing (which writes
`style` attributes) is off, and images display as `data:` URLs: the
editor and the record page run without a single violation.

### Filters

A filterable field publishes the operators its value shape admits:

| Shape | Operators |
|---|---|
| string | `eq ne like nlike in nin` |
| number, money | `eq ne lt lte gt gte between in nin` |
| boolean | `eq` |
| date, datetime | `eq ne lt lte gt gte between` (a day against an instant column spans the day) |
| enum, belongsTo | `eq ne in nin` |
| id | `eq ne lt lte gt gte between in nin` |

`null` and `nnull` are added for a nullable column. Collections are
never filterable. A soft-deleting resource also takes
`filter[trashed]=only|with`.

A filter's conditions must all hold. `or` holds alternatives, each a
filter object of its own, and any one of them may hold. `and` combines
several filter objects, so `(archived or low stock) and (featured or
expensive)` is:

```
filter[and][0][or][0][status][eq]=archived&filter[and][0][or][1][stock][lte]=5
&filter[and][1][or][0][isFeatured][eq]=true&filter[and][1][or][1][priceCents][gt]=50000
```

Groups nest at most four deep and a filter holds at most 50 conditions.
`trashed` applies to the whole filter only. Groups work wherever a
filter goes:
- lists and counts
- summaries and grouping
- exports
- an "all matching" selection
- saved views
- badges
- the MCP `vector_query` tool

The UI's filter bar edits "any of" groups beside the plain conditions.
A saved view nested deeper than that shows as one advanced condition
that can be removed.

### Actions

```php
use Kinetis\Vector\Actions\{ActionRequest, ActionResponse, VectorAction};

final class AdjustStock extends VectorAction
{
    public function fields(): array
    {
        return [Number::make('delta', 'Change by')->required()];
    }

    public function canRun(CurrentUserInterface $user, ?array $record): bool
    {
        return $record === null || $record['status'] !== 'archived';
    }

    public function handle(ActionRequest $request): ActionResponse
    {
        foreach ($request->entities() as $product) {
            if (!$product->adjustStock($request->field('delta'))) {
                return ActionResponse::danger('Stock cannot go below zero.'); // nothing is flushed
            }
        }

        return ActionResponse::message("{$request->count()} adjusted.");
    }
}
```

`label()`, `icon()`, `confirmation()`, `destructive()`, `standalone()`
(runs on no records), `showOnIndex()`, `showOnDetail()` and `queued()`
(runs in a background job in chunks of `chunkSize()`) shape the action.
The runner authorizes every record through the policy and `canRun()`,
validates the dialog like a form, loads the entities, hands them to
`handle()`, flushes once, logs the run and writes the audit trail. A
response is `message`, `danger` (422, nothing flushed), `redirect`,
`openUrl`, `download` or, for a queued action, `queued`.

### Widgets and dashboards

```php
final class RevenueStat extends VectorWidget
{
    public function __construct(private readonly SqlLink $db) {}
    public function type(): string { return self::STAT; }
    public function cacheSeconds(): int { return 60; }

    public function calculate(WidgetRequest $request): WidgetResult
    {
        // one statement on the query builder for $request->from .. $request->to
        return WidgetResult::stat($current, $previous, 'money:EUR');
    }
}

#[Dashboard]
final class MainDashboard extends VectorDashboard
{
    public function widgets(): array { return [RevenueStat::class, OrdersTrend::class]; }
}
```

Types are `stat`, `trend`, `partition`, `progress` and `table`;
`ranges()` (default `7d`, `30d`, `90d`, `1y`), `width()` (3, 4, 6, 12)
and `canView()` shape the card. A `cacheSeconds()` above zero memoizes
the result in a bounded per-worker cache keyed by widget, range and the
account it was computed for; a widget whose numbers are the same for
everyone declares `cacheShared()` true so one computation serves every
admitted admin. The dashboard named `main` is the home page.

### Files and images

`File` and `Image` fields hold the storage key of an uploaded file. With
`kinetis/storage` installed and a filesystem configured, the form
uploads a picked file at once to
`POST /vector/r/{resource}/fields/{field}/upload` (multipart `file`),
which:

- detects the format from the bytes, never from the name or type the
  client sent, and refuses one the field does not `accept()`;
- refuses a file above the field's `maxBytes()`;
- stores it privately under a key it generates,
  `vector/{resource}/{field}/{random}.{ext}`, and answers that key with
  the client's filename as data.

A written value must then be such a key, issued for that field and
stored, or the value already on the record: a key cannot be pointed at
another file. `GET /vector/r/{resource}/{id}/files/{field}` serves the
stored file to whoever may view the record and the field — images
inline, everything else as an attachment — with `nosniff` and a
sandboxing policy.

```php
Image::make('photo')->maxBytes(2_000_000),                   // png, jpeg, webp, gif by default, 5 MB
File::make('contract')->accept(['application/pdf' => 'pdf']), // pdf, png, jpeg, txt, csv by default, 10 MB
```

`Image` accepts raster formats only; SVG can carry script. Files are
never deleted by Vector when a value changes. The largest upload is also
bounded by the framework's `MAX_BODY_SIZE` and the web server's body
limit. Without a filesystem, uploads are unavailable
(`capabilities.uploads: false`) and the form edits the key as text.

### Settings groups

A `#[Settings]` class extending `SettingsGroup` declares `fields()` and
`canManage()`, and may override `validate(array $values)` for a rule
across its fields — a request-time check, like `canManage()`, whose
violations refuse the save; values are stored in `vector_settings` and
edited on the UI's settings page through the same registry as a form. `Branding` and
`Languages` ship with Vector. A setting is a value, never markup.

### Languages and translated labels

The admin is shown in each user's own language. The descriptor, settings
groups, dashboards and widget results are translated at response time —
the compiled registry stays in the labels' own words — for the request's
language: the user's `locale` preference, else the administrator's
default (English unless changed) — the browser's languages play no part —
among the languages on offer: the ones the
administrator enabled in the built-in **Languages** settings group, all
eight the UI ships at first. Every list of languages — the menu, the
switches, the default's options — follows `Locales::SHIPPED`, which is
kept in the Unicode root collation of the languages' own names (Latin
script alphabetically, then each other script as a group), the same in
every language; a test fails when a language is added out of place. The schema carries
`i18n: {locale, locales: [{code, name, dir}]}`, its ETag covers the
language, and the MCP schema resource is translated for the user's
preference.

Every label a resource, field, enum, action, widget, dashboard or
settings group declares is looked up as a key and shown as declared when
there is no translation, so English labels need no file. Vector binds a
`MessagesTranslator` over its own `translations/` (its built-in settings
groups) and the application's `VECTOR_TRANSLATIONS_DIR`, each
`{locale}.php` returning `['Products' => 'Produkte', …]`; an application
binds `Kinetis\Vector\Translation\TranslatorInterface` itself to use
another translator. The UI ships German, French, Dutch, Italian, Spanish,
Portuguese (Portugal and Brazil), Polish, Turkish, Russian, Ukrainian,
Hebrew and Arabic (right to left), Korean, Japanese and Simplified
Chinese beside English; those catalogs, and
Vector's own label translations, are machine translations. Validation
messages are not translated yet: the framework's and Vector's own arrive
in English.

### Policies

`policy()` names a `ResourcePolicyInterface` (`viewAny`, `view`,
`create`, `update`, `delete`, `restore`, `forceDelete`, `export`,
`runAction`, `viewField`, `attach`, `detach`, `setDefaultView`), evaluated through
`kinetis/authorization`'s `Gate`; extend `ResourcePolicy` to override
only what you restrict. `$record` is the serialized row. `scope()` on the
resource restricts rows per user. A resource without a policy uses
`AllowAllPolicy`: every user the access policy admits may do everything.

**Field visibility.** `viewField($user, $field)` decides whether a user
sees a field at all. A hidden field leaves that user's descriptor, every
record, the audit trail, exports and MCP results, and a filter, sort,
grouping, column or written value naming it is refused like an unknown
field. Search no longer reaches it. The primary key cannot be hidden; a
hidden title field falls back to the primary key. Hiding a field the
entity requires (not nullable, no default) makes creating a record
impossible for that user, and the descriptor says so.

```php
final class CustomerPolicy extends ResourcePolicy
{
    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        return $field !== 'phone' || $user instanceof SupportLead;
    }
}
```

**Membership.** `attach($user, $record, $relation, $related)` and
`detach(...)` decide adding and removing one record in a many-to-many
relation; both follow `update` unless overridden. They decide the
`POST …/{id}/{relation}/attach` and `…/detach` endpoints the UI's
relation panels use, and an update that writes the whole list: every id
it adds is an attach, every id it drops a detach, and one refusal
refuses the update.

**Everyone's default view.** A saved view that is shared can also be
flagged `resourceDefault`. The list then opens with it for every user
who has no default view of their own. A resource has at most one such
view: flagging another view clears the first. `setDefaultView($user)`
decides who may set the flag, or save a view that keeps it. Anyone may
drop it, and the schema publishes the answer as
`permissions.setDefaultView`.

## The REST contract

Every route is under `/vector` in the `vector` middleware group; the
full table is in the root [README](../../README.md#the-admin-api) and
the contract page in [`docs/rest-contract.md`](docs/rest-contract.md).
In short: `GET /vector/schema` (the descriptor, per-identity overlay,
`ETag`), `GET /vector/r/{resource}` under the list grammar, show,
create, patch with `If-Match`, delete/restore, batch delete, restore and
update, inline changesets, actions, export,
import, audit and revert, widgets, views, preferences, settings, jobs,
notifications, impersonation, global search.

**Revert.** `POST /vector/r/{resource}/{id}/audit/{entry}/revert` puts
back what one `update` (or `revert`) entry changed. Each field in the
entry that the user may see and edit gets its `from` value. The write
goes through the update path, so the policy, validation, conditions,
dependent choices and hooks all apply. It is pinned to the record's
current version and audited as a `revert`. If any of those fields has
changed since the entry, the revert answers `409` and writes nothing,
so it never overwrites a later edit. Collections and read-only fields
are left alone. Other events (`create`, `delete`, `attach`, actions)
are refused with `not_revertable`.

Errors: validation is the framework's RFC 9457 document; every other
refusal is `{"error": "..."}` with the status the exception declares
(404 unknown resource or record, 422 invalid list request, 409 stale
version, 403 refused operation).

## Background jobs

Queued actions, exports above `exportLimit()` (up to
`exportQueueLimit()`) and every import become jobs. Each reports into
`vector_jobs`, which the UI polls, and notifies the admin who started
it; that admin is rebuilt in the worker through the application's
`ActorResolverInterface`. Every Vector job is pushed for one attempt and
claims its row with one conditional write before it starts, so a job the
backend redelivers on its own does not run again; a failure is final and
reported on the row. A queued action must still tolerate a chunk running
twice, because a chunk's flush and the progress written after it are two
statements.

### XLSX exports

`format=xlsx` on `GET /vector/r/{resource}/export` writes an Excel
workbook with typed cells: numbers and money as numbers (money in major
units, its currency in the header when the field has one, else from the
row's currency), booleans as booleans, dates and date-times as real date
cells (in UTC), and everything else as text under the same formula guard
as CSV. It needs `openspout/openspout` (MIT) and PHP's `zip` extension:

```sh
composer require openspout/openspout
```

Without them the descriptor's `capabilities.exportFormats` is `["csv"]`
and the UI offers CSV alone. **An XLSX export is always a job.** The
decision, checked against openspout 5.12's own source: its XLSX writer
builds the sheet in a temporary folder and zips it with `ZipArchive` on
a real file path, and has no memory-only path. That is blocking file I/O,
which a persistent request worker must not do; the queue worker may. So
XLSX is offered only when a queue runs Vector's jobs, the request answers
202 with the job, and the same field selection and limits apply
(`exportQueueLimit()`).

An import creates each row, or — with `mode: update` or `upsert` and
a `key` column (the primary key or a `unique()` field, mapped) —
updates the record the key finds, creating the rest in `upsert` and
sending them to the failed-rows file in `update`. One indexed lookup per
row runs through the resource's `scope()`, so a row can only update a
record its admin may list; every write goes through the writer, so the
`update` policy, rules, hooks and audit apply per row. The result is
`{created, updated, failed}`; see the [REST contract](docs/rest-contract.md#import).
Re-importing an export with `key: sku` in `upsert` mode changes the
values in place instead of duplicating rows — map the columns that
import back (a relation exports its label, a rich-text field its plain
text) and skip the rest.

Vector has no queue of its own. Two settings decide where its jobs go:

| Key | Meaning |
|---|---|
| `VECTOR_QUEUE` | The queue name Vector pushes to; `default` when unset. |
| `VECTOR_QUEUE_CONNECTION` | A named queue connection for Vector's jobs. Unset, Vector pushes to the application's own `QueueInterface`. |

A named connection is built by `kinetis/queue`'s own `QueueFactory`:
its backend is `QUEUE_<NAME>_CONNECTION` (`redis`, `sql`, `sqs` or
`rabbitmq`, with that backend's package installed), and the backend
reads its own keys under the same name (`REDIS_<NAME>_*`, `DB_<NAME>_*`,
…). Vector builds it on the first push, once per worker, and closes it
with the application scope; the application's own queues are untouched.
A backend the queue package cannot build refuses the push with a 403
carrying the queue package's reason. A worker serves the connection by
name:

```sh
vendor/bin/kinetis queue:work --connection=admin --queue=vector
```

Whichever you choose, some worker must watch `VECTOR_QUEUE` on that
backend; otherwise jobs wait as `queued`. Without a queue, or without
an `ActorResolverInterface`, queued actions and imports answer 403
naming what is missing, large exports are refused rather than
truncated, and the descriptor reports `capabilities.queue: false`, so
the UI hides the Jobs page and the import button.

## MCP tools

`Kinetis\Vector\Mcp\VectorTools` registers `vector_resources`,
`vector_query`, `vector_get`, `vector_create`, `vector_update`,
`vector_delete`, `vector_run_action` and `vector_widget`, and the
`vector://schema` resource. They call the same services and policies as
the routes, inject `CurrentUserInterface` (so `kinetis mcp:serve` fails
closed without an identity) and ask `VectorAccessInterface` as the
`vector` group would. Open-shaped arguments are plain JSON objects:
`values` on `vector_create`, `vector_update` and `vector_run_action`,
and `filter` on `vector_query`. Over HTTP the application's
authentication middleware joins the `mcp` group.

## Authentication and authorization

Authentication is the application's. Its middleware in the `vector`
group registers `CurrentUserInterface`; `VectorAccessMiddleware`, the
group's innermost member, then asks the application-bound
`VectorAccessInterface`. A user object may also implement
`VectorShellUserInterface` (a display name for the hosted shell) and
`ImpersonatedUserInterface` (who is impersonating it, written to every
log and audit row). Both deployment modes of the UI are first-class:
bearer tokens on another origin, or the application's session cookie
with `X-CSRF-Token` on writes under the hosted shell — see
[`docs/deployment-modes.md`](docs/deployment-modes.md).

## Configuration keys

| Key | Meaning |
|---|---|
| `VECTOR_UI_DIR` | Where the published bundle lives (default `public/vector/ui`). |
| `VECTOR_LOGIN_URL`, `VECTOR_LOGOUT_URL` | The application's pages the hosted shell redirects to and posts to. |
| `VECTOR_SHELL_HEAD` | A raw fragment appended before `</head>` of the shell; `{nonce}` is replaced. Deployment configuration only. |
| `VECTOR_SHELL_CSP` | A `Content-Security-Policy` for the shell alone, sent instead of the application's `SECURITY_CSP`, `{nonce}` replaced per response. Unset, the application's policy applies, and the shell uses the nonce it put on the request. |
| `VECTOR_UI_EXTENSIONS` | Comma-separated ES-module URLs the UI imports after boot. |
| `VECTOR_TRANSLATIONS_DIR` | A directory of `{locale}.php` files translating the application's labels, relative to the project root. Read once at boot. |
| `VECTOR_CURRENCY` | The ISO 4217 code a `Currency` field starts from when it sets no `default()` of its own (and allows the code). An invalid code stops the boot. |
| `VECTOR_EXPORT_FORMATS` | The export formats offered, `csv,xlsx` by default. CSV is always offered; XLSX only where `openspout/openspout` and `ext-zip` are installed and a queue runs Vector's jobs. An unknown format stops the boot. |
| `VECTOR_DB_CONNECTION` | The connection Vector's own tables live on (`default` when unset). |
| `VECTOR_QUEUE` | The queue name Vector's jobs are pushed to (`default` when unset). |
| `VECTOR_QUEUE_CONNECTION` | A named queue connection for Vector's jobs; its backend is `QUEUE_<NAME>_CONNECTION`. |
| `VECTOR_STORAGE_CONNECTION` | A named storage connection (`FILESYSTEM_<NAME>_*`) for uploaded files. Unset, Vector uses the application's `FilesystemOperator` (`FILESYSTEM_DRIVER`). |

## Testing an admin

`Kinetis\Vector\Testing` drives the REST contract from the
application's own suite, through the framework's `TestClient`, so the
application's middleware, authentication and policies all run. A test
case extending `ApplicationTestCase` uses `InteractsWithVector`:

```php
use Kinetis\Vector\Testing\InteractsWithVector;

final class CatalogAdminTest extends ApplicationTestCase
{
    use InteractsWithVector;

    public function testEditorsCannotSeeCosts(): void
    {
        $products = $this->vector(['Authorization' => "Bearer {$this->editorToken()}"])->resource('products');

        $products->list(filter: ['status' => ['eq' => 'active']])->assertOk()->assertMissingField('costCents');
        $id = $products->create(['sku' => 'A-1', 'name' => 'Lamp'])->assertCreated()->id();
        $products->update($id, ['sku' => ''])->assertInvalid('sku', 'required');
        $products->action('reprice', [$id], ['percent' => 5])->assertQueued();
    }
}
```

`resource($slug)` has one method per endpoint (`list`, `show`,
`create`, `update`, `delete`, `restore`, `batch`, `changes`, `action`, `related`,
`attach`, `detach`, `options`, `audit`, `revert`, `export`, `upload`, `file`);
`schema()`, `me()`, `search()` and `job()` are on the client. Every call
answers a `VectorResponse` with chaining assertions (`assertRecord`,
`assertTotal`, `assertIds`, `assertListed`, `assertEveryRecord`,
`assertInvalid`, `assertForbidden`, `assertPermission`, `assertHasField`,
`assertQueued`, …); `raw()` is the framework's `TestResponse`.

## Lifecycle guarantees and limits

- The descriptor is compiled once (build, or first boot in development)
  and is immutable; request-time services hold it and the request's
  user only.
- Lists run the same bounded plan: scope → soft-delete predicate →
  filters → search → order terminated by the key → one page → one
  statement per relation field → one aggregate for summaries.
- `perPage` ≤ `maxPerPage()` (200), a bulk selection ≤ `bulkLimit()`
  (1000), an inline export ≤ `exportLimit()` (10 000), a queued export ≤
  `exportQueueLimit()` (250 000), an import ≤ 50 000 rows and 8 MB, a
  saved view or preference value ≤ 16 KiB, a job file ≤ 12 MiB (16 MiB
  as base64).
- Timestamps and soft deletes are resource conventions the writer
  applies; the ORM's optimistic lock and unique indexes map to 409 and
  422.
- Search is a case-insensitive `LIKE` (`ILIKE` on PostgreSQL) over the
  resource's own searchable columns; relation search and full-text
  search are not implemented.
- Vector's writes to its own tables (action log, audit, jobs) run on
  Vector's connection; when that is not the entities' connection, they
  commit separately from the entity flush.
