# kinetis/vector-queues

A queue dashboard for [`kinetis/vector`](../vector): the size of each
queue, the jobs the worker gave up on — to browse, retry, forget and
prune — and queues to clear. Built on
[`kinetis/queue`](https://github.com/kinetis-dev/queue) and
[`kinetis/queue-failed-jobs`](https://github.com/kinetis-dev/queue-failed-jobs).

In development: this release ships the JSON API. The admin UI module
and metrics come later.

## Requirements

- PHP 8.4, `kinetis/vector`, `kinetis/queue` ^1.9 and
  `kinetis/queue-failed-jobs` ^1.1.
- The `kinetis_failed_jobs` table: copy the migration stub
  `kinetis/queue-failed-jobs` ships for your database into your
  `migrations/` directory, as its README describes, and run
  `vendor/bin/kinetis migrate`.

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
failed-job reads use the connection `FAILED_JOBS_DB_CONNECTION_NAME`
names, as `kinetis/queue-failed-jobs` does, on a link of their own.

## Endpoints

Every route is in Vector's `vector` middleware group, so the
application's authentication and `VectorAccessInterface` run first; an
unauthenticated call gets whatever that group answers (`401`). Every
answer is JSON.

| Method and path | What it does |
|---|---|
| `GET /vector/queues` | `{data: [{name, size, clearable, failed}]}` per watched queue. `size` is `null` when the backend cannot report it. |
| `GET /vector/queues/failed` | Failed jobs, newest first: `{data, nextCursor, hasMore, limit}`. Query: `queue`, `class`, `failedFrom`, `failedTo` (RFC 3339, whole seconds, inclusive), `limit` (default 25, at most 100), `cursor` (the previous page's `nextCursor`). An unknown parameter or a bad value is a `422`. Rows carry no job arguments. |
| `GET /vector/queues/failed/{id}` | One failed job. Every argument passes through `JobSerializer::redact()`, so a `#[Sensitive]` value is `[redacted]` — and every value is, when the job class no longer loads. `exception.trace` is always `null`: the store keeps no trace. |
| `POST /vector/queues/failed/retry` | `{"ids": [...]}`, 1 to 50 ids, through `FailedJobRetrier`: `{data: [{id, status, class, queue, reason}]}`, one per id in order, `status` being `retried`, `not_found` or `failed`. |
| `DELETE /vector/queues/failed/{id}` | Forgets one record: `204`. |
| `POST /vector/queues/failed/prune` | `{"hours": n}` or `{"days": n}` — exactly one, from 1 — through the store's own `prune()`, which spans every queue: `{data: {deleted, olderThanSeconds}}`. |
| `POST /vector/queues/{queue}/clear` | `{"confirm": "<queue>"}`: the queue's name exactly, or `422`. A backend without `ClearableQueueInterface` (SQS) is refused with `409`. `{data: {queue, cleared}}`. |

## Access

`Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface` decides each
call — `view`, `retry`, `forget`, `prune` and `clear`, each given
Vector's `ActorContext` — and a refusal is a `403`. The package binds
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
