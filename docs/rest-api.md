# REST API

Every route is under `/vector`, in the `vector` middleware group,
except hosted mode's page under `/vector/ui`, which is in the
`vector-shell` group ({doc}`authentication`). The API is the authority:
the UI and the MCP tools are clients of it, and anything they do, a
script can do.

The descriptor `GET /vector/schema` publishes validates against the JSON
schema the package ships, `resources/schema/descriptor.schema.json`
(`vendor/kinetis/vector/resources/schema/descriptor.schema.json` in an
application).

## Routes

| Route | Answers |
|---|---|
| `GET /vector/schema`, `GET /vector/schema/{resource}` | The descriptor for this user, with an `ETag` (`If-None-Match` answers `304`). |
| `GET /vector/openapi.json` | This API as an OpenAPI 3.1 document, for this user, where `OPENAPI_ENVIRONMENTS` opens it ([below](#openapi-document)). |
| `GET /vector/me` | The authenticated identity. |
| `GET /vector/r/{resource}` | A page of records under the list grammar. |
| `GET /vector/r/{resource}/export` | The same list as a CSV file; a job above the export limit. |
| `GET /vector/r/{resource}/{id}` | One record and this user's permissions on it. |
| `POST /vector/r/{resource}` | Creates a record: `201`, or `422` naming every failing field. |
| `PATCH /vector/r/{resource}/{id}` | Updates the fields sent. `If-Match: "<version>"` answers `409` on a stale record. |
| `DELETE /vector/r/{resource}/{id}` | Deletes, or trashes on a soft-deleting resource (`?force=1` deletes for good); `204`. |
| `POST /vector/r/{resource}/{id}/restore` | Takes a record out of the trash. |
| `POST /vector/r/{resource}/batch` | `action`: `delete`, `restore`, `forceDelete` or `update` (with `fields`), on `ids` or on `all: {filter, q}`, within the bulk limit. One transaction; answers `{affected, deleted}`. |
| `POST /vector/r/{resource}/changes` | Inline edits: `{"changes": [{"id", "version", "values"}]}`, one transaction; answers the records as written. |
| `POST /vector/r/{resource}/actions/{action}` | Runs an action on `ids`, `all: {filter, q}`, or nothing (standalone), with `fields`. A queued action answers `202` with a `jobId`. |
| `GET /vector/r/{resource}/{id}/{relation}` | A page of a collection's records. |
| `GET /vector/r/{resource}/{id}/{relation}/rows` | A repeater's current rows. |
| `POST /vector/r/{resource}/{id}/{relation}/attach`, `…/detach` | `{"ids": [...]}` on a many-to-many. |
| `GET /vector/r/{resource}/fields/{field}/options?q=` | Choices for a relation input, each `{id, label}` plus the target's `subtitle` and `image` field when it declares them; `ids=` resolves known ones, `depends[field]=` narrows a dependent choice. |
| `POST /vector/r/{resource}/fields/{field}/upload` | Stores one file (multipart `file`); answers `{key, name, size, type}`. |
| `GET /vector/r/{resource}/{id}/files/{field}` | The record's stored file. |
| `GET /vector/r/{resource}/{id}/audit` | The record's history. |
| `POST /vector/r/{resource}/{id}/audit/{entry}/revert` | Reverts one update; `409` when a field changed since. |
| `GET /vector/r/{resource}/widgets/{widget}?range=` | A resource widget's data. |
| `POST /vector/r/{resource}/import/preview`, `…/import` | CSV import: a preview with a suggested mapping, then the queued import. |
| `GET /vector/badges` | Menu badges by resource. |
| `GET /vector/search?q=` | Global search. |
| `GET /vector/dashboards/{name}`, `…/widgets/{widget}?range=` | A dashboard, and one card's data. |
| `GET/POST /vector/views/{resource}`, `PUT/DELETE /vector/views/{resource}/{id}` | Saved views. |
| `GET/PUT /vector/preferences` | Per-user preferences; `PUT` merges, `null` removes a key. |
| `GET /vector/settings`, `GET/PUT /vector/settings/{group}` | Settings groups. |
| `GET /vector/jobs`, `GET /vector/jobs/{id}`, `GET /vector/jobs/{id}/download` | This user's jobs and their files. |
| `GET /vector/notifications`, `POST …/{id}/read`, `POST …/read-all` | The inbox. |
| `POST /vector/impersonate/{userId}`, `DELETE /vector/impersonate` | Impersonation. |
| `GET /vector/ui`, `GET /vector/ui/{path}` | Hosted mode: the admin's page. |

## Envelopes

- **A list** — `{data, currentPage, perPage, total, lastPage}`, or
  `{data, nextCursor, hasMore, perPage}` under a cursor, plus
  `summaries` when asked.
- **An uncounted list** — a resource that declares `withoutCount()`
  issues no COUNT: its offset page is `{data, currentPage, perPage,
  total: null, lastPage: null, hasMore}`, where `hasMore` says whether
  another page follows (read from one row past the page), plus
  `summaries` when asked. A counted resource's page carries no
  `hasMore`. `GET /vector/openapi.json` describes each resource's list
  in its own form. A collection's page under `/{id}/{relation}` takes
  the target resource's form; that path is not in the OpenAPI document.
  The cursor envelope is the same for every resource.
- **A record** — `{data, permissions}`, where `permissions` is
  `{update, delete, restore, forceDelete, actions}`.
- **A write refusal** — the framework's RFC 9457 document with
  `errors[].path/code/message`. Other refusals: `{"error": "..."}`.
- **An action** — `{type, message, url, affected, jobId}`, where `type`
  is `message`, `redirect`, `url`, `danger` (answered `422`) or `job`
  (answered `202`); a download answers the file itself.
- **A job** — `{id, kind, resource, name, actorId, impersonatorId,
  status, total, processed, result, failure, hasFile, fileName,
  createdAt, updatedAt, finishedAt}`.

## Language

Labels in `GET /vector/schema`, `/schema/{resource}`, `/settings`,
`/dashboards/{name}` and widget results are translated for the request's
language: the user's `locale` preference (`PUT /vector/preferences`,
which refuses a language not on offer with `422`), else the
administrator's default — among the languages on offer;
`Accept-Language` is not consulted. The schema's `i18n` is
`{locale, locales: [{code, name, dir}]}`, and its `ETag` covers the
language. Values and records are never translated. See {doc}`languages`.

## Relation options

`GET /vector/r/{resource}/fields/{field}/options` answers
`{data: [{id, label, subtitle?, image?}]}`. `subtitle` (a string or
null) is present when the target declares `searchSubtitle()` and the
user may see that field; `image` likewise for `searchImage()`, and is
the image field to fetch from `GET /vector/r/{target}/{id}/files/{image}`,
with the admin's credentials, or null when the record has none. Both are
read in the options' one statement. Relation values inside records stay
`{id, label}`.

## Duplicate

A resource's `replicable` says whether the admin offers Duplicate; a
field's `unique` whether a copy starts without its value (and, for an
import, whether it can match rows). A copy is an ordinary create. See
{doc}`fields`.

## Export

`GET /vector/r/{resource}/export` takes the list grammar and `format`:
`csv` (the default) or `xlsx` when `capabilities.exportFormats` offers
it, else `422` on `format`. A CSV up to `exportLimit()` answers the
file; a larger one, and every XLSX, answers `202 {type: "job", jobId}`,
and the file is the job's download (`text/csv; charset=utf-8` or
`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`).
An export writes exactly the columns named. See {doc}`jobs`.

## Import

`POST /vector/r/{resource}/import` takes a multipart `file`, `mapping`
(JSON, CSV header → field name), `mode` and `key`:

| `mode` | Each row | Needs |
|---|---|---|
| `create` (default) | is created | `permissions.create`; no `key` |
| `update` | updates the record whose `key` matches it; a row matching none is a failed row | `permissions.update` and a `key` |
| `upsert` | updates the matching record, or is created when none matches | either permission and a `key` |

`key` is the resource's primary key field or a field with
`unique: true`, and must be mapped. The mapping may name what the mode
writes: the `create` view's fields, the `update` view's, or either for
`upsert` (a row is then refused, per row, a field its own path does not
take). The key itself is matched by and never written by an update. Each
match is one indexed lookup through the resource's `scope()`, excluding
trashed records, and each write goes through the writer, so rules, the
`create` or `update` policy, hooks and audit apply per row. The job's
`result` is `{created, updated, failed}` (an object whose key order is
not guaranteed); refused rows, with an `error` column, are the job's
file. `POST …/import/preview` suggests a mapping, including the key
column.

## Layout

A resource's `layout` is `null`, or `{form, show}`: each a list of nodes
`{kind, label, description, collapsible, collapsed, columns, span, name,
children}`, where `kind` is `section`, `tabs`, `tab` or `field`. A
`field` node names a field of the resource; the others hold `children`.
Every key is present on every node. The tree holds only the fields that
view shows and this identity may see, without a section or tab left
empty. Layout groups fields for display; it never changes a record, a
write or the list grammar. See {doc}`forms`.

## Values on the wire

| Value | As |
|---|---|
| Instants | ISO 8601 strings, UTC |
| Dates | `YYYY-MM-DD` |
| Money | Integers in the currency's minor units; the schema lists exceptions to hundredths in `minorUnits` |
| Belongs-to, has-one | `{id, label}` or `null`, plus the related currency when a money field is priced in it (`currencyField('order.currency')`) |
| Has-many, many-to-many | `{count}` on a record; a list of ids on a write |
| Rich text | The editor's JSON document in a string — or, where the field `storesHtml`, the stored HTML; a write always sends the document |
| Markdown | Its source |
| Computed | Typed by its `computed.as` (money as minor units, a date as `YYYY-MM-DD`, an instant as ISO 8601 UTC), always with the fields it is computed from |
| Ids | As the entity types them |
| Trashed | `_trashed` on a soft-deleting resource's records |
| Version | `_version` on the records of an entity with a `#[Version]` column: the token a `PATCH` (`If-Match`) or an inline changeset sends back |

Other values are JSON scalars.

`columns` narrows the fields a list record carries to those index
fields, plus what their display needs (a money field's currency). The
primary key is always there, since it is the record's identity, whether
or not the columns name it.

## The list grammar

| Parameter | Meaning |
|---|---|
| `page`, `perPage` | The page; `perPage` is bounded by the resource. |
| `sort=-createdAt,name` | Sortable fields; `-` for descending. |
| `filter[field][op]=value` | Filterable fields, with the operators they publish. `filter[field]=value` is `eq`; `in`, `nin` and `between` take `a,b`. |
| `filter[or][i]…`, `filter[and][i]…` | Alternatives and combined groups, four deep, 50 conditions at most. |
| `filter[trashed]=only\|with` | The trash, on a soft-deleting resource. |
| `q` | Search over the searchable fields: contains the term, a searchable relation's title contains it, or full-text fields hold its words; see {doc}`resources`. |
| `group=field` | Group by a groupable field. |
| `summaries=1` | Include the summaries. |
| `columns=a,b` | Only these fields, plus what their display needs. |
| `cursor` | Keyset pages by the identifier. |

`(archived or low stock) and (featured or expensive)`:

```text
filter[and][0][or][0][status][eq]=archived&filter[and][0][or][1][stock][lte]=5
&filter[and][1][or][0][isFeatured][eq]=true&filter[and][1][or][1][priceCents][gt]=50000
```

A filter object's conditions must all hold. `filter[or][i]` holds
alternatives, and any one of them may hold. `filter[and][i]` combines
filter objects. Each alternative or combined object is itself a filter
object. Groups nest at most four deep, a filter holds at most 50
conditions, and `trashed` belongs to the outermost object. The words
`or`, `and` and `trashed` cannot name a filterable field. Groups work
wherever a filter goes: lists and counts, summaries and grouping,
exports, an "all matching" selection, saved views, badges and the MCP
`vector_query` tool. The UI's filter bar edits "any of" groups beside
the plain conditions; a saved view nested deeper than that shows as one
advanced condition that can be removed.

Anything outside the descriptor's allow-lists answers `422` with the
reason.

## Statement bounds

A list page is at most: one count, one page, one statement per relation
field on the page, one aggregate for summaries. A show is one statement
plus one per relation field. A write is one flush.

## Errors

| Status | When |
|---|---|
| `401` | No identity. |
| `403` | Not admitted, or refused by a policy: `{"error": "…"}`. |
| `404` | Unknown resource or record. |
| `409` | A stale version, or a revert over a field changed since. |
| `422` | Validation — the framework's RFC 9457 document, with `errors[].path`, `code` and `message` — or an invalid list request. |

## OpenAPI document

`GET /vector/openapi.json` describes this API as an OpenAPI 3.1
document, for the user who asks. It has a path for each resource they
may list, with:

- the list parameters that resource accepts: each field's
  `filter[<field>][<op>]` operators, the sortable fields, `perPage` up
  to the resource's maximum, `page`, `cursor`, `q`, `summaries`,
  `group`, `columns`, and `filter[trashed]` where it soft-deletes;
- show, create, update, delete and restore, with bodies built from the
  compiled fields and their rules;
- its actions, export, import and relation options;
- `422` as the RFC 9457 document and every other error as
  `{"error": "…"}`.

A resource, field or action the policies hide from this user is absent,
as it is from the descriptor. The document carries an `ETag` for the
document and the user, and `If-None-Match` answers `304`.

It is served where the framework serves its own `/openapi.json`: when
`OPENAPI_ENVIRONMENTS` names `APP_ENV`. Unset, it is closed. A closed
endpoint answers every method with the `404` of a path no route matches,
before authentication runs; an open one is still in the `vector` group.
The setting is read when the worker boots. The framework's own
`/openapi.json` lists Vector's routes by their templates
(`/vector/r/{resource}` and so on); this document lists each resource's
own.

## See also

- {doc}`limits` — every bound.
- {doc}`mcp` — the same services for agents.
