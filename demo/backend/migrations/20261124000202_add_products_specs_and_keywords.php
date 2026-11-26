<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Two more JSON columns on products: specs, a JSON repeater's rows (label
// and value objects, in the order they were sorted), and keywords, a tag
// field's list of free-text strings, filtered by "any" or "all" of them.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE products ADD COLUMN specs JSON NULL AFTER channels, ADD COLUMN keywords JSON NULL AFTER specs');
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE products DROP COLUMN keywords, DROP COLUMN specs');
    }
};
