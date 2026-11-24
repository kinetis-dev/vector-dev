<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Two more ways a customer may be contacted, beside newsletter: the three
// bool columns a columns-mode flag group edits together.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            ALTER TABLE customer_profiles
                ADD COLUMN accepts_sms TINYINT(1) NOT NULL DEFAULT 0 AFTER newsletter,
                ADD COLUMN accepts_post TINYINT(1) NOT NULL DEFAULT 0 AFTER accepts_sms
            SQL);
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE customer_profiles DROP COLUMN accepts_post, DROP COLUMN accepts_sms');
    }
};
