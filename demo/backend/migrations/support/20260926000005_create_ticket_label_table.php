<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// The support desk, on the PostgreSQL connection "support".
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE ticket_label (
                ticket_id BIGINT NOT NULL REFERENCES tickets (id) ON DELETE CASCADE,
                label_id BIGINT NOT NULL REFERENCES labels (id) ON DELETE CASCADE,
                PRIMARY KEY (ticket_id, label_id)
            )
            SQL);
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE ticket_label');
    }
};
