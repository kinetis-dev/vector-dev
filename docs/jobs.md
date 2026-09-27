# Background jobs

Some work is too large for a request. Vector runs it as a job on your
application's queue:

- **queued actions** — an action that declares `queued()`, run in chunks
  of `chunkSize()`;
- **large exports** — above the resource's `exportLimit()`, up to
  `exportQueueLimit()`;
- **imports** — every CSV import.

Each job runs as the admin who started it, reports its progress to
`vector_jobs` for the **Jobs** page, and leaves a notification when it
finishes, with its file when there is one.

## Requirements

- `kinetis/queue` 1.7 or later, with a backend (SQL, Redis, SQS or
  RabbitMQ) and a worker running `kinetis queue:work`.
- An `ActorResolverInterface` binding; see {doc}`authentication`.

Without either, queued actions and imports are refused with a message
saying what is missing, large exports are refused rather than truncated,
and the UI hides **Jobs** and **Import CSV**.

## Where the jobs go

Vector has no queue of its own:

| Key | Meaning |
|---|---|
| `VECTOR_QUEUE` | The queue name Vector pushes to; `default` when unset. |
| `VECTOR_QUEUE_CONNECTION` | A named queue connection for Vector's jobs. Unset, the application's own queue. |

Some worker must serve that queue, or jobs wait as *queued*:

```console
vendor/bin/kinetis queue:work --queue=default,vector
vendor/bin/kinetis queue:work --connection=admin --queue=vector
```

## Delivery

A job is pushed for one attempt and claims its row with one conditional
write before it starts, so a message the backend delivers twice does
not run twice. A failure is final and shown on the job. A queued action
must still tolerate a chunk running twice: a chunk's flush and the
progress written after it are separate statements.

## Imports

An import reads a CSV (up to 50 000 rows and 8 MB), maps columns to
fields as the user chose, and creates each row through the normal write
path: validation, policy, hooks and audit. Rows that fail are collected
into a file on the finished job, with the reason for each in an `error`
column.

Rows are created one by one: a failed row never undoes the rows before
it. To retry, fix and import the failed-rows file, not the original.

## See also

- {doc}`actions` — declaring a queued action.
- {doc}`limits` — every bound.
