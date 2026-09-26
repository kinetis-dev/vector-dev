<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector:install`. The inbox: one row per message
// to one admin — a finished job, an action's notice — read or not.
return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_notifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner_id VARCHAR(191) NOT NULL,
                kind VARCHAR(20) NOT NULL,
                title VARCHAR(200) NOT NULL,
                body TEXT NULL,
                link VARCHAR(500) NULL,
                read_at DATETIME(6) NULL,
                created_at DATETIME(6) NOT NULL,
                KEY vector_notifications_owner_index (owner_id, read_at, id)
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_notifications');
    }
};
