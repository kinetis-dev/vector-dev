<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// The support desk's JSON columns as jsonb: tickets.metadata, a JSON field,
// converted in place, and tickets.platforms, a multiple select's list of
// option values, which the "any" and "all" filters match with ?| and @>.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE tickets ALTER COLUMN metadata TYPE jsonb USING metadata::jsonb');
        $db->execute('ALTER TABLE tickets ADD COLUMN platforms jsonb NULL');
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE tickets DROP COLUMN platforms');
        $db->execute('ALTER TABLE tickets ALTER COLUMN metadata TYPE json USING metadata::json');
    }
};
