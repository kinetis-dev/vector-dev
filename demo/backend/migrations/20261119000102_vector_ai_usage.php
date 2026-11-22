<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector-ai:install`. The usage ledger: one row
// per AI call, with the tokens its answer reported, or, for an assistant
// call that ended without reporting them (stopped when the browser left,
// or failed after streaming some text), an estimate, its outcome marked
// `:estimated`. The actor index serves the daily budget, which sums an
// actor's tokens since midnight (UTC).
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_ai_usage (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_id VARCHAR(191) NOT NULL,
                impersonator_id VARCHAR(191) NULL,
                feature VARCHAR(40) NOT NULL,
                provider VARCHAR(40) NOT NULL,
                model VARCHAR(191) NOT NULL,
                input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                tool_calls INT UNSIGNED NOT NULL DEFAULT 0,
                outcome VARCHAR(40) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                KEY vector_ai_usage_actor_index (actor_id, created_at)
            )
            SQL);
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_ai_usage');
    }
};
