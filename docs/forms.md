# Forms

Create and edit forms are generated from the fields. The server
validates every write; the form mirrors its rules so most mistakes are
caught before a request, and places every server violation on the field
it names.

## Layout

Group a long form into sections and tabs by returning them among the
fields:

```php
use Kinetis\Vector\Fields\Layout\{Section, Tabs};

public function fields(): array
{
    return [
        Id::make(),
        Section::make('Details')->fields(['sku', 'name', 'status']),
        Section::make('Pricing')->description('In the product\'s currency.')->collapsible()
            ->fields(['priceCents', 'currency']),
        Tabs::make()
            ->tab('Content', ['description', 'imageKey'])
            ->tab('Publishing', ['isFeatured', 'releasedAt']),
        Text::make('sku')->required(),
        // … every field, in its own order
    ];
}
```

- A section or tab holds fields, or the **names** of fields declared
  elsewhere in `fields()`. A tab may hold sections; a section holds only
  fields. A field left at the top level keeps its place.
- `columns(1..4)` sets a section's grid (two by default);
  `->columnSpan(1|2|'full')` on a field sets its width, or the UI picks
  one by its type. A field declaring `columnSpan()` gives the resource a
  layout even without sections.
- `collapsible()` lets a section fold; `collapsed()` starts it folded.
- The show page uses the same layout, with the fields it shows.

Layout only groups: validation, serialization, filters and writes are
unchanged. The compiler flattens it to the same field list and
publishes it apart, as the descriptor's `layout` ({doc}`rest-api`). A
field a user may not see leaves the layout, and a section or tab it
empties disappears. A name the resource does not declare, or one placed
twice, fails the build. When a save is refused, the form opens the
section and switches to the tab holding the field in error, and marks
the tab.

## Conditions

A field can depend on another field of the same form — a resource's, an
action dialog's or a settings group's:

```php
DateTime::make('releasedAt')
    ->visibleWhen('status', 'ne', 'draft')
    ->requiredWhen('status', 'eq', 'active'),
Boolean::make('isFeatured')->disabledWhen('status', 'eq', 'archived'),
```

The operators are `eq`, `ne`, `in`, `nin` (a list), `null` and `nnull`.
Repeated calls of one kind must all hold. A relation compares by its id.

The form re-evaluates conditions as values change, and the server
evaluates them again on the stored record overlaid with the request. A
hidden or disabled field's submitted value is ignored; a field whose
`requiredWhen` holds must end up non-empty.

A condition on a field the form does not have, or on the field itself,
fails the build. A collection can only be tested with `null` or
`nnull`.

## Relation choices

A relation input searches the target's title. When the target declares
`searchSubtitle()` or `searchImage()` (see
[Resources](resources.md#global-search)), each choice shows that value
under its title and the image as a thumbnail — the same as global
search — so records with the same title can be told apart.

## Dependent choices

A belongs-to can offer only the records that match another field:

```php
BelongsTo::to('team', TeamResource::class),
BelongsTo::to('assignee', AgentResource::class)->dependsOn('team'),
```

The assignee input waits until a team is chosen and clears itself when
the team changes. Use `dependsOn('team', on: 'squad')` when the target's
property is named differently.

The options endpoint narrows by `depends[team]=…`; without that
parameter it searches every record, as a list filter does. A write that
changes the choice or the team must keep the choice among the agents the
options would offer; otherwise the choice is refused with
`invalid_choice`. Unchanged values are not re-checked, so records stored
before the rule still save. A dependency on a field the form lacks, or
on a property the target does not map, fails the build.

## Repeaters

A `HasMany` the parent owns can be edited inside the parent's form:

```php
HasMany::to('lines', OrderLineResource::class)
    ->repeater(['product', 'quantity', 'unitPriceCents'], min: 1, max: 50),
```

The whole list is sent with the parent and written in one transaction:

```json
{"lines": [{"id": 7, "quantity": 3}, {"product": 4, "quantity": 1, "unitPriceCents": 1200}]}
```

- a row with an `id` updates that child;
- a row without one creates a child pointing at the parent;
- a child left out is deleted.

Each row is validated as the child resource validates a record, and a
violation names its row (`lines/1/quantity`). The child's policy
authorizes each create, update and delete; its hooks run and its audit
trail records each change, as if the child had been written on its own.
The parent's hooks run after the rows are in place, so a total can be
recomputed there. `GET /vector/r/{resource}/{id}/{relation}/rows`
returns the current rows for an edit form.

Compilation refuses a repeater that is over a relation that is not
owned, that leaves out a child field a new row requires, whose child
resource soft-deletes, or that carries conditions. Repeaters are not
written in bulk, inline or by import.

## Inline editing

`inlineEditable()` lets the list edit a field in its cell. Edits across
rows are saved together as one changeset with
`POST /vector/r/{resource}/changes`, flushed in one transaction. Each
record is authorized, validated and hooked as a `PATCH` would be, and
checked against the `_version` it was listed at: one stale record
answers `409` for the whole changeset. A violation's path is
`changes/{index}/{field}`. Only simple fields editable on update
qualify: text, email, url, slug, color, number, money, boolean, enum,
currency, date, datetime and belongs-to. Anything else fails the
build.

## Optimistic locking

When the entity has a `#[Version]` column, every update carries the
version it was read at. A record changed in the meantime answers `409`
instead of losing the other change; the form says so.

## See also

- {doc}`fields` — every field and modifier.
- {doc}`records` — the forms from a user's side.
