# REST API

Every route is under `/vector`, in the `vector` middleware group
({doc}`authentication`). The UI and the MCP tools are clients of this
API; anything they do, a script can do.

## Routes

| Route | Answers |
|---|---|
| `GET /vector/schema`, `GET /vector/schema/{resource}` | The descriptor for this user, with an `ETag` (`If-None-Match` answers `304`). |
| `GET /vector/me` | The authenticated identity. |
| `GET /vector/r/{resource}` | A page of records under the list grammar. |
| `GET /vector/r/{resource}/export` | The same list as a CSV file; a job above the export limit. |
| `GET /vector/r/{resource}/{id}` | One record and this user's permissions on it. |
| `POST /vector/r/{resource}` | Creates a record: `201`, or `422` naming every failing field. |
| `PATCH /vector/r/{resource}/{id}` | Updates the fields sent. `If-Match: "<version>"` answers `409` on a stale record. |
| `DELETE /vector/r/{resource}/{id}` | Deletes, or trashes on a soft-deleting resource (`?force=1` deletes for good); `204`. |
| `POST /vector/r/{resource}/{id}/restore` | Takes a record out of the trash. |
| `POST /vector/r/{resource}/batch` | `delete`, `restore`, `forceDelete` or `update` (with `fields`) on `ids`, or on `all: {filter, q}`. One transaction. |
| `POST /vector/r/{resource}/changes` | Inline edits: `{"changes": [{"id", "version", "values"}]}`, one transaction. |
| `POST /vector/r/{resource}/actions/{action}` | Runs an action on `ids`, `all`, or nothing (standalone), with `fields`. A queued action answers `202` with a `jobId`. |
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
- **A record** — `{data, permissions}`, where `permissions` is
  `{update, delete, restore, forceDelete, actions}`.
- **An action** — `{type, message, url, affected, jobId}`; a download
  answers the file itself.
- **A job** — `{id, kind, resource, name, actorId, status, total,
  processed, result, failure, hasFile, fileName, createdAt, updatedAt,
  finishedAt}`.

## Values on the wire

| Value | As |
|---|---|
| Instants | ISO 8601 strings, UTC |
| Dates | `YYYY-MM-DD` |
| Money | Integers in the currency's minor units; the schema lists exceptions to hundredths in `minorUnits` |
| Belongs-to, has-one | `{id, label}` or `null` |
| Has-many, many-to-many | `{count}` on a record; a list of ids on a write |
| Trashed | `_trashed` on a soft-deleting resource's records |
| Version | `_version`, sent back as `If-Match` |

A list record always carries its primary key, whatever `columns` asks
for.

## The list grammar

| Parameter | Meaning |
|---|---|
| `page`, `perPage` | The page; `perPage` is bounded by the resource. |
| `sort=-createdAt,name` | Sortable fields; `-` for descending. |
| `filter[field][op]=value` | Filterable fields, with the operators they publish. `filter[field]=value` is `eq`; `in`, `nin` and `between` take `a,b`. |
| `filter[or][i]…`, `filter[and][i]…` | Alternatives and combined groups, four deep, 50 conditions at most. |
| `filter[trashed]=only\|with` | The trash, on a soft-deleting resource. |
| `q` | Search over the searchable fields. |
| `group=field` | Group by a groupable field. |
| `summaries=1` | Include the summaries. |
| `columns=a,b` | Only these fields, plus what their display needs. |
| `cursor` | Keyset pages by the identifier. |

`(archived or low stock) and (featured or expensive)`:

```text
filter[and][0][or][0][status][eq]=archived&filter[and][0][or][1][stock][lte]=5
&filter[and][1][or][0][isFeatured][eq]=true&filter[and][1][or][1][priceCents][gt]=50000
```

Anything outside the descriptor's allow-lists answers `422` with the
reason.

## Errors

| Status | When |
|---|---|
| `401` | No identity. |
| `403` | Not admitted, or refused by a policy: `{"error": "…"}`. |
| `404` | Unknown resource or record. |
| `409` | A stale version. |
| `422` | Validation — the framework's RFC 9457 document, with `errors[].path`, `code` and `message` — or an invalid list request. |

## See also

- {doc}`limits` — every bound.
- {doc}`mcp` — the same services for agents.
