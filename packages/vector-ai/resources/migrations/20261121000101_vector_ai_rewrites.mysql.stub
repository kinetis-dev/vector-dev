<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector-ai:install`. What a run of the bulk
// ProposeRewrites action made of each record, one row per (job, record):
// the proposed text, or why the record was skipped, failed or not sent.
// A chunk delivered again finds its records here and calls no provider
// for them, and the job's CSV is built from these rows. Building it
// deletes nothing: `vector-ai:approvals:prune` deletes the rows of every
// ended job, done included.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_ai_rewrites (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_id BIGINT NOT NULL,
                record_key VARCHAR(191) NOT NULL,
                status VARCHAR(16) NOT NULL,
                reason VARCHAR(40) NULL,
                proposal MEDIUMTEXT NULL,
                truncated SMALLINT NOT NULL DEFAULT 0,
                created_at DATETIME(6) NOT NULL,
                UNIQUE KEY vector_ai_rewrites_job_record_unique (job_id, record_key)
            )
            SQL);
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_ai_rewrites');
    }
};
