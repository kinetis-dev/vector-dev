# Changelog

All notable changes to `kinetis/vector` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.13.0] - 2026-10-23

### Added

- `kinetis vector:install-ui` installs the hosted admin UI into the
  served directory, `VECTOR_UI_DIR` or `public/vector/ui`. By default it
  downloads the `@kinetis/vector-ui` release this package pins in
  `resources/ui.json` from the npm registry, over HTTPS, and checks the
  tarball against the registry's sha512 `dist.integrity` before
  extracting it. `--version=X.Y.Z` installs another published release;
  `--from=<tgz|dir>` installs a packed tarball (checked against
  `--integrity=sha512-…` when given) or a built `dist/static` directory;
  `--force` reinstalls a release that is installed and verifies already;
  `--check` verifies the served directory against its
  `vector-bundle.json` and the expected release without the network.
  Exit codes are 0 (done or in sync), 1 (refused or failed) and 2 (a
  usage error). `VECTOR_UI_REGISTRY` names another registry, and
  `VECTOR_UI_REGISTRY_TOKEN` a bearer token sent to that registry's host
  only. A bundle is staged and verified whole before anything in the
  served directory changes; `index.html` and `vector-bundle.json` are
  written last, and only files the previous manifest listed are pruned.
- `resources/ui.json` pins `@kinetis/vector-ui` 0.11.0, and
  `/vector/schema` publishes it as `ui.expected`, so the dashboard can
  say when it runs another release.

### Changed

- `kinetis vector:install` publishes the migrations only, then notes
  when the served admin UI is missing, has no `vector-bundle.json`, or is
  not the pinned release.
- The hosted bundle is no longer part of this package: it ships inside
  `@kinetis/vector-ui`, and `vector:install-ui` installs it.
- Requires `ext-zlib` and `ext-openssl`.

## [0.12.2] - 2026-10-22

### Changed

- The `vector_widget` MCP tool resolves its default dashboard and range
  before choosing which widget to compute. Its behavior is unchanged.

## [0.12.1] - 2026-10-22

### Changed

- The write, import and file-prune paths, list parsing, search, the MCP
  tools, the OpenAPI document, rich text, the registry compilers and the
  install command are split into smaller methods. Their behavior,
  responses and failure codes are unchanged.
- Ships the `@kinetis/vector-ui` 0.10.4 hosted bundle.

## [0.12.0] - 2026-10-22

### Added

- `kinetis vector:files:prune` deletes uploads no record uses: a file
  under a declared file or image field's `vector/{resource}/{field}/`,
  older than `--grace-hours=<n>` (24), that no file or image column of
  any row holds (trashed rows included), no rich-text document using
  the field as its `imageField` shows, and no entry of the resource's
  audit trail holds, since a revert writes an old value back.
  `--dry-run` lists what would be deleted. Nothing outside `vector/` is
  ever touched. Run it from a scheduler.

### Changed

- Uploading to a file or image field needs write permission, not just
  list (`viewAny`): the field — or a rich-text field uploading its
  images through it — on the create form while the policy allows
  `create()`, or on the update form while it allows `update()` on the
  record the upload names in its new multipart `record` part. Otherwise
  the upload answers `403`. The UI's inputs send the id of the record
  they edit; a client of its own that uploads while editing must send
  `record` too, or the account needs create permission. A rich-text
  field's image field no longer needs to be on a form itself.
- Ships the `@kinetis/vector-ui` 0.10.3 hosted bundle, whose upload
  inputs send that `record` part.

### Security

- A job whose push the queue backend refused stored the backend's own
  message on its row — hosts, ports, and whatever else the driver put in
  it — where the admin's Jobs page shows it. The row now says only that
  the job failed unexpectedly, as a job failing in the worker does; the
  message reaches the log with the rethrown exception.
- The MCP `vector://schema` resource listed every action of a resource,
  including those the policy's `runAction()` or the action's own
  `canRun()` refuses this user, which `GET /vector/schema` leaves out.
  It now publishes the same actions.
- `POST /vector/views/{resource}` saved a view of a resource whose list
  the policy's `viewAny()` refuses this user, which the views index
  already refused. Creating a view now asks `viewAny()` first and
  answers the same `403`.
- An import's failed-rows file echoed the uploaded cells as they came,
  so a cell such as `=HYPERLINK(…)` ran as a formula when the admin
  opened the file in a spreadsheet. Its headers and cells now get the
  same formula guard as CSV and XLSX exports.
- `GET /vector/r/{resource}/{id}/files/{field}` served whatever key the
  record held. Only a write checks the key, and only when storage was
  available at the time, so a value written otherwise could point at
  another field's upload or walk out of its prefix with `..`. A download
  now answers `404` for a key with an empty, `.` or `..` segment, a
  leading `/`, a backslash or a control character, or one under
  `vector/` but outside the field's own `vector/{resource}/{field}/`.
  Keys outside `vector/`, which applications and uploads-off forms
  write, are still served.
- Several request-value patterns ended in `$` without the `D` flag, so a
  trailing newline slipped through: a rich-text link's `rel`, a code
  block's `language`, an image's `src` and size, a date-time value, a
  widget's `range`, a preference key and the branding colours. Each now
  refuses it as it refuses any other value outside its pattern.

### Fixed

- A job whose completion notification could not be stored was marked
  failed after its row had already been finished. The row now stays
  done, and the failed notification is logged.
- An import whose failed-rows file came to more than 12 MiB, the most a
  job file holds, failed at the end with a `LengthException` although
  every row had been processed. The file now keeps the failed rows that
  fit, whole, the last one's `error` saying how many more are not in it,
  and the import completes.
- An enum value of the scalar type its enum is not backed by — any
  `filter[field]=…` on an int-backed enum, or a string sent for one in
  a body — answered `500` with a `TypeError` from `tryFrom()`. A list
  filter now matches an int-backed enum by its digits, and any other
  value is refused as `invalid_choice` (`422`).
- A CSV import refused a non-empty cell of a date-only field
  (`Kinetis\Orm\Date`) on every row with "must be a date as
  YYYY-MM-DD.": the parsed date reached the writer as an object rather
  than the text a body carries.
- A row of a CSV import that the database refused — a duplicate unique
  value, a value too long for its column — stayed pending in the entity
  manager, so the next row's write sent it again and failed with that
  row's reason, up to the next hundred rows. A refused row's pending
  changes are now discarded before the next row.
- The descriptor published each resource's `primaryKey.column`, the
  key's database column, which its JSON schema does not declare and no
  client reads; every other column it already leaves out. The key is
  `{field, type}`, as the MCP description has it.
- `descriptor.schema.json` did not declare two keys the descriptor
  publishes: a resource's `show.extra` (as `table.extra` is) and a
  relation's `owned`.

## [0.11.0] - 2026-10-21

### Added

- `Kinetis\Vector\Import\CsvReadException` and
  `Kinetis\Vector\Query\ExportWriteException`, thrown where the CSV
  reader and the CSV and XLSX export writers threw a bare
  `\RuntimeException`, with the same messages. Both extend
  `\RuntimeException`, so an existing `catch` still catches them.

### Fixed

- An integer identifier, list cursor, `page`, `perPage` or integer
  value no longer accepts a trailing newline: `"42\n"` read as `42`,
  because the patterns ended in `$` without the `D` modifier. It is now
  refused like any other non-integer — 404 for a record, job,
  notification, view or history entry, 422 for a list parameter or a
  field value.
- Ships the `@kinetis/vector-ui` 0.10.2 hosted bundle, with its keyboard
  and screen-reader fixes and its linear-time e-mail and Regex rule
  checks.

## [0.10.1] - 2026-10-21

### Security

- Ships the `@kinetis/vector-ui` 0.10.1 hosted bundle, built with
  `markdown-it` 15.0.3, which fixes two quadratic-time denials of
  service in rendering Markdown.

## [0.10.0] - 2026-10-20

### Added

- `GET /vector/openapi.json`: Vector's REST API as an OpenAPI 3.1
  document with concrete paths for the resources the caller may list,
  filtered through the same per-identity overlay as the descriptor, so a
  hidden resource, field or action never appears. It is served where the
  framework serves its own `/openapi.json`, per `OPENAPI_ENVIRONMENTS`
  and `APP_ENV`; elsewhere its path answers 404 before authentication.
- A searchable `belongsTo` makes `q` match the related record's title,
  through a subquery that applies neither the target's `scope()` nor its
  trash. Compilation refuses one whose target's title is not a string
  column.
- `VectorResource::fullText()`: `q` matches the named searchable text
  fields by whole words through their full-text index, `MATCH ...
  AGAINST` in boolean mode on MySQL and `to_tsvector @@ plainto_tsquery`
  on PostgreSQL. LIKE stays on the other searchable columns.
- `VectorResource::withoutCount()`: an offset list page skips its COUNT.
  The page is `{data, currentPage, perPage, total: null, lastPage: null,
  hasMore}`, with `hasMore` read from one row past the page through the
  same policy, scope and trash predicate; summaries still work. A counted
  resource's page is unchanged and carries no `hasMore`. Its export does
  not count the match either: `ResourceService::exportCount()` takes a
  `$cap` and reads at most one row past it, which is enough to choose
  between inline and queued, and a refusal says "more than N records".
- `VectorResource::cursorPaging()`: the admin pages the list by cursor.
  The REST API is unchanged: `?page=` and `?cursor=` work on every
  resource.
- Both flags are published in the schema's `table` (`withoutCount`,
  `cursorPaging`). The OpenAPI document gives a withoutCount resource's
  list `total` and `lastPage` as `null` and documents `hasMore`;
  `vector_describe` marks it `withoutCount`, and `vector_query` says so.
- `VectorResponse::assertHasMore()`. `assertTotal()` on an uncounted page
  now fails with a message pointing to it.

### Changed

- Ships the `@kinetis/vector-ui` 0.10.0 hosted bundle, which pages
  uncounted and cursor lists, and makes `VectorPage.total` and
  `lastPage` nullable.

### Fixed

- `ListRequest::fromQueryParams()` takes a caller's `perPageCap` only as a
  tighter bound: a cap above the resource's `maxPerPage()` no longer raises
  the page size a request may ask for.
- Two compile refusals read correctly: a badge without a color says
  "color", and a repeater naming a field its rows cannot write no longer
  puts "a" before the child's label ("not a writable field of Order
  line").

## [0.9.5] - 2026-10-19

### Changed

- Ships the `@kinetis/vector-ui` 0.9.4 hosted bundle, which renames the
  toast type to `VectorToast`.

## [0.9.4] - 2026-10-19

### Changed

- Documentation: the manual reviewed for correctness against the code;
  the agent guide moved to the Kinetis documentation as
  `kinetis://docs/vector`.

## [0.9.3] - 2026-10-18

### Changed

- Released alongside `@kinetis/vector-ui` 0.9.3, whose bundled package is
  unchanged from 0.9.2; nothing in `kinetis/vector` changed.

## [0.9.2] - 2026-10-18

### Changed

- The hosted bundle is built from `@kinetis/vector-ui` 0.9.2
  (Nuxt 4.6.1, Vue 3.5.44, Vuetify 4.2.6).

## [0.9.1] - 2026-10-18

### Changed

- The hosted bundle is built from `@kinetis/vector-ui` 0.9.1
  (Tiptap 3.32.0).

## [0.9.0] - 2026-10-18

### Added

- Resources over `kinetis/orm` entities, compiled into one immutable
  descriptor at `kinetis build`: derived or declared fields, layouts,
  conditions, dependent choices, repeaters, computed and rich-text
  fields, badges and global search, and several resources over one
  entity.
- A REST admin API under `/vector`: lists with filters, "any of" groups,
  sorting, grouping, summaries and cursors; create, update, delete,
  restore, batch and inline edits under optimistic locking; uploads;
  CSV and XLSX export and CSV import; saved views, preferences,
  settings groups, dashboards and widgets, notifications and
  impersonation.
- Resource policies, row scope and field visibility, enforced on every
  request, and a field-level audit trail with revert.
- Actions, run directly or as background jobs on the application's
  queue, with large exports and imports as jobs too.
- MCP tools and the `vector://schema` resource over the same services.
- The hosted admin shell at `/vector/ui`, under a strict Content
  Security Policy, and labels translated per user.
- MySQL and PostgreSQL on every connection; Vector's own tables on a
  connection of their own.
- `vector:install`, `vector:list` and `vector:jobs:reap`, and
  `Kinetis\Vector\Testing` for an application's own test suite.
