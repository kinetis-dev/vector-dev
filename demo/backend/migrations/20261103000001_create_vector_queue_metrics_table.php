<?php

declare(strict_types=1);

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Migrations\Migration;

// MySQL version (8.0.19 or later). Copy this file into your own
// migrations/ directory (see kinetis/migrations) — migrations/<name>/ when
// FAILED_JOBS_DB_CONNECTION_NAME selects a named connection, since the
// metrics live beside the failed jobs — renaming it with a timestamp
// prefix, e.g. 20261103000001_create_vector_queue_metrics_table.php.
// Required by Kinetis\Vector\Queues\Metrics\JobMetricsStore.
//
// One row per (minute, queue, job class). `bucket` and
// `last_processed_at` are unix seconds, `bucket` floored to its minute.
// Both key columns are matched for exact equality, and MySQL's default
// collation compares case-insensitively, so neither uses it: `queue` is
// ascii_bin, as in kinetis/queue-failed-jobs' stub — kinetis/queue's
// queue-name grammar is ASCII — and `job_class` is VARBINARY, compared
// and stored as bytes, with no character set to validate the name
// against. (The failed-jobs stub's own `class VARCHAR(255) NOT NULL` takes
// the table's default, case-insensitive collation; this column keys a
// unique index, where two classes differing only in case must stay two
// rows.) Postgres compares byte-exactly already — see the .pgsql. sibling
// stub.
//
// The unique key is what every write upserts on, and serves the reads
// narrowed to a queue or a class; the bucket index serves the unfiltered
// window reads and the prune.

return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_queue_metrics (
                bucket BIGINT NOT NULL,
                queue VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                job_class VARBINARY(255) NOT NULL,
                succeeded INT UNSIGNED NOT NULL DEFAULT 0,
                released INT UNSIGNED NOT NULL DEFAULT 0,
                failed INT UNSIGNED NOT NULL DEFAULT 0,
                total_duration_ms DOUBLE NOT NULL DEFAULT 0,
                max_duration_ms DOUBLE NOT NULL DEFAULT 0,
                last_processed_at BIGINT NOT NULL,
                UNIQUE KEY vector_queue_metrics_queue_class_bucket_unique (queue, job_class, bucket),
                INDEX vector_queue_metrics_bucket_index (bucket)
            )
            SQL);
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_queue_metrics');
    }
};
