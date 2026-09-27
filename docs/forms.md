# Forms

Create and edit forms are generated from the fields. The server
validates every write; the form mirrors its rules so most mistakes are
caught before a request, and places every server violation on the field
it names. Four features make forms depend on their own values.

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
  `->columnSpan(1|2|'full')` on a field sets its width.
- `collapsible()` lets a section fold; `collapsed()` starts it folded.
- The show page uses the same layout, with the fields it shows.

Layout only groups: validation, lists and writes are unchanged. A field
a user may not see leaves the layout, and a section or tab it empties
disappears. When a save is refused, the form opens the section and
switches to the tab holding the field in error, and marks the tab.

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

## Dependent choices

A belongs-to can offer only the records that match another field:

```php
BelongsTo::to('team', TeamResource::class),
BelongsTo::to('assignee', AgentResource::class)->dependsOn('team'),
```

The assignee input waits until a team is chosen and clears itself when
the team changes. The server refuses a choice outside the team. Use
`dependsOn('team', on: 'squad')` when the target's property is named
differently.

## Repeaters

A `HasMany` the parent owns can be edited inside the parent's form:

```php
HasMany::to('lines', OrderLineResource::class)
    ->repeater(['product', 'quantity', 'unitPriceCents'], min: 1, max: 50),
```

The whole list is sent with the parent and written in one transaction:
a row with an `id` updates that child, a row without one creates it, and
a child left out is deleted. Each row is validated, authorized, hooked
and audited as the child resource would be; a violation names its row.
The parent's hooks run after the rows are in place, so a total can be
recomputed there.

A repeater must be over an owned relation and include every field a new
row requires; the child may not soft-delete. Repeaters are not written
in bulk, inline or by import.

## Inline editing

`inlineEditable()` lets the list edit a field in its cell. Edits across
rows are saved together as one changeset, in one transaction. Each
record is authorized, validated and hooked as a normal update, and
checked against the version it was listed at: one stale record refuses
the whole changeset. Text, number, money, boolean, enum, date,
datetime and belongs-to fields qualify.

## Optimistic locking

When the entity has a `#[Version]` column, every update carries the
version it was read at. A record changed in the meantime answers `409`
instead of losing the other change; the form says so.

## See also

- {doc}`fields` — every field and modifier.
- {doc}`records` — the forms from a user's side.
