<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector:install`. One row per background job an
// admin started — a queued action, an export, an import — with its
// progress, its outcome and, for an export or a failed-rows file, the
// file itself (bounded by MEDIUMBLOB: 16 MiB).
return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                kind VARCHAR(20) NOT NULL,
                resource VARCHAR(120) NOT NULL,
                name VARCHAR(120) NOT NULL,
                actor_id VARCHAR(191) NOT NULL,
                status VARCHAR(20) NOT NULL,
                total INT UNSIGNED NULL,
                processed INT UNSIGNED NOT NULL DEFAULT 0,
                result JSON NULL,
                failure TEXT NULL,
                file MEDIUMBLOB NULL,
                file_name VARCHAR(255) NULL,
                file_type VARCHAR(120) NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                finished_at DATETIME(6) NULL,
                KEY vector_jobs_actor_index (actor_id, created_at)
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_jobs');
    }
};
