# MCP tools

Vector exposes the admin to AI agents over the Model Context Protocol,
through `kinetis/mcp`. The tools call the same services and policies as
the REST API: an agent sees what its identity may see, and every write
is validated, hooked and audited.

| Tool | Does |
|---|---|
| `vector_resources` | Lists the resources this identity may use: slug, label and group — an index to pick from. |
| `vector_describe` | Describes one resource for reading and writing its records (below). |
| `vector_query` | Lists records under the list grammar: filter, search, sort, page. |
| `vector_get` | Reads one record. |
| `vector_create`, `vector_update`, `vector_delete` | Writes. |
| `vector_run_action` | Runs an action. |
| `vector_widget` | Computes a widget. |

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

`values` (on create, update and run-action) and `filter` (on query) are
plain JSON objects, shaped as in the REST API.

## Serving them

- **Over HTTP**, at `POST /mcp`: your authentication middleware joins the
  `mcp` group, as it joins `vector` (below).
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

`kinetis/mcp` closes the endpoint by default. Its
`Kinetis\Mcp\Http\McpIdentityGuardMiddleware` is the last member of the
`mcp` middleware group (priority 0), and it answers `401` unless
something earlier in that group registered a
`Kinetis\Http\CurrentUserInterface` on the request scope. Your
authentication middleware in the `vector` group does not run for
`/mcp`: it has to join the `mcp` group as well.

```php
#[AsMiddlewareGroup('vector', priority: 50)]
#[AsMiddlewareGroup('vector-shell', priority: 50)] // hosted mode only
#[AsMiddlewareGroup('mcp', priority: 50)]          // POST /mcp
final readonly class AdminAuthMiddleware implements MiddlewareInterface { /* … */ }
```

The middleware's priority in `mcp` must be above the guard's 0: the
higher the priority, the earlier a member runs, and a member at 0 or
below is not ordered before the guard, so requests still get `401`. `kinetis/mcp`'s other member, `McpOriginMiddleware`, is at 100
and checks the `Origin` header first; 50 sits between the two. The
demo's `App\Auth\VectorAuthMiddleware` carries exactly these three
attributes, at 50. In production, rebuild the compiled cache (`kinetis build`)
after the change, so the compiled `mcp` group carries the middleware
({doc}`deployment`).

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
