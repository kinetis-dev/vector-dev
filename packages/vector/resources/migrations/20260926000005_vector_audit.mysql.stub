<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector:install`. The audit trail: one row per
// record and event with the field-level changes the writer observed,
// and the impersonator when the actor was impersonating someone.
return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                resource VARCHAR(120) NOT NULL,
                record_id VARCHAR(191) NOT NULL,
                actor_id VARCHAR(191) NOT NULL,
                impersonator_id VARCHAR(191) NULL,
                event VARCHAR(80) NOT NULL,
                changes JSON NOT NULL,
                created_at DATETIME(6) NOT NULL,
                KEY vector_audit_record_index (resource, record_id, id),
                KEY vector_audit_actor_index (actor_id, created_at)
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_audit');
    }
};
