# MCP tools

Vector exposes the admin to AI agents over the Model Context Protocol,
through `kinetis/mcp`. The tools call the same services and policies as
the REST API: an agent sees what its identity may see, and every write
is validated, hooked and audited.

| Tool | Does |
|---|---|
| `vector_resources` | Lists the resources this identity may use. |
| `vector_query` | Lists records under the list grammar: filter, search, sort, page. |
| `vector_get` | Reads one record. |
| `vector_create`, `vector_update`, `vector_delete` | Writes. |
| `vector_run_action` | Runs an action. |
| `vector_widget` | Computes a widget. |

The resource `vector://schema` is the descriptor, as this identity sees
it.

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
