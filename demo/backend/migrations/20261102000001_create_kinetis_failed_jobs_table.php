<?php

declare(strict_types=1);

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Migrations\Migration;

// The demo's failed-jobs table, kinetis/queue-failed-jobs' own stub, on the
// default connection (MySQL and PostgreSQL).
// Required by Kinetis\QueueFailedJobs\SqlFailedJobStore.
//
// `id`, `queue_connection` and `queue` are ascii_bin: MySQL's default
// collation compares case-insensitively, and all three are matched for
// exact equality. `failed_at` is unix seconds. Postgres compares
// byte-exactly already — see the .pgsql. sibling stub.
//
// `args` holds each job's payload in full, #[Sensitive] values included.
// Give this table the access controls the queue's own storage has. It is
// MEDIUMTEXT because TEXT stops at 64 KiB, below what SQS, Redis and
// RabbitMQ admit, and a record the table cannot hold stops every worker
// that pops the job.

return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE kinetis_failed_jobs (
                id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                queue_connection VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
                queue VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                class VARCHAR(255) NOT NULL,
                args MEDIUMTEXT NOT NULL,
                max_attempts INT UNSIGNED NULL,
                attempts INT UNSIGNED NOT NULL,
                exception_class VARCHAR(255) NOT NULL,
                exception_message TEXT NOT NULL,
                failed_at BIGINT NOT NULL,
                INDEX kinetis_failed_jobs_failed_at_id_index (failed_at, id)
            )
            SQL);
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE kinetis_failed_jobs');
    }
};
