<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// The index TicketResource::fullText() searches: the same to_tsvector()
// expression, configuration included, that the search builds.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute("CREATE INDEX tickets_subject_fulltext ON tickets USING GIN (to_tsvector('simple', coalesce(subject, '')))");
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP INDEX tickets_subject_fulltext');
    }
};
