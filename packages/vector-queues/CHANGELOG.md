# Changelog

All notable changes to `kinetis/vector-queues` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
