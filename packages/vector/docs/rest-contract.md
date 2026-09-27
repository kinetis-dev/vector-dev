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

## Language

Labels in `GET /vector/schema`, `/schema/{resource}`, `/settings`,
`/dashboards/{name}` and widget results are translated for the request's
language: the user's `locale` preference (`PUT /vector/preferences`,
which refuses a language not on offer with `422`), else the
administrator's default — among the languages on offer; `Accept-Language`
is not consulted. The schema's `i18n` is `{locale, locales: [{code,
name, dir}]}`; its ETag covers the language.
Values and records are never translated.

## Relation options

`GET /vector/r/{resource}/fields/{field}/options` answers `{data:
[{id, label, subtitle?, image?}]}`. `subtitle` (a string or null) is
present when the target declares `searchSubtitle()` and the user may see
that field; `image` likewise for `searchImage()`, and is the image field
to fetch from `GET /vector/r/{target}/{id}/files/{image}`, or null when
the record has none. Both are read in the options' one statement.
Relation values inside records stay `{id, label}`.

## Duplicate

A resource's `replicable` says whether the admin offers Duplicate; a
field's `unique` whether a copy starts without its value (and, for an
import, whether it can match rows). A copy is an ordinary create.

## Export

`GET /vector/r/{resource}/export` takes the list grammar and `format`:
`csv` (the default) or `xlsx` when `capabilities.exportFormats` offers
it, else 422 on `format`. A CSV up to `exportLimit()` answers the file;
a larger one, and every XLSX, answers `202 {type: "job", jobId}` and the
file is the job's download (`text/csv; charset=utf-8` or
`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`).

## Import

`POST /vector/r/{resource}/import` takes a multipart `file`, `mapping`
(JSON, CSV header → field name), `mode` and `key`:

| `mode` | Each row | Needs |
|---|---|---|
| `create` (default) | is created | `permissions.create`; no `key` |
| `update` | updates the record whose `key` matches it; a row matching none is a failed row | `permissions.update` and a `key` |
| `upsert` | updates the matching record, or is created when none matches | either permission and a `key` |

`key` is the resource's primary key field or a field with `unique:
true`, and must be mapped. The mapping may name what the mode writes: the
`create` view's fields, the `update` view's, or either for `upsert` (a
row is then refused, per row, a field its own path does not take). The
key itself is matched by and never written by an update. Each match is
one indexed lookup through the resource's `scope()`, excluding trashed
records, and each write goes through the writer, so rules, the `create`
or `update` policy, hooks and audit apply per row. The job's `result` is
`{created, updated, failed}` (an object whose key order is not
guaranteed); refused rows, with an `error` column, are the job's file.
`POST …/import/preview` suggests a mapping, including the key column.

## Layout

A resource's `layout` is `null`, or `{form, show}`: each a list of nodes
`{kind, label, description, collapsible, collapsed, columns, span, name,
children}`, where `kind` is `section`, `tabs`, `tab` or `field`. A
`field` node names a field of the resource; the others hold `children`.
Every key is present on every node. The tree holds only the fields that
view shows and this identity may see. Layout groups fields for display;
it never changes a record, a write or the list grammar.

## Values on the wire

JSON scalars; ISO 8601 UTC strings for dates (`YYYY-MM-DD` for a date
field); integers for money in its currency's minor units (ISO 4217: hundredths, except the codes the schema lists in `minorUnits`, such as `JPY: 0`, `BHD: 3`); ids as the entity types them;
a `belongsTo` as `{id, label}` (or null), plus the related currency when a money field is priced in it (`currencyField('order.currency')`), a `hasOne` the same, a
`hasMany`/`manyToMany` as `{count}` on a record and as ids on a write;
a `richtext` field's value as the editor's JSON document in a string
(or, where the field `storesHtml`, the stored HTML; a write always sends
the document), a `markdown` field's as its source;
a `computed` field's value typed by its `computed.as` (money as
minor units, a date as `YYYY-MM-DD`, an instant as ISO 8601 UTC), always
with the fields it is computed from;
`_trashed` on a soft-deleting resource's records; `_version` on the
records of an entity with a `#[Version]` column, the token a `PATCH`
(`If-Match`) or an inline changeset sends back.

## The list grammar

`page`, `perPage`, `sort=-a,b`, `filter[f][op]=v` (`filter[f]=v` is
`eq`; `in`/`nin`/`between` take `a,b`), `filter[trashed]=only|with`,
`q`, `group=f`, `summaries=1`, `columns=a,b`, `cursor`. Anything outside
the descriptor's allow-lists is `422` with the reason.

`columns` narrows the fields a list record carries to those index
fields, plus what their display needs (a money field's currency). The
primary key is always there, since it is the record's identity, whether
or not the columns name it. An export writes exactly the columns named.

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
