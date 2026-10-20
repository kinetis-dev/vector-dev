# MCP tools

Vector exposes the admin to AI agents over the Model Context Protocol,
through `kinetis/mcp`. The tools call the same services and policies as
the REST API: an agent sees what its identity may see, and every write
is validated, hooked and audited.

| Tool | Arguments | Does |
|---|---|---|
| `vector_resources` | — | Lists the resources this identity may view: slug, label and group. |
| `vector_describe` | `resource` | Describes one resource's fields, actions and permissions (below). |
| `vector_query` | `resource`, `filter`, `q`, `sort`, `page`, `perPage` | Lists records under the list grammar. `perPage` defaults to 25 and is capped at 200 and at the resource's own maximum. A page carries `total` and `lastPage`; for a resource described as `withoutCount`, both are `null` and `hasMore` says whether another page follows. |
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

- {doc}`authorization` — what an identity may do.
- {doc}`rest-api` — the list grammar the tools share.
