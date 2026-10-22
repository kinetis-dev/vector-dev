# Background jobs

Some work is too large for a request. Vector runs it as a job on your
application's queue:

- **queued actions** — an action that declares `queued()`, run in chunks
  of `chunkSize()`;
- **large exports** — above the resource's `exportLimit()`, up to
  `exportQueueLimit()`, and every Excel (XLSX) export;
- **imports** — every CSV import.

Each job runs as the admin who started it, reports its progress to
`vector_jobs` for the **Jobs** page, which the UI polls, and leaves that
admin a notification when it finishes, with its file when there is one.
The admin is rebuilt in the worker through the application's
`ActorResolverInterface`. When they were being impersonated, the job row
remembers by whom, so the job's log and audit rows name the
impersonator as the request's would.

## Requirements

- `kinetis/queue` 1.7 or later, with a backend (SQL, Redis, SQS or
  RabbitMQ) and a worker running `kinetis queue:work`.
- An `ActorResolverInterface` binding; see {doc}`authentication`.

Without either, queued actions and imports answer `403` naming what is
missing, large exports are refused rather than truncated, and the
descriptor reports `capabilities.queue: false`, so the UI hides **Jobs**
and **Import CSV**.

## Where the jobs go

Vector has no queue of its own:

| Key | Meaning |
|---|---|
| `VECTOR_QUEUE` | The queue name Vector pushes to; `default` when unset. |
| `VECTOR_QUEUE_CONNECTION` | A named queue connection for Vector's jobs. Unset, the application's own queue. |

A named connection is built by `kinetis/queue`'s own `QueueFactory`:
its backend is `QUEUE_<NAME>_CONNECTION` (`redis`, `sql`, `sqs` or
`rabbitmq`, with that backend's package installed), and the backend
reads its own keys under the same name (`REDIS_<NAME>_*`, `DB_<NAME>_*`,
…). Vector builds it on the first push, once per worker, and closes it
with the application scope; the application's own queues are untouched.
A backend the queue package cannot build refuses the push with a `403`
carrying the queue package's reason.

A worker serves the connection by name:

```console
vendor/bin/kinetis queue:work --connection=admin --queue=vector
```

Whichever you choose, some worker must watch `VECTOR_QUEUE` on that
backend, or jobs wait as *queued*:

```console
vendor/bin/kinetis queue:work --queue=default,vector
```

## Delivery

A job is pushed for one attempt and claims its row with one conditional
write before it starts, so a message the backend delivers twice does
not run twice. A failure is final and shown on the job. A queued action
must still tolerate a chunk running twice: a chunk's flush and the
progress written after it are separate statements.

A failure the admin should read — the selection is gone, the action
declined, a validation refusal — is reported on the row verbatim; any
other exception is logged, and the row says only that the job failed.

## Stalled jobs

A job moves its own row, so a worker that dies mid-way leaves the row
`running`. A running row that has reported no progress for the minutes
the **Background jobs** settings group allows (30 until an admin changes
it) is answered as `stalled`: the **Jobs** page shows it so and stops
polling. `kinetis vector:jobs:reap`, run periodically by a scheduler,
marks such rows failed with the reason. The row itself stays `running`
until the reaper closes it, so a job that is in fact still running
finishes as usual.

## Excel exports

`format=xlsx` on `GET /vector/r/{resource}/export` writes an Excel
workbook with typed cells: numbers and money as numbers (money in major
units, its currency in the header when the field has one, else from the
row's currency), booleans as booleans, dates and date-times as real date
cells (in UTC), and everything else as text under the same formula guard
as CSV. It needs `openspout/openspout` (MIT) and PHP's `zip` extension:

```console
composer require openspout/openspout
```

Without them the descriptor's `capabilities.exportFormats` is `["csv"]`
and the UI offers CSV alone.

**An XLSX export is always a job.** openspout builds the sheet in a
temporary folder and zips it with `ZipArchive` on a real file path:
blocking file I/O, which a persistent request worker must not do and the
queue worker may. So XLSX is offered only when a queue runs Vector's
jobs, the request answers `202` with the job, and the same field
selection and limits apply (`exportQueueLimit()`).

## Imports

An import reads a CSV (up to 50 000 rows and 8 MB), maps columns to
fields as the user chose, and writes each row through the normal write
path: validation, policy, hooks and audit. Rows that fail are collected
into a file on the finished job, with the reason for each in an `error`
column. Its cells get the exports' formula guard: one starting with `=`,
`+`, `-`, `@`, a tab or a carriage return, other than a signed number,
gets an apostrophe in front, so remove it from a value you fix and
import again. The file holds at most 12 MiB: past that it keeps the
failed rows that fit, and the last one's `error` ends with how many
more are not in it. The job still completes, and its `failed` count is
every failed row.

What a row does depends on the mode:

| Mode | A row | Needs |
|---|---|---|
| Create new records | is created | permission to create |
| Update existing records | updates the record its key column matches; a row that matches none fails | permission to update, and a key |
| Update or create | updates the matching record, or is created | either permission, and a key |

The key column is the record's ID or a field declared `unique()`, and
must be mapped. One indexed lookup per row runs through the resource's
`scope()`, so a row can only match a record you may see in the list,
and every write goes through the writer, so the `update` policy, rules,
hooks and audit apply per row. The finished job reports
`{created, updated, failed}`; the exact contract is on {doc}`rest-api`.

Re-importing an export with `key: sku` in update-or-create (`upsert`)
mode changes the values in place instead of duplicating rows. Map the
columns that import back — a relation exports its label, a rich-text
field its plain text — and skip the rest.

Rows are created one by one: a failed row never undoes the rows before
it. To retry, fix and import the failed-rows file, not the original.

## See also

- {doc}`actions` — declaring a queued action.
- {doc}`limits` — every bound.
