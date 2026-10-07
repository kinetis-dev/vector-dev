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
            CREATE TABLE customers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                name VARCHAR(120) NOT NULL,
                phone VARCHAR(40) NULL,
                website VARCHAR(255) NULL,
                birth_date DATE NULL,
                notes TEXT NULL,
                created_at DATETIME(6) NOT NULL,
                UNIQUE KEY customers_email_unique (email)
            )
            SQL);
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE customers');
    }
};
