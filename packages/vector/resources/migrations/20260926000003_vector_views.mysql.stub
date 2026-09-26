<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector:install`. Saved list views: personal to
// their owner, or shared with every admin; one shared view per resource
// can be the default of everyone without one of their own.
return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_views (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                resource VARCHAR(120) NOT NULL,
                owner_id VARCHAR(191) NOT NULL,
                name VARCHAR(80) NOT NULL,
                shared TINYINT(1) NOT NULL DEFAULT 0,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                resource_default TINYINT(1) NOT NULL DEFAULT 0,
                state JSON NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                KEY vector_views_resource_index (resource, owner_id),
                KEY vector_views_shared_index (resource, shared)
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_views');
    }
};
