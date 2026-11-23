# Data and access

Who may use the AI features, and what any of them may send a model.

(ai-access)=
## The access policy

`Kinetis\VectorAi\Auth\AiAccessInterface` decides who may use each
feature, `canUse($user, Feature $feature)`, and who may manage the AI
settings and keys, `canConfigure($user)`. The default admits everyone
the Vector access policy admits. Bind your own on AppScope, as you bind
`VectorAccessInterface`, and keep request state out of it:

```php
$app->instance(AiAccessInterface::class, new MyAiAccess());
```

Every AI route is in the `vector` middleware group.

(ai-boundary)=
## Data sent to the model

`Kinetis\VectorAi\Governance\ModelBoundary` decides what any feature may
send a model:

- **Never sent:** password, file and image fields, and every field the
  user's `viewField` policy hides from them.
- **Sent by default:** everything else, unless a resource or field opts
  out through the metadata bag ({ref}`resource-meta`).
- **Allow-list:** with *Record data sent to the model* set to only
  fields marked to send, only a resource that opts in with
  `meta: ['ai.send' => true]`, and within it only fields that opt in
  with `meta('ai.send', true)`.

```php
#[Resource(entity: Customer::class, meta: ['ai.send' => false])]   // nothing of this resource
Email::make('email')->meta('ai.send', false),                     // nor this field
```

Vector publishes the bag and never reads it; `kinetis/vector-ai` does. A
resource kept from the model whole offers no AI at all: its
plain-language filter answers `403`, and the admin hides it.

### The boundary for each tool

Every tool answer passes the boundary (`record()`, `page()`,
`toolResult()`) before the model sees it; writing help and bulk rewrites
check the field before sending it ({ref}`ai-writing-checks`).

**A record** keeps the allowed fields, plus its primary key, which names
it (`products#12`), its `_trashed` marker, and its `_version`, the
optimistic lock a proposed `vector_update` passes back so an approval
after someone else's change is refused. A relation (`{id, label, …}`)
or one-to-one value (`{id, label}`) keeps its id; its label (the
target's title field) only when the target and its title field may be
sent (or the title is its primary key); and each carried column (an
order's currency, say) only when that field of the target may be sent.
A to-many relation is sent as its count. A string over 2,000 characters
is cut and marked `… [truncated]`.

**A page** is at most 25 records plus its position (`currentPage`,
`total`, …), never its summaries, which could total a kept field. A
query projecting `fields` may carry at most min(200, ⌊1000 / the number
of fields⌋) narrower rows.

**A description** (`vector_describe`) is cut like a record, an excluded
field left out even as the title or a searched field. It offers search
only where `q` is allowed, gives as the default sort the order a query
naming none gets, and leaves out actions, rules, defaults and
conditions, which only a write needs.

**A query** (`vector_query`) may filter and sort, in `or` and `and`
groups too, only on fields a record keeps. It may search with `q` only
where every field the search matches may be sent (each searched column,
the full-text index's columns, each searched relation with its target's
title), since which records match, and how they rank, would reveal a
kept field. A query that breaks this, or a projection (`fields`) naming
a kept field, is refused before it runs, without naming the field. With
no sort, it is ordered by the resource's default sort without excluded
fields, or by its primary key. In the demo, users search and sort by
their email, which opts out, so the assistant may not search users, nor
sort or filter them by email.

**An aggregate** (`vector_aggregate`) is refused the same way when its
`groupBy`, a metric, or its `filter`, `sort` or `q` touches a kept
field, or a money metric's currency field is kept, since that currency
becomes a group key. Its answer is sent with strings cut; a belongsTo
group key keeps its id, and its label only when the related resource's
title field may be sent. An answer naming a field the boundary would
not send is refused whole.

**A widget** (`vector_widget`) is sent with strings cut. It is refused
when it is a resource widget of a kept resource, its `link` leads to one
(`/r/{slug}…`), or it is a table whose rows link to records of one. A
table also loses each column whose key is a field the boundary would not
send of the resource its row links to (the row's `_link`, else the
table's `link`): the demo's low-stock table loses its SKU column under
the allow-list, since products' SKU does not opt in. A key that is no
field of that resource, or a widget showing an excluded field in its own
words, cannot be cut: opt the resource out, or do not write such a
widget.

**Any other tool's** answer is refused unless the boundary lists the
tool as carrying no record data. One is listed: `vector_resources`,
which lists slugs and labels only.
