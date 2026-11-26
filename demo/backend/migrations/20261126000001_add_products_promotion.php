<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// The two ends of a product's promotion, a date range field's days: both
// set or neither, the end never before the start. Plain DATE columns, the
// same statement on either database.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE products ADD COLUMN promotion_starts_on DATE NULL, ADD COLUMN promotion_ends_on DATE NULL');
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE products DROP COLUMN promotion_ends_on, DROP COLUMN promotion_starts_on');
    }
};
