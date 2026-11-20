<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector:install`. The channel a write came through
// when it was not the admin's own hand (ActorContext::$origin, such as
// `assistant`), on the audit trail and the action log; null otherwise.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE vector_audit ADD COLUMN origin VARCHAR(40) NULL');
        $db->execute('ALTER TABLE vector_action_events ADD COLUMN origin VARCHAR(40) NULL');
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('ALTER TABLE vector_action_events DROP COLUMN origin');
        $db->execute('ALTER TABLE vector_audit DROP COLUMN origin');
    }
};
