<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector:install`. Typed settings an admin edits
// in Vector's UI: one row per group and key, the value as JSON.
return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_settings (
                `group` VARCHAR(80) NOT NULL,
                `key` VARCHAR(80) NOT NULL,
                value JSON NOT NULL,
                updated_by VARCHAR(191) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                PRIMARY KEY (`group`, `key`)
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_settings');
    }
};
