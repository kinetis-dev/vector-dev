<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE products (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sku VARCHAR(40) NOT NULL,
                name VARCHAR(200) NOT NULL,
                slug VARCHAR(220) NOT NULL,
                description TEXT NULL,
                status VARCHAR(20) NOT NULL,
                price_cents INT NOT NULL,
                currency CHAR(3) NOT NULL,
                stock INT NOT NULL DEFAULT 0,
                weight_kg DECIMAL(8, 3) NOT NULL DEFAULT 0,
                is_featured TINYINT(1) NOT NULL DEFAULT 0,
                attributes JSON NULL,
                image_key VARCHAR(255) NULL,
                released_at DATETIME(6) NULL,
                category_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                deleted_at DATETIME(6) NULL,
                version BIGINT NOT NULL DEFAULT 1,
                UNIQUE KEY products_sku_unique (sku),
                UNIQUE KEY products_slug_unique (slug),
                KEY products_status_index (status),
                KEY products_category_id_index (category_id),
                KEY products_deleted_at_index (deleted_at),
                CONSTRAINT products_category_id_foreign FOREIGN KEY (category_id) REFERENCES categories (id)
            )
            SQL);
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE products');
    }
};
