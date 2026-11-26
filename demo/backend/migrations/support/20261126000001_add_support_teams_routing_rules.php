<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// support_teams.routing_rules, a code field's YAML: which tickets the team
// picks up first and when they escalate. Plain TEXT on either database.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE support_teams ADD COLUMN routing_rules TEXT NULL');
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE support_teams DROP COLUMN routing_rules');
    }
};
