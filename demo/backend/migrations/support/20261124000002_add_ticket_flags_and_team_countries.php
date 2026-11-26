<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// tickets.flags, a JSON-mode flag group's object of option key to bool,
// which the "any" and "all" filters match with @>; and
// support_teams.countries, a multiple country field's list of codes.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE tickets ADD COLUMN flags jsonb NULL');
        $db->execute('ALTER TABLE support_teams ADD COLUMN countries jsonb NULL');
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE support_teams DROP COLUMN countries');
        $db->execute('ALTER TABLE tickets DROP COLUMN flags');
    }
};
