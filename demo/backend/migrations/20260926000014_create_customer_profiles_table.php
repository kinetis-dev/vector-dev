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
            CREATE TABLE customer_profiles (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id BIGINT UNSIGNED NOT NULL,
                address_line VARCHAR(200) NOT NULL,
                city VARCHAR(120) NOT NULL,
                postal_code VARCHAR(20) NOT NULL,
                country CHAR(2) NOT NULL,
                newsletter TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY customer_profiles_customer_id_unique (customer_id),
                CONSTRAINT customer_profiles_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
            )
            SQL);
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE customer_profiles');
    }
};
