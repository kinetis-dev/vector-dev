# Kinetis Vector

<style>
article[role="main"] section > p > strong:only-child { color: #D97706; }
@media (prefers-color-scheme: dark) {
  body:not([data-theme="light"]) article[role="main"] section > p > strong:only-child { color: #F59E0B; }
}
body[data-theme="dark"] article[role="main"] section > p > strong:only-child { color: #F59E0B; }
</style>

*A schema-driven admin platform for Kinetis applications.*

**Declare resources over your entities. Get an admin API, a complete admin UI and MCP tools from the same definition.**

- Resource classes over your entities: fields, filters, actions and widgets.
- A REST admin API that enforces your policies on every request.
- A Nuxt and Vuetify admin UI that renders whatever the API describes.
- Background jobs for large exports, imports and bulk actions.
- The same services as Model Context Protocol tools for AI agents.

## What you get

**Lists that do the work**

- Search, sort, filter with "any of" groups, group and summarize, on
  the server, over any page size the resource allows.
- Saved views, per-user columns, bulk edits, inline edits and CSV
  export and import.
- Global search from anywhere with <kbd>Ctrl</kbd>/<kbd>⌘</kbd>+<kbd>K</kbd>.

**Forms from the descriptor**

- One input per field, with the server's validation rules mirrored in
  the browser and every server violation placed on its field.
- Conditional fields, dependent choices, repeaters for owned rows,
  uploads and many-to-many relations.

**Operations, audited**

- Actions with their own dialogs, run on one record, a selection, or
  every record a filter matches, directly or as a background job.
- A field-level history of every change, with one-click revert.
- Impersonation, logged with both identities.

**Yours to control**

- Policies per resource, per record and per field. A field a user may
  not see leaves their descriptor, their records, exports and MCP
  results alike.
- Dashboards and widgets computed on your own queries.
- Branding, languages, custom cells, inputs, widgets and pages, without
  forking the UI.

## Two ways to serve the admin

**Standalone** — the UI is a static site on its own origin, calling the
API with a bearer token. **Hosted** — your PHP application serves the
same UI at `/vector/ui` behind its own session login, under a strict
Content Security Policy. Both are first-class and run the same code.
See {doc}`deployment`.

## Start here

````{grid} 1 2 2 2
:gutter: 3

```{grid-item-card} Getting started
:link: getting-started
:link-type: doc

Install the package, wire authentication, declare a first resource and
open the admin.
```

```{grid-item-card} Core concepts
:link: core-concepts
:link-type: doc

The descriptor, the API as the authority, and how the UI and the MCP
tools follow from them.
```

```{grid-item-card} Using the admin
:link: using-the-admin
:link-type: doc

A tour for the people who work in the admin every day: navigation,
lists, records and jobs.
```

```{grid-item-card} Resources
:link: resources
:link-type: doc

Everything a resource class declares, from fields to badges.
```
````

```{toctree}
:maxdepth: 2
:caption: Start here
:hidden:

Home <self>
getting-started
core-concepts
demo
```

```{toctree}
:maxdepth: 2
:caption: Using the admin
:hidden:

using-the-admin
lists
records
```

```{toctree}
:maxdepth: 2
:caption: Building the admin
:hidden:

resources
fields
forms
actions
dashboards
files
settings
```

```{toctree}
:maxdepth: 2
:caption: Access
:hidden:

authentication
authorization
audit
```

```{toctree}
:maxdepth: 2
:caption: Background work
:hidden:

jobs
```

```{toctree}
:maxdepth: 2
:caption: Deployment and customization
:hidden:

deployment
customizing-the-ui
languages
databases
```

```{toctree}
:maxdepth: 2
:caption: Integrations
:hidden:

mcp
testing
```

```{toctree}
:maxdepth: 2
:caption: Reference
:hidden:

reference
rest-api
configuration
limits
```

```{toctree}
:maxdepth: 2
:caption: Working on Vector
:hidden:

contributing
coding-agents
releasing
```
