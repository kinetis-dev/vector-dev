<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// A reply's free-form metadata: a jsonb document that keeps its objects,
// the JSON field of a relationship repeater's row.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE ticket_replies ADD COLUMN metadata jsonb NULL');
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE ticket_replies DROP COLUMN metadata');
    }
};
