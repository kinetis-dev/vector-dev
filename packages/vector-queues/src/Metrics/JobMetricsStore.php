<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Metrics;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

/**
 * `vector_queue_metrics`: one row per (minute bucket, queue, job class),
 * counting the attempts that succeeded, were released for a retry and
 * failed permanently, with their summed and longest handler durations.
 *
 * Every write is one atomic upsert — MySQL's `INSERT … ON DUPLICATE KEY
 * UPDATE`, Postgres' `INSERT … ON CONFLICT … DO UPDATE` — which adds to
 * the counters and keeps the larger maximum in the database itself, so
 * any number of workers, and of Fibers within one, write the same row
 * without reading it first and without losing an increment.
 *
 * Application-scoped and request-neutral: the only state is the link,
 * opened by {@see \Kinetis\Vector\Queues\PackageBootstrap} on the
 * connection kinetis/queue-failed-jobs records on.
 *
 * @phpstan-type Aggregate array{succeeded: int, released: int, failed: int, totalDurationMs: float, maxDurationMs: float, lastProcessedAt: int}
 */
final readonly class JobMetricsStore
{
    public const string TABLE = 'vector_queue_metrics';

    public const int BUCKET_SECONDS = 60;

    public function __construct(private MysqlLink|PostgresLink $db) {}

    /** The start of the minute $time falls in. */
    public static function bucket(int $time): int
    {
        return $time - (($time % self::BUCKET_SECONDS) + self::BUCKET_SECONDS) % self::BUCKET_SECONDS;
    }

    /** Adds one attempt that ended at $at to its minute's row, creating the row when it is the first. */
    public function record(string $queue, string $class, JobOutcome $outcome, float $durationMs, int $at): void
    {
        $durationMs = max(0.0, $durationMs);
        $params = [
            self::bucket($at),
            $queue,
            $class,
            $outcome === JobOutcome::Succeeded ? 1 : 0,
            $outcome === JobOutcome::Released ? 1 : 0,
            $outcome === JobOutcome::Failed ? 1 : 0,
            $durationMs,
            $durationMs,
            $at,
        ];
        $insert = 'INSERT INTO ' . self::TABLE
            . ' (bucket, queue, job_class, succeeded, released, failed, total_duration_ms, max_duration_ms, last_processed_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $t = self::TABLE;

        if ($this->db instanceof PostgresLink) {
            $this->db->execute($insert . ' ON CONFLICT (queue, job_class, bucket) DO UPDATE SET'
                . " succeeded = {$t}.succeeded + EXCLUDED.succeeded,"
                . " released = {$t}.released + EXCLUDED.released,"
                . " failed = {$t}.failed + EXCLUDED.failed,"
                . " total_duration_ms = {$t}.total_duration_ms + EXCLUDED.total_duration_ms,"
                . " max_duration_ms = GREATEST({$t}.max_duration_ms, EXCLUDED.max_duration_ms),"
                . " last_processed_at = GREATEST({$t}.last_processed_at, EXCLUDED.last_processed_at)", $params);

            return;
        }

        // the row alias (MySQL 8.0.19+) in place of VALUES(), which MySQL 8 deprecates in this clause; the
        // existing row's columns are qualified, as the alias' share their names
        $this->db->execute($insert . ' AS incoming ON DUPLICATE KEY UPDATE'
            . " succeeded = {$t}.succeeded + incoming.succeeded,"
            . " released = {$t}.released + incoming.released,"
            . " failed = {$t}.failed + incoming.failed,"
            . " total_duration_ms = {$t}.total_duration_ms + incoming.total_duration_ms,"
            . " max_duration_ms = GREATEST({$t}.max_duration_ms, incoming.max_duration_ms),"
            . " last_processed_at = GREATEST({$t}.last_processed_at, incoming.last_processed_at)", $params);
    }

    /**
     * Every row from bucket $from through bucket $to, both inclusive,
     * summed in SQL by $by: per minute (`bucket`), per `queue` or per
     * `job_class`. Only $queues are read; $queue and $class narrow it
     * further.
     *
     * @param 'bucket'|'queue'|'job_class' $by
     * @param non-empty-list<string> $queues
     * @return array<array-key, Aggregate> keyed by the grouped value
     */
    public function aggregate(string $by, int $from, int $to, array $queues, ?string $queue = null, ?string $class = null): array
    {
        $column = match ($by) {
            'bucket' => 'bucket',
            'queue' => 'queue',
            'job_class' => 'job_class',
        };
        $conditions = ['bucket >= ?', 'bucket <= ?', 'queue IN (' . implode(', ', array_fill(0, count($queues), '?')) . ')'];
        $params = [$from, $to, ...$queues];

        if ($queue !== null) {
            $conditions[] = 'queue = ?';
            $params[] = $queue;
        }

        if ($class !== null) {
            $conditions[] = 'job_class = ?';
            $params[] = $class;
        }

        $sql = "SELECT {$column} AS grouped, SUM(succeeded) AS succeeded, SUM(released) AS released, SUM(failed) AS failed,"
            . ' SUM(total_duration_ms) AS total_duration_ms, MAX(max_duration_ms) AS max_duration_ms, MAX(last_processed_at) AS last_processed_at'
            . ' FROM ' . self::TABLE . ' WHERE ' . implode(' AND ', $conditions) . " GROUP BY {$column} ORDER BY {$column}";

        $rows = [];

        foreach ($this->db->execute($sql, $params) as $row) {
            $key = $by === 'bucket' ? (int) $row['grouped'] : (string) $row['grouped'];
            $rows[$key] = [
                'succeeded' => (int) $row['succeeded'],
                'released' => (int) $row['released'],
                'failed' => (int) $row['failed'],
                'totalDurationMs' => (float) $row['total_duration_ms'],
                'maxDurationMs' => (float) $row['max_duration_ms'],
                'lastProcessedAt' => (int) $row['last_processed_at'],
            ];
        }

        return $rows;
    }

    /** Deletes every row whose minute began before $before, and returns how many it deleted. */
    public function pruneBefore(int $before): int
    {
        return $this->db->execute('DELETE FROM ' . self::TABLE . ' WHERE bucket < ?', [$before])->getRowCount() ?? 0;
    }
}
