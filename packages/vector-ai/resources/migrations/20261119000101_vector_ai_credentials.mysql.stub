<?php

declare(strict_types=1);

use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

// Published by `kinetis vector-ai:install`. The provider keys
// administrators entered, sealed with VECTOR_AI_KEY (XChaCha20-Poly1305,
// for its provider): one row per provider. The ciphertext and the
// 24-byte nonce are stored base64-encoded, because the drivers bind
// parameters as text; key_id is the first 8 hex characters of the sha256
// of the key that sealed them.
return new class implements Migration
{
    #[\Override]
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE vector_ai_credentials (
                provider VARCHAR(40) NOT NULL PRIMARY KEY,
                ciphertext TEXT NOT NULL,
                nonce VARCHAR(32) NOT NULL,
                key_id VARCHAR(16) NOT NULL,
                updated_by VARCHAR(191) NOT NULL,
                updated_at DATETIME(6) NOT NULL
            )
            SQL);
    }

    #[\Override]
    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE vector_ai_credentials');
    }
};
