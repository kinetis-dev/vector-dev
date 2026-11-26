<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// The free-text keywords of a ticket: a tag field's jsonb list of strings,
// which the "any" and "all" filters match with ?| and @>.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE tickets ADD COLUMN keywords jsonb NULL');
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE tickets DROP COLUMN keywords');
    }
};
