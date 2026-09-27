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
| A job's file | 12 MiB |
| An image upload / a file upload (`maxBytes()`) | 5 MB / 10 MB |

The per-resource bounds are methods on the resource; see
{doc}`resources`.

## Guarantees

- **An allow-list for every identifier.** A field, sort, filter, column
  or action a request names is checked against the compiled descriptor;
  request text never reaches SQL as an identifier.
- **A bounded list.** A list page is at most one count, one page, one
  statement per relation field on the page and one aggregate for
  summaries. A record is one statement plus one per relation field. A
  write is one flush.
- **One transaction per operation.** A batch, an inline changeset, a
  repeater and an action each commit together or not at all.
- **No lost updates.** With a `#[Version]` column, a stale write answers
  `409`.
- **Request isolation.** Nothing Vector keeps between requests carries a
  user's state; everything per request is built by the request
  container.

## Known limits

- Search is a case-insensitive `LIKE` over the resource's own searchable
  columns. Relation search and full-text search are not implemented.
- When Vector's tables are on another connection than the entities, its
  own rows (audit, action log, jobs) commit separately, after the entity
  changes.
- Vector never deletes a stored upload when a value changes.
