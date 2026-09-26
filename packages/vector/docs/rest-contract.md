# The REST contract

The API is the authority; the UI and the MCP tools are clients of it.
The descriptor `GET /vector/schema` publishes validates against
[`resources/schema/descriptor.schema.json`](../resources/schema/descriptor.schema.json),
and the reference application's test suite asserts that for its own
descriptor on every run.

## Envelopes

- A list: `{data, currentPage, perPage, total, lastPage}` (offset) or
  `{data, nextCursor, hasMore, perPage}` (cursor), plus `summaries`
  when asked.
- A record: `{data, permissions}` with `permissions` =
  `{update, delete, restore, forceDelete, actions}`.
- A write refusal: the framework's RFC 9457 document with
  `errors[].path/code/message`. Other refusals: `{"error": "..."}`.
- An action: `{type, message, url, affected, jobId}`; a `download`
  answers the file itself; a queued action answers `202`.
- A job: `{id, kind, resource, name, actorId, status, total, processed,
  result, failure, hasFile, fileName, createdAt, updatedAt, finishedAt}`.

## Values on the wire

JSON scalars; ISO 8601 UTC strings for dates (`YYYY-MM-DD` for a date
field); integers for money in its currency's minor units (ISO 4217: hundredths, except the codes the schema lists in `minorUnits`, such as `JPY: 0`, `BHD: 3`); ids as the entity types them;
a `belongsTo` as `{id, label}` (or null), plus the related currency when a money field is priced in it (`currencyField('order.currency')`), a `hasOne` the same, a
`hasMany`/`manyToMany` as `{count}` on a record and as ids on a write;
`_trashed` on a soft-deleting resource's records; `_version` on the
records of an entity with a `#[Version]` column, the token a `PATCH`
(`If-Match`) or an inline changeset sends back.

## The list grammar

`page`, `perPage`, `sort=-a,b`, `filter[f][op]=v` (`filter[f]=v` is
`eq`; `in`/`nin`/`between` take `a,b`), `filter[trashed]=only|with`,
`q`, `group=f`, `summaries=1`, `columns=a,b`, `cursor`. Anything outside
the descriptor's allow-lists is `422` with the reason.

A filter object's conditions must all hold. `filter[or][i]` holds
alternatives, and any one of them may hold. `filter[and][i]` combines
filter objects. Each alternative or combined object is itself a filter
object. Groups nest at most four deep, a filter holds at most 50
conditions, and `trashed` belongs to the outermost object. The words
`or`, `and` and `trashed` cannot name a filterable field.

## Statement bounds

A list page is at most: one count, one page, one statement per relation
field on the page, one aggregate for summaries. A show is one statement
plus one per relation field. A write is one flush. These bounds are
asserted by the reference suite against MySQL's own statement counter.
