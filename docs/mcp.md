# MCP tools

Vector exposes the admin to AI agents over the Model Context Protocol,
through `kinetis/mcp`. The tools call the same services and policies as
the REST API: an agent sees what its identity may see, and every write
is validated, hooked and audited.

| Tool | Does |
|---|---|
| `vector_resources` | Lists the resources this identity may use: slug, label and group — an index to pick from. |
| `vector_describe` | Describes one resource for reading and writing its records (below). |
| `vector_query` | Lists records under the list grammar: `filter`, `q`, `sort`, `page`, `perPage` (at most 200, and the resource's own maximum). |
| `vector_get` | Reads one record, with its permissions. |
| `vector_create`, `vector_update`, `vector_delete` | Writes. `vector_update` takes the `version` it read to refuse a stale write; `vector_delete` trashes on a soft-deleting resource unless `force` is true. |
| `vector_run_action` | Runs an action on record `ids` (none for a standalone action) with its dialog `values`. |
| `vector_widget` | Computes a dashboard widget (`dashboard` and `widget`) or a resource widget (`resource` and `widget`) for a range such as `30d`. |

An agent lists the resources, then describes the one it works with, so
its context holds one resource's detail rather than all of them. For
each field, `vector_describe` (`Kinetis\Vector\Mcp\ResourceDescription`)
states:

- **`writable`**: whether a create, an update, both or neither may write it.
- **`required`**: whether a create must send it. This is the writer's own
  rule (`RecordInput::requiredOnCreate()`), so a create with just the
  required fields succeeds.
- **`rules`, `options`, `default`, `unique`**: what a value must satisfy.
- **`format`**: what a value looks like, where the type alone doesn't
  say (a date, a rich-text document, a list of ids).
- **`relation`**: the resource it points to.
- **`filter`, `sortable`, `searchable`**: what `vector_query` accepts for it.
- **`visibleWhen`, `requiredWhen`, `disabledWhen`**: when these depend on
  other fields' values.

The description also carries each action with its dialog fields, and
what this identity may do. It is in the identity's language, and a field
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

## `POST /mcp` answers 401 to everyone

In bearer-token mode, when your authentication middleware is in the
`vector` group only, `POST /mcp` answers `401 Unauthenticated.` to every
caller, admins with a valid token included, while the same token works
on `/vector/…`.

`kinetis/mcp` closes the endpoint by default: its
`Kinetis\Mcp\Http\McpIdentityGuardMiddleware`, the last member of the
`mcp` group, answers `401` unless something earlier in that group
registered a `Kinetis\Http\CurrentUserInterface`. Your authentication
middleware in the `vector` group does not run for `/mcp`. Add
`#[AsMiddlewareGroup('mcp', priority: 50)]` to it, at a priority above
the guard's 0, as {doc}`authentication` shows. In production, rebuild
the compiled cache (`kinetis build`) after the change, so the compiled
`mcp` group carries the middleware ({doc}`deployment`).

```{warning}
Do not set `MCP_HTTP_PUBLIC=true` to make the 401 go away. It serves
`/mcp` to unauthenticated callers: the guard lets every request through
whether or not an identity is registered, so any tool of yours that
needs no identity becomes public, and Vector's own still have no
identity to run as. Put the authentication middleware in the `mcp`
group instead.
```

## See also

- {doc}`authorization` — what an identity may do.
- {doc}`rest-api` — the list grammar the tools share.
