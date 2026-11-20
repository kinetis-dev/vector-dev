<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector-ai:install` for a PostgreSQL connection.
// The changes the assistant proposed, one row each, until a person
// approves or rejects them: the arguments the approval executes are this
// row's, never the request's. The id is 128 random bits in base32 (`apr_`
// + id is what the model and the admin see). The actor index serves the
// lookup an approval makes, and the sweep `vector-ai:approvals:prune`
// makes of stale rows. Postgres has no inline INDEX clause inside CREATE
// TABLE.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_ai_approvals (
                id CHAR(26) NOT NULL PRIMARY KEY,
                actor_id VARCHAR(191) NOT NULL,
                impersonator_id VARCHAR(191) NULL,
                conversation_id VARCHAR(64) NOT NULL,
                tool VARCHAR(60) NOT NULL,
                arguments JSON NOT NULL,
                arguments_hash CHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                result JSON NULL,
                created_at TIMESTAMP(6) NOT NULL,
                expires_at TIMESTAMP(6) NOT NULL,
                decided_at TIMESTAMP(6) NULL,
                executed_at TIMESTAMP(6) NULL
            )
            SQL);

        $db->execute('CREATE INDEX vector_ai_approvals_actor_index ON vector_ai_approvals (actor_id, status, expires_at)');
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_ai_approvals');
    }
};
