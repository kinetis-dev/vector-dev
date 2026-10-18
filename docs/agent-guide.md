# Agent guide

Routing guidance for an AI coding agent working on a Kinetis application
that may also use `kinetis/vector`, the optional schema-driven admin
package. Vector is not part of every Kinetis project. Over MCP this page
is the resource `kinetis://docs/vector`; it is also part of the Vector
manual at <https://kinetis.dev/vector/docs/>.

**Before anything else**, call `orbitron_inspect` where Orbitron is
registered, or read the project's own `composer.lock`, and confirm that
`kinetis/vector` is installed. When it is not, nothing on this page
applies: an application without the admin package has no Vector
correctness surface. This page existing is no reason to install Vector;
install it only when the task or the user asks for an admin.

## The version boundary

This page is fetched from the `main` branch of `kinetis-dev/vector-dev`
on every read, so it can describe a newer Vector than the one installed.
Once the package is confirmed, the installed `kinetis/vector` is the
authority for the exact contract this page routes to: its own
`README.md` and source, read with Orbitron's installed-source tools
(`orbitron_read_package_source`, `orbitron_search_package_source`,
`orbitron_search_package_source_tree` and `orbitron_list_package_source`),
or from the project's own `vendor/kinetis/vector` when Orbitron is
unavailable. The manual pages linked below describe `main` as well.

`@kinetis/vector-ui`, the npm package a standalone admin is built from,
is JavaScript that neither the documentation server nor Orbitron's
installed-source tools reach. Read its `package.json`, the lockfile and
its source with the project's own file-reading tools.

## Task routing

- **Install and migrations.** `vendor/bin/kinetis vector:install`
  publishes Vector's migrations and the hosted UI bundle a released
  package ships, and is idempotent: run it again after every upgrade,
  then `migrate`. `--connection=<name>` puts Vector's tables on a named
  connection, read at runtime from `VECTOR_DB_CONNECTION`.
  [Getting started](https://kinetis.dev/vector/docs/getting-started.html#install),
  [Databases](https://kinetis.dev/vector/docs/databases.html#vector-s-own-tables).
- **Resources, fields, forms, lists, records, actions, dashboards,
  settings and audit.** A `#[Resource]` class extending `VectorResource`
  declares `fields()`, `actions()` and `widgets()`; a `#[Dashboard]`
  class extending `VectorDashboard` and a `#[Settings]` class extending
  `SettingsGroup` add dashboards and settings groups. All of them compile
  into the descriptor.
  [Resources](https://kinetis.dev/vector/docs/resources.html),
  [Fields](https://kinetis.dev/vector/docs/fields.html),
  [Forms](https://kinetis.dev/vector/docs/forms.html),
  [Lists](https://kinetis.dev/vector/docs/lists.html),
  [Records](https://kinetis.dev/vector/docs/records.html),
  [Actions](https://kinetis.dev/vector/docs/actions.html),
  [Dashboards and widgets](https://kinetis.dev/vector/docs/dashboards.html),
  [Settings](https://kinetis.dev/vector/docs/settings.html),
  [Audit trail and impersonation](https://kinetis.dev/vector/docs/audit.html).
- **Authentication, authorization and field concealment.** The
  application's authentication middleware joins the `vector` middleware
  group and registers `Kinetis\Http\CurrentUserInterface`; Vector's
  `VectorAccessMiddleware` then asks the application's
  `VectorAccessInterface` whether that user may enter the admin at all.
  What an admitted user sees is the resource's `policy()` class (a
  `ResourcePolicyInterface`), its `scope()` for rows, and the policy's
  `viewField()` for fields.
  [Authentication](https://kinetis.dev/vector/docs/authentication.html#the-vector-middleware-group),
  [Authorization](https://kinetis.dev/vector/docs/authorization.html),
  [Field visibility](https://kinetis.dev/vector/docs/authorization.html#field-visibility).
- **The REST API and the MCP tools.** Every route is under `/vector`.
  The MCP tools — `vector_resources`, `vector_describe`, `vector_query`,
  `vector_get`, `vector_create`, `vector_update`, `vector_delete`,
  `vector_run_action` and `vector_widget` — call the same services and
  policies under the caller's identity. Over HTTP (`POST /mcp`) the
  authentication middleware must join the `mcp` group as well as
  `vector`.
  [REST API](https://kinetis.dev/vector/docs/rest-api.html),
  [MCP tools](https://kinetis.dev/vector/docs/mcp.html),
  [Serving them](https://kinetis.dev/vector/docs/mcp.html#serving-them),
  and the framework's [MCP](https://kinetis.dev/docs/mcp.html)
  (`kinetis://docs/mcp`).
- **Files, imports and exports.** Uploads are checked by their bytes,
  never their name, and stored privately under a generated key. Export
  is CSV or Excel (XLSX), and every XLSX export runs as a job; import is
  CSV only, and always a job.
  [Files and images](https://kinetis.dev/vector/docs/files.html),
  [Export and import](https://kinetis.dev/vector/docs/lists.html#export-and-import),
  [Excel exports](https://kinetis.dev/vector/docs/jobs.html#excel-exports),
  [Imports](https://kinetis.dev/vector/docs/jobs.html#imports).
- **Jobs: queued or inline, terminal state, reaping.** Actions that
  declare `queued()`, exports above the resource's `exportLimit()`, every
  XLSX export and every import run on the application's queue as the
  admin who started them; everything else runs in the request.
  [Background jobs](https://kinetis.dev/vector/docs/jobs.html),
  [Delivery](https://kinetis.dev/vector/docs/jobs.html#delivery),
  [Stalled jobs](https://kinetis.dev/vector/docs/jobs.html#stalled-jobs),
  [How an action runs](https://kinetis.dev/vector/docs/actions.html#how-it-runs).
- **Hosted or standalone UI, and the customization tiers.** Standalone
  is a Nuxt application extending `@kinetis/vector-ui` on its own
  origin, signing in with a bearer token; hosted is the bundle served by
  the PHP application at `/vector/ui` behind its session. Customization
  has three tiers: descriptor and settings, runtime extensions, and the
  application's own build.
  [Deployment](https://kinetis.dev/vector/docs/deployment.html),
  [Customizing the UI](https://kinetis.dev/vector/docs/customizing-the-ui.html).
- **Database, queue and storage topology.** An entity on a named
  connection, Vector's own tables on another (`VECTOR_DB_CONNECTION`),
  Vector's jobs on a named queue (`VECTOR_QUEUE`) or queue connection
  (`VECTOR_QUEUE_CONNECTION`), and uploads on a named storage connection
  (`VECTOR_STORAGE_CONNECTION`).
  [Databases](https://kinetis.dev/vector/docs/databases.html),
  [Where the jobs go](https://kinetis.dev/vector/docs/jobs.html#where-the-jobs-go),
  [Configuration](https://kinetis.dev/vector/docs/configuration.html#environment-keys).

## Correctness boundaries

- **Definitions are compiled and request-neutral.** The descriptor is
  compiled by `kinetis build` (or at boot, in development) from every
  resource, settings group and dashboard, and is immutable. A
  resource's definition methods return the same data for every request;
  what varies per request belongs in its request-time hooks.
  [The descriptor](https://kinetis.dev/vector/docs/core-concepts.html#the-descriptor),
  [Request-time hooks](https://kinetis.dev/vector/docs/resources.html#request-time-hooks),
  and the framework's [Caching & AOT Compilation](https://kinetis.dev/docs/caching.html)
  (`kinetis://docs/caching`).
- **Identity and policy stay request-scoped.** `CurrentUserInterface`
  is what the application's authentication middleware registers for
  each request, and policies, hooks, actions and widgets are built by
  the request container. The `VectorAccessInterface` implementation is
  bound on the application scope and answers for every request, so it
  must hold no request state. A job carries only the actor's id, and
  `ActorResolverInterface` rebuilds the user in the worker.
  [Request scope](https://kinetis.dev/vector/docs/core-concepts.html#request-scope),
  and the framework's [Container Lifecycle](https://kinetis.dev/docs/container.html)
  (`kinetis://docs/container`).
- **Vector's own database connection is explicit.**
  `VECTOR_DB_CONNECTION` names it, `default` when unset; it is never
  inferred from an administered entity's connection.
  [Vector's own tables](https://kinetis.dev/vector/docs/databases.html#vector-s-own-tables).
- **Relations and transactions do not span databases.** The ORM refuses
  a relation across two connections at build time, and each connection
  commits on its own: Vector's own rows (action log, audit, jobs) commit
  on its connection after the entity changes they describe.
  [Entities on several connections](https://kinetis.dev/vector/docs/databases.html#entities-on-several-connections),
  and the framework's [ORM](https://kinetis.dev/docs/orm.html)
  (`kinetis://docs/orm`) and [Database](https://kinetis.dev/docs/persistence.html)
  (`kinetis://docs/persistence`).
- **A queued job is not finished work.** A queued action, export or
  import answers only that the job was pushed. Its row moves through
  `queued`, `running`, and then `done` or `failed`; a job is pushed for
  one attempt and claims its row with one conditional write, so a
  duplicate delivery does not run twice.
  [Delivery](https://kinetis.dev/vector/docs/jobs.html#delivery),
  and the framework's [Queue](https://kinetis.dev/docs/queue.html)
  (`kinetis://docs/queue`).
- **The actor and the terminal state have to be proven.** Without
  `kinetis/queue` and an `ActorResolverInterface` binding, queued actions
  and imports answer `403` and large exports are refused. A worker that
  dies mid-job leaves the row `running`; once it has reported no progress
  for the configured minutes it is answered as `stalled`, and
  `vector:jobs:reap`, run by a scheduler, marks it `failed`.
  [Stalled jobs](https://kinetis.dev/vector/docs/jobs.html#stalled-jobs).
- **Uploads, export formats and the queue are optional capabilities.**
  The descriptor's `capabilities` (`uploads`, `exportFormats`, `queue`)
  report what this deployment has configured; a change that needs one
  must not assume it.
  [Requirements](https://kinetis.dev/vector/docs/jobs.html#requirements),
  and the framework's [Storage](https://kinetis.dev/docs/storage.html)
  (`kinetis://docs/storage`).
- **Hosted and standalone authentication, and the CSP, are deployment
  concerns.** Standalone sends `Authorization: Bearer …` from another
  origin and needs CORS; hosted sends the session cookie with
  `X-CSRF-Token` and needs a login page (`VECTOR_LOGIN_URL`). One
  middleware may accept both. The hosted shell runs under the
  application's own `SECURITY_CSP` with its nonce, or under
  `VECTOR_SHELL_CSP` for that page alone.
  [Deployment](https://kinetis.dev/vector/docs/deployment.html),
  [Content Security Policy](https://kinetis.dev/vector/docs/deployment.html#content-security-policy),
  [One API, both modes](https://kinetis.dev/vector/docs/authentication.html#one-api-both-modes).

## Verification checklist

- **Boot and compile.** The application boots, or `kinetis build`
  compiles, with every resource, action, widget, dashboard and settings
  group the change touches; `vendor/bin/kinetis vector:list` prints what
  compiled. A deliberate definition mistake fails naming the class and
  field.
  [Checked at build](https://kinetis.dev/vector/docs/resources.html#checked-at-build).
- **Authorization and concealment.** A restricted user gets the same
  refusal through REST and MCP, and a field `viewField()` hides is absent
  everywhere: the descriptor, records, the audit trail, exports and MCP
  results, with a filter, sort, grouping, column or written value naming
  it refused as unknown.
  [Field visibility](https://kinetis.dev/vector/docs/authorization.html#field-visibility),
  and the framework's [Authorization](https://kinetis.dev/docs/authorization.html)
  (`kinetis://docs/authorization`).
- **The real backend.** A claim about the database, queue or storage is
  checked against the connection, queue or filesystem Vector actually
  uses: "Read installed source to settle a fact" in
  [Agent Correctness Review](https://kinetis.dev/docs/agent-correctness.html)
  (`kinetis://docs/agent-correctness`).
- **Settlement.** Queued work is followed to its terminal state — `done`,
  `failed`, or stalled and then reaped as `failed` — never the push
  alone.
- **Request isolation.** No identity, impersonation or record selection
  is read back on a later request or in a resident Fiber: "Request state
  leakage" in
  [Agent Correctness Review](https://kinetis.dev/docs/agent-correctness.html).
- **The UI mode.** Hosted or standalone is exercised only when the
  change touches UI behavior.

## See also

- [Agent Workflow](https://kinetis.dev/docs/agent-workflow.html)
  (`kinetis://docs/agent-workflow`),
  [Application Recipes](https://kinetis.dev/docs/application-recipes.html)
  (`kinetis://docs/application-recipes`) and
  [Agent Correctness Review](https://kinetis.dev/docs/agent-correctness.html)
  (`kinetis://docs/agent-correctness`).
- The framework pages this one leans on:
  [Database](https://kinetis.dev/docs/persistence.html),
  [ORM](https://kinetis.dev/docs/orm.html),
  [Queue](https://kinetis.dev/docs/queue.html),
  [Storage](https://kinetis.dev/docs/storage.html),
  [Authorization](https://kinetis.dev/docs/authorization.html),
  [MCP](https://kinetis.dev/docs/mcp.html),
  [Caching & AOT Compilation](https://kinetis.dev/docs/caching.html) and
  [Container Lifecycle](https://kinetis.dev/docs/container.html), each
  also `kinetis://docs/<page>` (`persistence`, `orm`, `queue`, `storage`,
  `authorization`, `mcp`, `caching`, `container`).
- [Core concepts](https://kinetis.dev/vector/docs/core-concepts.html)
  and the [Reference](https://kinetis.dev/vector/docs/reference.html) of
  this manual.
