<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector:install` for a PostgreSQL connection.
// Per-user UI preferences the client wants back on another device, as
// JSON values by key.
return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_preferences (
                owner_id VARCHAR(191) NOT NULL,
                "key" VARCHAR(80) NOT NULL,
                value JSON NOT NULL,
                updated_at TIMESTAMP(6) NOT NULL,
                PRIMARY KEY (owner_id, "key")
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_preferences');
    }
};
