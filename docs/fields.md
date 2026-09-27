# Fields

A field maps one entity property to a column in lists, a value on the
record page and an input in forms. `Text::make('sku', 'SKU')` names the
property and, optionally, the label; without one the label is derived
from the name.

## Catalogue

| Class | Holds |
|---|---|
| `Id` | The identifier. Read-only, sortable, never on a form. |
| `Text`, `Email`, `Url`, `Password`, `Slug`, `Color` | Strings. `Slug::from('name')` derives a slug. |
| `Textarea`, `Json` | Long text, edited in wide inputs. |
| `RichText` | Formatted content — headings, lists, links, tables, images — stored as a structured document; see below. |
| `Markdown` | Markdown text for technical content, edited visually or as source; `toolbar([...])`. |
| `Number` | Numbers; `decimals(n)`, `step()`. |
| `Money` | An amount in the currency's minor units (cents, whole yen). The currency is fixed with `currency('EUR')`, read from the record with `currencyField('currency')`, or from a related record with `currencyField('order.currency')`. |
| `Currency` | An ISO 4217 code, chosen from a searchable list; `allowed(['EUR', 'USD'])`. |
| `Boolean` | True or false. |
| `Date`, `DateTime` | A calendar day, or an instant (UTC on the wire). |
| `Enum` | A backed enum: `of(Status::class)`, `labels()`, `colors()`. |
| `KeyValue` | A JSON object of text values, edited as key/value rows; `columns('Attribute', 'Value')`, `maxEntries(n)`. |
| `File`, `Image` | An uploaded file's storage key; see {doc}`files`. |
| `Computed` | A read-only value a method of the resource computes from the record; see below. |
| `BelongsTo`, `HasOne`, `HasMany`, `ManyToMany` | Relations to another resource, made with `::to('name', TargetResource::class)`. |

## Common modifiers

Every field takes:

| Modifier | Effect |
|---|---|
| `label()`, `help()` | Its label, and a hint under the input. |
| `required()`, `min()`, `max()`, `rule(Constraint::class, ...)` | Validation, checked by the server and mirrored by the form. Any framework constraint may be used. |
| `default()` | The value a new record starts with; a create that leaves the field out takes it. |
| `readonly()` | Shown, never written. |
| `sortable()`, `searchable()`, `filterable()` | Allowed in the list grammar. |
| `hideFromIndex()`, `hideFromShow()`, `hideFromForms()`, `onlyOn*()` | Where the field appears. |
| `summarize('sum'\|'avg'\|'min'\|'max'\|'count')` | A totals row under the list. |
| `inlineEditable()` | Editable in its list cell; see {doc}`forms`. |
| `unique()` | The value is unique (as its column's index enforces): a duplicated record starts without it, and an import can match rows by it. |
| `component('name')` | Rendered by a custom cell or input; see {doc}`customizing-the-ui`. |
| `props([...])` | Extra settings passed to the UI. |

Conditions (`visibleWhen()`, `requiredWhen()`, `disabledWhen()`),
dependent choices and repeaters are on {doc}`forms`.

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

`RichText` gives authors a word-processor-like editor and stores what
they write as a **structured document**, not HTML. The server checks
every save against the field's schema, so nothing unsafe can be stored:

```php
RichText::make('description')
    ->features(['bold', 'italic', 'heading', 'list', 'link', 'table', 'image'])
    ->imageField('imageKey')    // images upload through this field
    ->mentions('assignee'),     // @ picks one of these records
```

- **Features** are what the editor offers and the server accepts:
  `bold`, `italic`, `underline`, `strike`, `code`, `heading`, `list`,
  `blockquote`, `codeBlock`, `horizontalRule`, `link`, `table`, `image`,
  `mention` — all but images and mentions by default.
- **Links** go only to web addresses, e-mail addresses or places in the
  site.
- **Images** are uploads, stored privately and shown only to people who
  may see the record.
- **Search** needs `textField('bodyText')`: a second text property Vector
  fills with the plain text.
- On your own pages, `Document::toHtml()` renders a value as safe HTML;
  exports carry its plain text.
- `storesHtml()` keeps an existing HTML column, at the cost of fidelity.

`Markdown` remains for technical text: authors edit visually or switch
to the source, and the page shows it formatted and sanitized.

## Computed fields

A computed field shows a value your resource calculates, such as a line
total or a full name:

```php
Computed::make('lineTotal', 'Total')->as('money')->currencyField('order.currency')
    ->from(['quantity', 'unitPriceCents'])->method('lineTotal'),

public function lineTotal(array $line): ?int
{
    return $line['quantity'] * $line['unitPriceCents'];
}
```

- `as()` — how it is shown: `text`, `number`, `money`, `boolean`,
  `date`, `datetime`, or `enum` with `options()`.
- `from()` — the fields the method reads; they always come with it.
- `method()` — a public method of the resource, given the record.

It appears on lists, record pages and exports, costs no extra query,
and is never sorted, filtered, searched, summarized or edited. If a user
may not see a field it reads, they do not see the computed field either.

## Relations

- `BelongsTo` shows the target's title and edits with a searchable
  picker. `titleField()` overrides which field names the target.
- `HasMany` and `ManyToMany` show a count in lists and a panel on the
  record page. A many-to-many is edited in the form, or attached and
  detached from the panel.
- A relation never crosses databases; a value pointing at another
  database is an ordinary field. See {doc}`databases`.

## Money and currencies

Money is stored in minor units, following ISO 4217: hundredths for most
currencies, whole units for JPY, thousandths for BHD. The schema
publishes the exceptions, so the UI formats and parses each amount
correctly. `VECTOR_CURRENCY` sets the starting code of a `Currency`
field that declares no `default()`.

## See also

- {doc}`forms` — conditions, dependent choices, repeaters, inline edits.
- {doc}`files` — uploads.
- {doc}`authorization` — hiding fields per user.
