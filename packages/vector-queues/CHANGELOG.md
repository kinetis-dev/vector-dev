# Changelog

All notable changes to `kinetis/vector-queues` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The page and card text ships in Vector's 16 other languages (machine
  translations); English is still the fallback for any other locale.

## [0.1.0] - 2026-11-02

### Added

- The queue dashboard's JSON API under `/vector/queues`, in Vector's
  `vector` middleware group: an overview of the watched queues (size,
  clearability, failed count), the failed-job list with keyset paging
  and queue, class and failed-at filters, a failed job's detail with its
  arguments redacted through `JobSerializer::redact()`, retry through
  `FailedJobRetrier`, forget, prune, and clearing a queue behind a
  confirmation of its name.
- `VECTOR_QUEUES`, the queues the dashboard watches; `default` when
  unset.
- `QueueDashboardPolicyInterface`, bound to `ViewOnlyQueueDashboardPolicy`
  by default: viewing for anyone Vector admits, no mutation.
- Every mutation writes an entry to Vector's audit trail under the
  resource `vector-queues`, without job arguments.
- Job metrics: a listener records every attempt the worker settles
  (`JobSucceeded`, `JobReleased`, `JobFailedPermanently`) into
  `vector_queue_metrics`, one atomic upsert per attempt into a row per
  minute, queue and job class, on the failed-jobs connection. A failed
  write never affects the job and is logged at most once a minute per
  worker. MySQL and Postgres migration stubs ship in `migrations/`.
- `GET /vector/queues/metrics`: per-minute buckets, zero-filled, and
  totals per queue and per class — counts, failure rate, average and
  longest duration, last processed — over a `from`/`to` window of up to
  7 days (the last 60 minutes by default), filtered by `queue` and
  `class`, behind the policy's `view`.
- `vector:queues:prune-metrics --days=<n>` (7 by default) deletes older
  metric rows.
- `GET /vector/queues/permissions`: what the policy lets the caller do
  (`view`, `retry`, `forget`, `prune`, and `clear` per watched queue),
  behind the policy's `view`. It only shapes the UI; every route still
  asks the policy.
- The admin UI module `resources/ui/queues.js`, declared under
  `extra.kinetis.vector.extensions` so the hosted admin loads it: the
  **Queues** page (`/p/queues`, in the menu's Operations section) with
  Overview, Failed jobs and Metrics tabs, offering only the actions
  `/permissions` allows. It also registers the renderer for the
  `vector-queues` dashboard card.
- `Kinetis\Vector\Queues\Widgets\QueuesWidget`, the dashboard card
  (name `queues`, extension type `vector-queues`): the jobs waiting and
  failed across the watched queues, with a link to the Queues page. An
  application lists it in a dashboard's `widgets()`; the package places
  it on none. The card reads `GET /vector/queues` itself, so the
  policy's `view` governs it. The widget's own answer carries no queue
  data and opens no connection. It has no range and caches nothing.
- Requires PHP 8.4, `kinetis/vector` `^0.14`, `kinetis/queue` `^1.9` and
  `kinetis/queue-failed-jobs` `^1.1`.
