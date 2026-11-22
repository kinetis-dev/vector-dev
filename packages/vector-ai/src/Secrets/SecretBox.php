<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Secrets;

use Kinetis\Config\Config;

/**
 * Seals the provider keys administrators enter, with libsodium's AEAD
 * XChaCha20-Poly1305 (IETF) under VECTOR_AI_KEY (32 bytes, given as
 * `base64:<…>`) and a random 24-byte nonce for every write. Without
 * VECTOR_AI_KEY it seals nothing: storing a key is refused.
 *
 * Every value is sealed for a context, authenticated with it as
 * additional data and needed to open it: CredentialStore passes the
 * provider's name, so one provider's sealed key, copied into another
 * provider's row, does not open there.
 *
 * A sealed value carries the id of the key that sealed it, the first 8
 * hex characters of the key's sha256, so a value sealed under a key that
 * has since changed is told apart from a tampered one. Bound on AppScope:
 * immutable, request-neutral, and it never shows the key, in a message or
 * a dump.
 */
final readonly class SecretBox
{
    public const string CONFIG_KEY = 'VECTOR_AI_KEY';

    private function __construct(#[\SensitiveParameter] private ?string $key) {}

    /**
     * @throws \InvalidArgumentException naming VECTOR_AI_KEY when it is set but not 32 bytes in base64
     */
    public static function fromConfig(Config $config): self
    {
        $raw = trim($config->string(self::CONFIG_KEY, ''));

        if ($raw === '') {
            return new self(null);
        }

        $decoded = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : false;

        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \InvalidArgumentException(self::CONFIG_KEY . ' must be base64:<32 random bytes in base64>, such as `php -r \'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;\'` prints.');
        }

        return new self($decoded);
    }

    /** A box sealing under $key, 32 raw bytes. */
    public static function withKey(#[\SensitiveParameter] string $key): self
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \InvalidArgumentException('A SecretBox key is 32 bytes.');
        }

        return new self($key);
    }

    /** The box without a key: it seals and opens nothing. */
    public static function none(): self
    {
        return new self(null);
    }

    /** Whether VECTOR_AI_KEY is set, so a key may be stored. */
    public function canSeal(): bool
    {
        return $this->key !== null;
    }

    /** The id of VECTOR_AI_KEY: the first 8 hex characters of its sha256; null without one. */
    public function keyId(): ?string
    {
        return $this->key === null ? null : substr(hash('sha256', $this->key), 0, 8);
    }

    /**
     * Seals $plaintext for $context (a provider's name): only open() with
     * the same context opens it.
     *
     * @throws \LogicException without VECTOR_AI_KEY
     */
    public function seal(#[\SensitiveParameter] string $plaintext, string $context): Sealed
    {
        if ($this->key === null) {
            throw new \LogicException('Set ' . self::CONFIG_KEY . ' to store provider keys.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return new Sealed(sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::additionalData($context), $nonce, $this->key), $nonce, (string) $this->keyId());
    }

    /**
     * @throws SealedWithAnotherKeyException when $sealed was sealed under another key, or none is set
     * @throws \UnexpectedValueException when it does not open: altered, malformed, or sealed for another context
     */
    public function open(Sealed $sealed, string $context): string
    {
        if ($this->key === null || !hash_equals((string) $this->keyId(), $sealed->keyId)) {
            throw new SealedWithAnotherKeyException();
        }

        if (strlen($sealed->nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new \UnexpectedValueException('A sealed provider key is malformed.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($sealed->ciphertext, self::additionalData($context), $sealed->nonce, $this->key);

        if ($plaintext === false) {
            throw new \UnexpectedValueException('A sealed provider key does not open: it was altered, or sealed for another provider.');
        }

        return $plaintext;
    }

    /** What a value is authenticated with besides its own bytes: its purpose and $context. */
    private static function additionalData(string $context): string
    {
        return 'kinetis/vector-ai provider key:' . $context;
    }

    /** @return array<string, mixed> never the key */
    public function __debugInfo(): array
    {
        return ['canSeal' => $this->canSeal(), 'keyId' => $this->keyId()];
    }
}
