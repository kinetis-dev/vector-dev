# Changelog

All notable changes to `kinetis/vector` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
