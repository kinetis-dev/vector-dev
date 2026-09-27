# Core concepts

Vector is three clients around one authority. Knowing which part
decides what makes the rest of the manual predictable.

## The descriptor

Every resource, field, action, widget, dashboard and settings group
compiles into one immutable **descriptor** when the application builds
(or boots, in development). It records what each resource is: its
fields and their types, what may be sorted, filtered and searched,
table and show layouts, actions and their dialogs, widgets, and the
menu.

The descriptor is also an allow-list. A field, sort, filter, column or
action that a request names is checked against it before anything runs,
so no request text ever reaches SQL as an identifier.

## The API is the authority

`GET /vector/schema` publishes the descriptor **for the user asking**:
resources they may not list are absent, fields they may not see are
removed, and each resource carries that user's permissions. Every other
route enforces the same decisions again. The UI never decides access; it
only reflects what the API published.

```text
             ┌────────────── compiled descriptor ──────────────┐
             │                                                 │
 admin UI ── REST API (/vector/…) ── policies ── your entities (ORM)
 AI agent ── MCP tools ─────────────┘
```

## One UI, no application knowledge

`@kinetis/vector-ui` renders the descriptor: navigation, lists, forms,
record pages, dashboards, jobs and settings. It contains nothing
specific to your application. You change what it shows by changing
resources on the server, and how it shows things through configuration,
extensions or your own build ({doc}`customizing-the-ui`).

## The same services for agents

The MCP tools call the same services and policies as the REST routes.
An agent sees what its identity may see, and writes go through the
same validation, hooks and audit trail. See {doc}`mcp`.

## Request scope

Anything that runs per request — policies, hooks, actions, widgets — is
built by the request container and may inject what a controller may.
Nothing Vector keeps on the application scope carries request state,
which is what a persistent worker requires.

## Where things run

| Work | Runs |
|---|---|
| Reads and writes, small exports, actions | In the request |
| Queued actions, large exports, imports | In a queue worker, as the admin who started them |
| Badges, widgets | In the request, optionally cached per worker |

## See also

- {doc}`resources` — declaring what the descriptor holds.
- {doc}`authorization` — how the per-user descriptor is decided.
- {doc}`rest-api` — every route.
