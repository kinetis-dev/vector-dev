# kinetis/vector-queues

A queue dashboard for [`kinetis/vector`](https://github.com/kinetis-dev/vector): the size of each
queue, the jobs the worker gave up on — to browse, retry, forget and
prune — queues to clear, and per-minute throughput, failure and
duration metrics. Built on
[`kinetis/queue`](https://github.com/kinetis-dev/queue) and
[`kinetis/queue-failed-jobs`](https://github.com/kinetis-dev/queue-failed-jobs).

On the 0.x line, so a minor release may break. The package ships the
JSON API and an admin UI module,
`resources/ui/queues.js`, which its `composer.json` declares
(`extra.kinetis.vector.extensions`), so the admin loads it, hosted or
standalone: a
"Queues" page (overview, failed jobs, metrics) that offers only the
actions `GET /vector/queues/permissions` allows, and the renderer of
the `vector-queues` dashboard card. To show the card, list
`Kinetis\Vector\Queues\Widgets\QueuesWidget` in a dashboard's
`widgets()`; the package adds it to none. The card reads
`GET /vector/queues` itself, behind the same policy.

The user manual's
[Queue dashboard](https://kinetis.dev/vector/docs/queues.html) page
covers it in full.

## Install

```sh
composer require kinetis/vector-queues
```

## Requirements

- PHP 8.4, `kinetis/vector` ^0.14, `kinetis/queue` ^1.9 and
  `kinetis/queue-failed-jobs` ^1.1.
- The `kinetis_failed_jobs` table: copy the migration stub
  `kinetis/queue-failed-jobs` ships for your database into your
  `migrations/` directory, as its README describes, and run
  `vendor/bin/kinetis migrate`.
- The `vector_queue_metrics` table, on the same connection: copy
  `migrations/create_vector_queue_metrics_table.mysql.php.stub` (MySQL
  8.0.19 or later) or `create_vector_queue_metrics_table.pgsql.php.stub`
  from this package into the same `migrations/` directory —
  `migrations/<name>/` when `FAILED_JOBS_DB_CONNECTION_NAME` names a
  connection — renamed with a timestamp prefix, e.g.
  `20261103000001_create_vector_queue_metrics_table.php`, and run
  `vendor/bin/kinetis migrate`. Queue names and job classes are compared
  exactly: on MySQL `queue` is `ascii_bin` and `job_class` is
  `VARBINARY(255)`; on Postgres both are `VARCHAR`, whose default
  collation is already byte-exact, so a class name must be valid in the
  database's encoding there.

## Configuration

```text
VECTOR_QUEUES=default,mail,reports
```

The queues the dashboard watches, comma-separated, in the order the
overview lists them — normally the names your worker serves
(`queue:work --queue=…`). A worker's queue names are not visible to an
HTTP process, so when `VECTOR_QUEUES` is unset or blank the dashboard
watches `default` only. Only these names are accepted in a path or a
filter: any other queue is a `404` in a path and a `422` in a filter,
and failed jobs on other queues are not listed, shown, retried or
forgotten.

Sizes and clears go to the application's bound `QueueInterface`. The
failed-job reads and the job metrics use the connection
`FAILED_JOBS_DB_CONNECTION_NAME` names (`default` when unset), as
`kinetis/queue-failed-jobs` does, sharing one link of their own.

## Endpoints

Every route is in Vector's `vector` middleware group, so the
application's authentication and `VectorAccessInterface` run first; an
unauthenticated call gets whatever that group answers (`401`). Every
answer is JSON.

| Method and path | What it does |
|---|---|
| `GET /vector/queues` | `{data: [{name, size, clearable, failed}]}` per watched queue. `size` is `null` when the backend cannot report it. |
| `GET /vector/queues/permissions` | What the policy lets the caller do, for the UI to leave out the rest: `{view, retry, forget, prune, clear: {<queue>: bool}}`, `clear` per watched queue. Reads nothing but the policy; every route still asks it. |
| `GET /vector/queues/failed` | Failed jobs, newest first: `{data, nextCursor, hasMore, limit}`. Query: `queue`, `class`, `failedFrom`, `failedTo` (RFC 3339, whole seconds, inclusive), `limit` (default 25, at most 100), `cursor` (the previous page's `nextCursor`). An unknown parameter or a bad value is a `422`. Rows carry no job arguments. |
| `GET /vector/queues/failed/{id}` | One failed job. Every argument passes through `JobSerializer::redact()`, so a `#[Sensitive]` value is `[redacted]` — and every value is, when the job class no longer loads. `exception.trace` is always `null`: the store keeps no trace. |
| `POST /vector/queues/failed/retry` | `{"ids": [...]}`, 1 to 50 ids, through `FailedJobRetrier`: `{data: [{id, status, class, queue, reason}]}`, one per id in order, `status` being `retried`, `not_found` or `failed`. |
| `DELETE /vector/queues/failed/{id}` | Forgets one record: `204`. |
| `POST /vector/queues/failed/prune` | `{"hours": n}` or `{"days": n}` — exactly one, from 1 — through the store's own `prune()`, which spans every queue: `{data: {deleted, olderThanSeconds}}`. |
| `POST /vector/queues/{queue}/clear` | `{"confirm": "<queue>"}`: the queue's name exactly, or `422`. A backend without `ClearableQueueInterface` (SQS) is refused with `409`. `{data: {queue, cleared}}`. |
| `GET /vector/queues/metrics` | Job metrics over a window, described below. |

## Metrics

A listener records every attempt the worker settles — kinetis/queue's
`JobSucceeded`, `JobReleased` and `JobFailedPermanently` events — into
`vector_queue_metrics`: one row per minute, queue and job class (the
events carry no connection name), with the `succeeded`, `released` and
`failed` counts, the summed and longest handler durations
(`total_duration_ms`, `max_duration_ms`) and `last_processed_at`. Each
attempt is one atomic upsert, so concurrent workers never lose an
increment. Installing the package is what turns recording on, in every
process that runs `queue:work`.

Recording never affects a job: the events fire after the job is
settled, and a failed write — the table missing, the database down — is
logged as a warning, at most once a minute per worker, and dropped.

`GET /vector/queues/metrics` reads a window of it, on the watched queues
only. Query:

- `from`, `to` — unix seconds, each taken to the start of its minute,
  both minutes included. `to` defaults to now and `from` to 59 minutes
  before `to`: the last 60 minutes. The window spans at most 7 days
  (10 080 minutes).
- `queue` — one watched queue; any other is a `422`, as in the failed
  list's filter.
- `class` — one job class, fully qualified.

A bad value, an inverted or too-long window, and an unknown parameter
are a `422`. The answer:

```text
{data: {
  from, to, bucketSeconds: 60,
  buckets: [{bucket, succeeded, released, failed, averageDurationMs, maxDurationMs}],
  queues:  [{queue, succeeded, released, failed, failureRate, averageDurationMs, maxDurationMs, lastProcessedAt}],
  classes: [{class, succeeded, released, failed, failureRate, averageDurationMs, maxDurationMs, lastProcessedAt}]
}}
```

`buckets` has one entry per minute of the window, every number `0` in a
minute nothing ran; `queues` and `classes` list only what ran in it.
Times are unix seconds and durations milliseconds. The average is over
every attempt; `failureRate` is `failed / (succeeded + failed)` — the
share of finished jobs that failed permanently, a release being a retry
rather than an outcome — and `null` when nothing finished.

Nothing prunes the table on its own. Run, from a scheduler:

```sh
vendor/bin/kinetis vector:queues:prune-metrics --days=7
```

It deletes the rows whose minute began more than `--days` days ago (a
whole number from 1 to 36500; 7 when absent) and prints how many it
deleted. Any other `--days` is refused with exit code 1, deleting
nothing.

## Access

`Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface` decides each
call — `view` (the overview, the failed list and detail, the metrics
and the permissions), `retry`, `forget`, `prune`, and `clear`, which is
also given the queue's name so it can answer per queue, each given
Vector's `ActorContext` — and a refusal is a `403`. It is asked before
the request is validated and before any database or queue connection is
opened. The package binds
`ViewOnlyQueueDashboardPolicy`: anyone Vector admits may look, and
nobody may change anything. Bind your own in `bootstrap.php`, which runs
after the package and wins:

```php
$app->instance(QueueDashboardPolicyInterface::class, new MyQueueDashboardPolicy());
```

One instance answers every request a worker handles, so a policy holds
no request state.

## Audit

Every retry, forget, prune and clear writes to Vector's audit trail
(`vector_audit`) under the resource `vector-queues`, with the actor and
any impersonator: `queue:retry` per id with its status, class and
queue; `queue:forget` per id with its class and queue; `queue:prune`
with the age and the count deleted; `queue:clear` with the count
cleared. No entry carries a job argument.

## License

MIT — see [LICENSE](LICENSE).
