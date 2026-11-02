# Queue dashboard

`kinetis/vector-queues` adds a queue dashboard to the admin, in the
spirit of Laravel Horizon. It shows the size of each queue and the jobs
the worker gave up on, which can be browsed, retried, forgotten and
pruned. It can clear queues, and it records per-minute throughput,
failure and duration metrics. It is a separate Composer package, built on
[`kinetis/queue`](https://github.com/kinetis-dev/queue) and
[`kinetis/queue-failed-jobs`](https://github.com/kinetis-dev/queue-failed-jobs),
and it is unrelated to Vector's own background work ({doc}`jobs`): it
reads the application's queues, whatever pushes to them.

## Install

```console
composer require kinetis/vector-queues
```

The package needs PHP 8.4, `kinetis/vector` 0.14, `kinetis/queue` 1.9 or
later and `kinetis/queue-failed-jobs` 1.1 or later. Installing it is
the whole wiring:

- its `extra.kinetis` registers its bootstrap, its endpoints, its
  metrics listener and its console command, all discovered from their
  attributes;
- its `extra.kinetis.vector.extensions` declares its admin UI module,
  `resources/ui/queues.js`. Vector reads the declaration from
  Composer's `installed.json` and serves the module itself, and the
  admin loads it, hosted or standalone ("Extensions in a Composer
  package" in {doc}`customizing-the-ui`).
  Run `composer install` (or `composer update kinetis/vector-queues`)
  after any change to the package's `composer.json`, so `installed.json`
  carries it, and rebuild with `kinetis build` in production. The
  standalone UI imports the module from the API's origin, once it is
  signed in, so the API's CORS must cover `/vector/extensions/*` for the
  UI's origin ({doc}`deployment`).

Installing the package also turns on recording metrics, in every process
that runs `queue:work`.

## Migrations

The dashboard needs two tables, on the same database connection:

- `kinetis_failed_jobs`, where `kinetis/queue-failed-jobs` keeps the
  jobs the worker gave up on. Copy the stub for your database from
  `vendor/kinetis/queue-failed-jobs/resources/migrations/`
  (`create_kinetis_failed_jobs_table.mysql.php.stub` or
  `….pgsql.php.stub`), as that package's README describes.
- `vector_queue_metrics`, the job metrics. Copy
  `vendor/kinetis/vector-queues/resources/migrations/create_vector_queue_metrics_table.mysql.php.stub`
  or `create_vector_queue_metrics_table.pgsql.php.stub`. On MySQL it
  needs **8.0.19 or later**: every write is one
  `INSERT … AS incoming ON DUPLICATE KEY UPDATE`, and the row alias
  arrived in 8.0.19. Postgres uses `INSERT … ON CONFLICT … DO UPDATE`.

Put each in your `migrations/` directory with a timestamp prefix, for
example `20261103000001_create_vector_queue_metrics_table.php`, and run
`vendor/bin/kinetis migrate`. When `FAILED_JOBS_DB_CONNECTION_NAME`
names a connection, both go in `migrations/<name>/` instead.

Both tables are read through one link the package opens on the
connection `FAILED_JOBS_DB_CONNECTION_NAME` names, `default` when it is
unset or blank, the same connection `kinetis/queue-failed-jobs` records
on. The metrics table always lives beside the failed jobs.

`kinetis_failed_jobs` holds each job's arguments in full, `#[Sensitive]`
values included, because a retry rebuilds the job from them. Give it the
access controls the queue's own storage has. The dashboard never shows a
sensitive value (see below).

## Configuration

```text
VECTOR_QUEUES=default,mail,reports
```

These are the queues the dashboard watches, comma-separated, listed in
the order the overview shows them. Set it to the names your worker
serves (`queue:work --queue=default,mail,reports`). A worker's
`--queue` list is its own command-line argument, which no HTTP process
can see, so when `VECTOR_QUEUES` is unset or blank the dashboard watches
`default` only. Each name must follow `kinetis/queue`'s grammar
(letters, digits, `-` and `_`, up to 80 characters), or the application
does not boot.

Only these names are accepted. Any other queue is a `404` in a path and
a `422` in a filter, and failed jobs on other queues are not listed,
shown, retried or forgotten. Sizes and clears go to the application's
bound `QueueInterface`.

## Who may do what

Every endpoint is in Vector's `vector` middleware group, so the
application's authentication and `VectorAccessInterface` run first. An
unauthenticated call gets what that group answers, a `401`. Then
`Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface` decides each
call, and a refusal is a `403`:

| Method | Decides |
|---|---|
| `view(ActorContext $actor)` | The overview, the failed list and detail, the metrics and the permissions. |
| `retry(ActorContext $actor)` | Pushing failed jobs back onto their queue. |
| `forget(ActorContext $actor)` | Deleting one failed-job record without retrying it. |
| `prune(ActorContext $actor)` | Deleting every failed-job record older than an age. |
| `clear(ActorContext $actor, string $queue)` | Discarding every job waiting on `$queue`, one queue at a time. |

The policy is asked before anything else, before the request is
validated and before any database or queue connection is opened. A
refused caller learns nothing about which queues exist. The policy runs
for an unwatched queue's clear too, ahead of its `404`.

The package binds `ViewOnlyQueueDashboardPolicy`: anyone Vector admits
may look, and nobody may retry, forget, prune or clear. Every viewer
sees a failed job's exception class and message. The store keeps no
stack trace, so the detail's `exception.trace` is always `null`. Job
arguments pass through `JobSerializer::redact()`: a `#[Sensitive]`
constructor parameter shows as `[redacted]`, and so does every argument
when the job class no longer loads.

To let someone change things, bind your own policy in `bootstrap.php`.
It runs after the package's bootstrap, so its binding wins:

```php
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;

final readonly class AdminQueuePolicy implements QueueDashboardPolicyInterface
{
    public function view(ActorContext $actor): bool { return true; }
    public function retry(ActorContext $actor): bool { return self::admin($actor); }
    public function forget(ActorContext $actor): bool { return self::admin($actor); }
    public function prune(ActorContext $actor): bool { return self::admin($actor); }

    // per queue: an admin may empty a scratch queue but not the mail queue
    public function clear(ActorContext $actor, string $queue): bool
    {
        return self::admin($actor) && $queue !== 'mail';
    }

    // AppUser stands for your own user class, as the Vector actor carries it
    private static function admin(ActorContext $actor): bool
    {
        return $actor->user instanceof AppUser && $actor->user->isAdmin;
    }
}

$app->instance(QueueDashboardPolicyInterface::class, new AdminQueuePolicy());
```

One instance answers every request a worker handles, so a policy holds
no request state. The demo's `App\Auth\DemoQueueDashboardPolicy` lets
admins mutate ({doc}`demo`).

Every retry, forget, prune and clear writes to Vector's audit trail
({doc}`audit`) under the resource `vector-queues`, with the actor and
any impersonator. No entry carries a job argument.

## The admin page

The UI module registers the page `/p/queues`, **Queues** in the menu's
**Operations** section, with three tabs:

- **Overview**: each watched queue's waiting jobs and failed count,
  refreshed every five seconds while the page is visible, with
  **Clear** where the policy allows it.
- **Failed jobs**: the list with its filters, a **Load more** that
  follows the server's cursor, and each job's detail. **Retry** works on
  one job or a selection of up to 50; **Forget** and **Prune** (by age in
  days) are offered where the policy allows them.
- **Metrics**: a queue and a window from 15 minutes to 7 days, the
  totals, a per-minute sparkline, and tables per queue and per job
  class.

The page reads `GET /vector/queues/permissions` and leaves out every
action the policy refuses. That only shapes the page: every endpoint
asks the policy again. Each action asks for confirmation first. All the
page's text goes through the admin's translator (`defineMessages`) and
ships in every language Vector's UI ships, as machine translations; override
any of it with `defineMessages` under `vectorQueues`. English is the fallback.
Server text such as exception messages is rendered as text, never as
HTML.

## The dashboard card

The package's `Kinetis\Vector\Queues\Widgets\QueuesWidget` is a
dashboard card: the jobs waiting and failed across the watched queues,
with a link to the page. The package places it on no dashboard. List it
in one of your own:

```php
use Kinetis\Vector\Queues\Widgets\QueuesWidget;

#[Dashboard]
final class MainDashboard extends VectorDashboard
{
    public function widgets(): array { return [RevenueStat::class, QueuesWidget::class]; }
}
```

Its name is `queues` and its type `vector-queues`, an extension widget
type ({doc}`dashboards`) whose renderer the UI module registers. The
card reads `GET /vector/queues` itself, so the policy's `view` decides
what it shows: a viewer the policy refuses gets the card's "no access"
notice, not the figures. The widget's own answer, `GET
/vector/dashboards/{name}/widgets/queues`, carries no queue data and
opens no database or queue connection. It offers no range and caches
nothing. Like the page, the card needs the package's UI module, which
the hosted and the standalone admin both load. Without the module it
says that no renderer is registered for `vector-queues`.

## Endpoints

All answers are JSON, under `/vector/queues`:

| Method and path | What it does |
|---|---|
| `GET /vector/queues` | `{data: [{name, size, clearable, failed}]}` per watched queue. `size` is `null` when the backend cannot report it. |
| `GET /vector/queues/permissions` | What the policy lets the caller do: `{view, retry, forget, prune, clear: {<queue>: bool}}`, `clear` for each watched queue. It reads nothing but the policy. A `403` when `view` is refused. |
| `GET /vector/queues/failed` | Failed jobs, newest first: `{data, nextCursor, hasMore, limit}`. Query: `queue`, `class`, `failedFrom`, `failedTo` (RFC 3339, whole seconds, inclusive), `limit` (default 25, at most 100), `cursor` (the previous page's opaque `nextCursor`). An unknown parameter or a bad value is a `422`. Rows carry no job arguments. |
| `GET /vector/queues/failed/{id}` | One failed job, its arguments redacted as above. |
| `POST /vector/queues/failed/retry` | `{"ids": [...]}`, **1 to 50 ids**, through `FailedJobRetrier`: `{data: [{id, status, class, queue, reason}]}`, one per id in order, `status` being `retried`, `not_found` or `failed`. A record on an unwatched queue is `not_found`. |
| `DELETE /vector/queues/failed/{id}` | Forgets one record: `204`. |
| `POST /vector/queues/failed/prune` | `{"hours": n}` or `{"days": n}`, exactly one, a whole number from 1 (at most 36 500 days): `{data: {deleted, olderThanSeconds}}`. |
| `POST /vector/queues/{queue}/clear` | `{"confirm": "<queue>"}`, the queue's name exactly, or a `422`. A backend without `ClearableQueueInterface` (SQS) is refused with a `409`. `{data: {queue, cleared}}`. |
| `GET /vector/queues/metrics` | Job metrics over a window; see below. |

A clear answers in a fixed order: `403` when the policy refuses, `404`
for an unwatched queue, `422` for a wrong confirmation, `409` for a
backend that cannot clear.

**Pruning failed jobs spans every queue.** It goes through the store's
own `prune()`, which deletes every record older than the age given,
including records on queues the dashboard does not watch.

## Metrics

A listener records every attempt the worker settles: `kinetis/queue`'s
`JobSucceeded`, `JobReleased` and `JobFailedPermanently` events. Each
attempt is one atomic upsert into a row per minute, queue and job class,
so concurrent workers never lose an increment. A row holds the
`succeeded`, `released` and `failed` counts, the summed and longest
handler durations and the last time a job was processed. Recording never
affects a job. A failed write, for example a missing table or a database
that is down, is logged as a warning at most once a minute per worker
and dropped.

`GET /vector/queues/metrics` reads a window of it, on the watched queues
only:

- `from`, `to`: unix seconds, each taken to the start of its minute,
  both minutes included. `to` defaults to now and `from` to 59 minutes
  before `to`, so the default window is the last 60 minutes. A window
  spans at most 7 days (10 080 minutes).
- `queue`: one watched queue; `class`: one fully qualified job class.

The answer has `buckets` (one per minute, zero-filled), and `queues` and
`classes` (what ran in the window): counts, `failureRate`
(`failed / (succeeded + failed)`, or `null` when nothing finished),
average and longest duration in milliseconds, and `lastProcessedAt`.

Nothing prunes the table on its own. Run the prune command from your
scheduler, such as cron or a scheduled container task. Kinetis does not
schedule commands itself:

```console
vendor/bin/kinetis vector:queues:prune-metrics --days=7
```

It deletes the rows whose minute began more than `--days` days ago (a
whole number from 1 to 36 500, 7 when absent) and prints how many it
deleted. Any other `--days` exits with code 1 and deletes nothing. Once a
day is enough. The metrics for a window older than that are then gone.

## In the demo

The demo installs the package, binds `DemoQueueDashboardPolicy` and
watches `VECTOR_QUEUES=default,vector`, the two queues its worker serves.
To put real rows in the Failed and Metrics tabs, queue its flaky job:

```console
docker compose exec app vendor/bin/kinetis demo:queue-flaky --count=20 --failure=40
```

Each `App\Jobs\SyncWarehouseStockJob` goes on `default` with up to three
attempts and fails the given share of them, so the metrics show releases
and failures, and a job that runs out of attempts lands in **Failed
jobs**. Its `apiKey` argument is `#[Sensitive]`, so its detail shows it
as `[redacted]`. See {doc}`demo`.

## See also

- {doc}`jobs`: Vector's own background jobs, on a queue this dashboard
  can watch too.
- {doc}`customizing-the-ui`: runtime extensions, which this page is
  built as.
- {doc}`authorization`: Vector's own access checks, which run before the
  dashboard's policy.
