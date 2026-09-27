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
each field, `vector_describe` states:

- **`writable`**: whether a create, an update, both or neither may write it.
- **`required`**: whether a create must send it. This is the writer's own
  rule, so a create with just the required fields succeeds.
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
  `mcp` group, as it joins `vector`.
- **Over stdio**, with `kinetis mcp:serve`: the tools need an
  authenticated identity, so without one they fail closed.

Either way, Vector's access policy decides whether the identity may use
the admin at all, as it does for the UI.

## See also

- {doc}`authorization` — what an identity may do.
- {doc}`rest-api` — the list grammar the tools share.
