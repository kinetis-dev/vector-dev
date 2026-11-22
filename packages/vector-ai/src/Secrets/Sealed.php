<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Secrets;

/** A secret as SecretBox sealed it: raw ciphertext (with its tag), its 24-byte nonce, and the id of the key that sealed it. */
final readonly class Sealed
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
        public string $keyId,
    ) {}
}
