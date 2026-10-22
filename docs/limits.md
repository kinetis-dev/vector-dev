# Limits and guarantees

## Bounds

| Bound | Default |
|---|---|
| Records per page (`maxPerPage()`) | 200 |
| Records in one bulk operation or action (`bulkLimit()`) | 1 000 |
| Rows in an inline export (`exportLimit()`) | 10 000 |
| Rows in a queued export (`exportQueueLimit()`) | 250 000 |
| Rows and size of an import | 50 000 rows, 8 MB |
| Filter nesting and size | 4 groups deep, 50 conditions |
| A saved view or a preference value | 16 KiB |
| Preference keys per account | 500 |
| A job's file | 12 MiB (16 MiB as base64); a CSV export job stops at that size and reports it, an import's failed-rows file keeps the rows that fit and says how many it left out |
| An image upload / a file upload (`maxBytes()`) | 5 MB / 10 MB |

The per-resource bounds are methods on the resource; see
{doc}`resources`.

## Guarantees

- **An allow-list for every identifier.** A field, sort, filter, column
  or action a request names is checked against the compiled descriptor;
  request text never reaches SQL as an identifier.
- **A compiled, immutable descriptor.** It is compiled once (at build,
  or on first boot in development); request-time services hold it and
  the request's user only.
- **A bounded list.** Every list runs the same plan: scope → soft-delete
  predicate → filters → search → order terminated by the key → one page
  → one statement per relation field → one aggregate for summaries. A
  list page is at most one count, one page, one statement per relation
  field on the page and one aggregate for summaries. A record is one
  statement plus one per relation field. A write is one flush.
- **One transaction per operation.** A batch, an inline changeset, a
  repeater and an action each commit together or not at all.
- **No lost updates.** With a `#[Version]` column, a stale write answers
  `409`; a unique-index violation answers `422` on its field.
- **Writer conventions.** Timestamps and soft deletes are resource
  conventions the writer applies.
- **Request isolation.** Nothing Vector keeps between requests carries a
  user's state; everything per request is built by the request
  container.

## Known limits

- Search is a case-insensitive `LIKE` (`ILIKE` on PostgreSQL), and
  whole words only on the fields a resource indexes with `fullText()`.
  Matches are not ranked: a list keeps its sort.
- Vector's writes to its own tables (action log, audit, jobs) run on
  Vector's connection as statements of their own, after the entity
  flush has committed — on the same connection or another. When
  Vector's tables are on another connection than the entities, they
  commit separately.
- Vector never deletes a stored upload when a value changes;
  `vector:files:prune` deletes the ones no record uses ({doc}`files`).
