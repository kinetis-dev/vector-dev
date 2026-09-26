<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE categories (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(140) NOT NULL,
                description TEXT NULL,
                position INT NOT NULL DEFAULT 0,
                parent_id BIGINT UNSIGNED NULL,
                UNIQUE KEY categories_slug_unique (slug),
                KEY categories_parent_id_index (parent_id),
                CONSTRAINT categories_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES categories (id)
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE categories');
    }
};
