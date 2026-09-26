<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector:install`. Every write an admin performs
// through Vector is one row here: who (and as whom), what, which
// records, when.
return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_action_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_id VARCHAR(191) NOT NULL,
                impersonator_id VARCHAR(191) NULL,
                resource VARCHAR(120) NOT NULL,
                action VARCHAR(60) NOT NULL,
                record_ids JSON NOT NULL,
                created_at DATETIME(6) NOT NULL,
                KEY vector_action_events_resource_index (resource, created_at),
                KEY vector_action_events_actor_index (actor_id, created_at)
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_action_events');
    }
};
