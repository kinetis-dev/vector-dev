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
            CREATE TABLE orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                number VARCHAR(30) NOT NULL,
                customer_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL,
                total_cents INT NOT NULL DEFAULT 0,
                currency CHAR(3) NOT NULL,
                placed_at DATETIME(6) NOT NULL,
                shipped_at DATETIME(6) NULL,
                notes TEXT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                version BIGINT NOT NULL DEFAULT 1,
                UNIQUE KEY orders_number_unique (number),
                KEY orders_customer_id_index (customer_id),
                KEY orders_status_index (status),
                KEY orders_placed_at_index (placed_at),
                CONSTRAINT orders_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES customers (id)
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE orders');
    }
};
