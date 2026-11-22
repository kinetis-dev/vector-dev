# MCP tools

Vector exposes the admin to AI agents over the Model Context Protocol,
through `kinetis/mcp`. The tools call the same services and policies as
the REST API: an agent sees what its identity may see, and every write
is validated, hooked and audited.

| Tool | Arguments | Does |
|---|---|---|
| `vector_resources` | none | Lists the resources this identity may view: slug, label and group. |
| `vector_describe` | `resource` | Describes one resource's fields, actions and permissions (below). |
| `vector_query` | `resource`, `filter`, `q`, `sort`, `page`, `perPage`, `fields` | Lists records under the list grammar. `perPage` defaults to 25 and is capped at 200 and at the resource's own maximum. A page carries `total` and `lastPage`; for a resource described as `withoutCount`, both are `null` and `hasMore` says whether another page follows. `fields` (a list of field names) makes each row carry only those fields, its key, and `_version` and `_trashed` where the list carries them; a field the list does not publish is refused. Without `fields` a row is the list's own. |
| `vector_aggregate` | `resource`, `metrics`, `groupBy`, `filter`, `q`, `sort`, `limit` | Counts, totals, averages, minimums and maximums over every record `filter` and `q` match, grouped by up to three keys ({ref}`mcp-aggregates`). |
| `vector_get` | `resource`, `id` | Reads one record, with its permissions. |
| `vector_create` | `resource`, `values` | Creates a record, validated as the form validates it. |
| `vector_update` | `resource`, `id`, `values`, `version` | Updates the fields in `values`. Pass the `version` you read to refuse a stale write. |
| `vector_delete` | `resource`, `id`, `force` | Deletes a record; on a soft-deleting resource it goes to the trash unless `force` is true. |
| `vector_run_action` | `resource`, `action`, `ids`, `values` | Runs an action on record `ids` (none for a standalone action) with its dialog `values`. A download comes back base64-encoded, with its filename and content type. |
| `vector_widget` | `widget`, `dashboard` or `resource`, `range` | Computes a dashboard widget (`dashboard` defaults to `main`) or a resource widget, for a range such as `30d`. |

An agent lists the resources, then describes the one it works with, so
its context holds one resource's detail rather than all of them. For
each field, `vector_describe` (`Kinetis\Vector\Mcp\ResourceDescription`)
states:

- **`writable`**: whether a create, an update, both or neither may write it.
- **`required`**: whether a create must send it. It follows the writer's
  own rule, so a create with just the required fields succeeds.
- **`rules`, `options`, `default`, `unique`**: what a value must satisfy.
- **`format`**: what a value looks like, where the type alone doesn't
  say (a date, a rich-text document, a list of ids).
- **`relation`**: the resource it points to.
- **`filter`, `sortable`, `searchable`**: what `vector_query` accepts for it.
- **`visibleWhen`, `requiredWhen`, `disabledWhen`**: when these depend on
  other fields' values.

The description also carries each action with its dialog fields, and
what this identity may do. `withoutCount: true` marks a resource whose
`vector_query` pages are not counted (see {doc}`rest-api`); the key is
absent otherwise. It is in the identity's language, and a field
a policy hides is left out. Files and images can't be uploaded through
the tools.

The resource `vector://schema` is the whole descriptor, as this identity
sees it.

`values` (on create, update and run-action) is a JSON object of field
values, as a REST write takes them. `filter` (on query) is the list
grammar's filter as a JSON object: `{field: value | {op: value}, or:
[filter, ...], and: [filter, ...]}`.

(mcp-aggregates)=
## Aggregates

`vector_aggregate` computes in the database what an agent would
otherwise add up from pages of `vector_query`. It reads the rows the
same identity's list would show: the resource's `scope()`, the trash
left out, and `filter` and `q` read by the list's own grammar
(`ListRequest`), under `viewAny`.

- **`metrics`**: 1 to 6 of `{fn, field?}`. `fn` is `count` (no field),
  `sum` or `avg` (a number or money field), or `min` or `max` (a number,
  money, date or datetime field). A metric's key is `count` or
  `<fn>_<field>`, such as `sum_totalCents`.
- **`groupBy`**: 0 to 3 of `{field, bucket?}`, none for one overall row.
  A choice, currency, boolean, text or number field groups by its value;
  a belongsTo groups by its key, written `{id, label}` with the related
  record's title; a date or datetime needs `bucket`: `day`, `week`
  (from Monday), `month`, `quarter` or `year`; its key is the
  bucket's first day, `YYYY-MM-DD`. Buckets fall in UTC, the timezone
  Vector stores and filters instants in: like the date filters, they
  assume a timestamp column holds naive UTC values. A PostgreSQL
  `timestamptz` column is bucketed in the session's timezone instead.
- **`sort`**: one metric key or group field, `-` first for descending;
  by default the first metric, descending. Ties are ordered by the
  group keys.
- **`limit`**: the groups returned, 1 to 100 (25 by default).

A field is eligible when the identity's descriptor publishes it (what
`vector_describe` lists, so a field a policy hides is unknown) and the
resource's list or record view shows it, the rule a list applies to the
columns it may serialize: a form-only field is refused, and so is a money
metric whose currency field no view shows. It must also map to a column
of a type the use takes. A computed, rich-text,
key-value or collection field is refused, as it is for a sort or a
summary, and so is a bucket on a field that is no date, or a date
without one. Each refusal is a tool error naming what to change.

```json
{
  "groups": [
    {"key": {"customer": {"id": 73, "label": "Linus Turing"}, "currency": "EUR"},
     "metrics": {"sum_totalCents": 4495417, "count": 8}}
  ],
  "groupCount": 80,
  "truncated": true,
  "timezone": "UTC",
  "money": {"sum_totalCents": {"unit": "minor", "currencyGroup": "currency"}}
}
```

`groupCount` counts every group, and `truncated` says whether more than
`limit` were found. Money is in minor units, as a list and its summaries
write it. A money field priced per record in a currency field
(`currencyField('currency')`) is grouped by that currency too, so no two
currencies are added: `money` names that group key
(`currencyGroup`). A money field of a fixed currency (`currency('EUR')`)
says so (`"currency": "EUR"`). A money field priced in a related
record's currency (`currencyField('order.currency')`) can be counted,
but not summed, averaged or compared: that would need a join.

## Serving them

- **Over HTTP**, at `POST /mcp`: your authentication middleware joins the
  `mcp` group, as it joins `vector` ({doc}`authentication`).
- **Over stdio**, with `kinetis mcp:serve`: the tools inject
  `CurrentUserInterface`, so without an authenticated identity they fail
  closed. The HTTP `mcp` group does not run over stdio.

Either way, the tools ask `VectorAccessInterface` whether the identity
may use the admin at all, as the `vector` group does for the UI.

## When `POST /mcp` answers 401

If every caller of `POST /mcp` gets `401 Unauthenticated.` while the same
token works on `/vector/…`, your authentication middleware is in the
`vector` group but not the `mcp` group. Add it there, at a priority
above the identity guard's, as
[the `mcp` middleware group](authentication.md#the-mcp-middleware-group)
shows, and rebuild the compiled cache in production
({doc}`deployment`).

```{warning}
Do not set `MCP_HTTP_PUBLIC=true` to make the 401 go away. It lets
requests without an identity through to every tool: any tool of yours
that needs no identity becomes public, and Vector's tools still have no
identity to run as.
```

## See also

- {doc}`authorization`: what an identity may do.
- {doc}`rest-api`: the list grammar the tools share.
