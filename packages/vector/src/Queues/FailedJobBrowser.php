<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues;

use Kinetis\Persistence\Contract\SqlLink;

/**
 * The dashboard's reads of `kinetis_failed_jobs` that SqlFailedJobStore
 * has no method for: a page filtered by class and failed-at range, newest
 * first, and the failure count per queue. Only ever reads, and never the
 * `args` column — a single record's arguments come from the store's own
 * find(), and are redacted before they leave the server.
 *
 * Application-scoped and request-neutral: the only state is the link,
 * opened by {@see QueueDashboard::register()} on the connection the store itself
 * uses (FAILED_JOBS_DB_CONNECTION_NAME) and closed with the application
 * scope.
 *
 * @phpstan-type FailedRow array{id: string, connection: string|null, queue: string, class: string, attempts: int, maxAttempts: int|null, exceptionClass: string, exceptionMessage: string, failedAt: int}
 */
final readonly class FailedJobBrowser
{
    private const string TABLE = 'kinetis_failed_jobs';

    public function __construct(private SqlLink $db) {}

    /**
     * Newest first, ordered by (failed_at, id) descending: the next page
     * starts strictly after the filter's cursor, so a record forgotten or
     * added meanwhile never shifts a page. Returns up to $filter->limit
     * rows, and whether at least one more matches.
     *
     * @param non-empty-list<string> $queues the queues the dashboard watches
     * @return array{rows: list<FailedRow>, hasMore: bool}
     */
    public function page(FailedJobFilter $filter, array $queues): array
    {
        $conditions = ['queue IN (' . implode(', ', array_fill(0, count($queues), '?')) . ')'];
        $params = $queues;

        if ($filter->queue !== null) {
            $conditions[] = 'queue = ?';
            $params[] = $filter->queue;
        }

        if ($filter->class !== null) {
            $conditions[] = 'class = ?';
            $params[] = $filter->class;
        }

        if ($filter->failedFrom !== null) {
            $conditions[] = 'failed_at >= ?';
            $params[] = $filter->failedFrom;
        }

        if ($filter->failedTo !== null) {
            $conditions[] = 'failed_at <= ?';
            $params[] = $filter->failedTo;
        }

        if ($filter->after !== null) {
            $conditions[] = '(failed_at < ? OR (failed_at = ? AND id < ?))';
            array_push($params, $filter->after['failedAt'], $filter->after['failedAt'], $filter->after['id']);
        }

        // the limit is an int bounded by FailedJobFilter, inlined as the store inlines its own
        $fetch = $filter->limit + 1;
        $sql = 'SELECT id, queue_connection, queue, class, attempts, max_attempts, exception_class, exception_message, failed_at FROM '
            . self::TABLE . ' WHERE ' . implode(' AND ', $conditions)
            . " ORDER BY failed_at DESC, id DESC LIMIT {$fetch}";

        $rows = [];

        foreach ($this->db->execute($sql, $params) as $row) {
            $rows[] = self::row($row);
        }

        $hasMore = count($rows) > $filter->limit;

        return ['rows' => array_slice($rows, 0, $filter->limit), 'hasMore' => $hasMore];
    }

    /**
     * @param non-empty-list<string> $queues
     * @return array<string, int> keyed by queue name, every one of $queues present
     */
    public function failedCounts(array $queues): array
    {
        $counts = array_fill_keys($queues, 0);
        $sql = 'SELECT queue, COUNT(*) AS failed FROM ' . self::TABLE
            . ' WHERE queue IN (' . implode(', ', array_fill(0, count($queues), '?')) . ') GROUP BY queue';

        foreach ($this->db->execute($sql, $queues) as $row) {
            $counts[(string) $row['queue']] = (int) $row['failed'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     * @return FailedRow
     */
    private static function row(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'connection' => $row['queue_connection'] === null ? null : (string) $row['queue_connection'],
            'queue' => (string) $row['queue'],
            'class' => (string) $row['class'],
            'attempts' => (int) $row['attempts'],
            'maxAttempts' => $row['max_attempts'] === null ? null : (int) $row['max_attempts'],
            'exceptionClass' => (string) $row['exception_class'],
            'exceptionMessage' => (string) $row['exception_message'],
            'failedAt' => (int) $row['failed_at'],
        ];
    }
}
