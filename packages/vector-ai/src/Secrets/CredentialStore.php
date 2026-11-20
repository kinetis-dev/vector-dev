<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Secrets;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Config\ProviderConfig;
use Kinetis\VectorAi\Config\ProviderType;

/**
 * `vector_ai_credentials`: the provider keys administrators entered,
 * sealed by SecretBox for their provider, one row per provider, on
 * Vector's own connection.
 * The ciphertext and the nonce are stored base64-encoded: the database
 * drivers bind parameters as text, which raw bytes would not survive.
 *
 * status() says what is set and by whom, and never the key; key() opens
 * one for the resolver and nothing else; stored() says whether there is
 * one to open, without opening it. Built per request.
 */
final readonly class CredentialStore
{
    private const string TABLE = 'vector_ai_credentials';

    public function __construct(
        private VectorDatabase $db,
        private SecretBox $box,
        private AiConfig $config,
    ) {}

    /**
     * What each configured provider's key is, as the credentials endpoint
     * answers: set or not, managed by the environment or an administrator,
     * who stored it and when. Never the key.
     *
     * @return array<string, array<string, mixed>> provider => status, in VECTOR_AI_PROVIDERS order
     */
    public function status(): array
    {
        $rows = [];

        foreach ($this->query()->select('provider', 'key_id', 'updated_by', 'updated_at')->get() as $row) {
            $rows[(string) $row['provider']] = $row;
        }

        $status = [];

        foreach ($this->config->providers as $name => $provider) {
            $status[$name] = $this->statusOf($provider, $rows[$name] ?? null);
        }

        return $status;
    }

    /** @return array<string, mixed> one provider's status */
    public function statusFor(ProviderConfig $provider): array
    {
        $row = $this->query()->select('provider', 'key_id', 'updated_by', 'updated_at')->where('provider', '=', $provider->name)->first();

        return $this->statusOf($provider, is_array($row) ? $row : null);
    }

    /**
     * Whether an administrator may store a key for $provider: the type
     * takes one and the environment does not already give it.
     */
    public static function storable(ProviderConfig $provider): bool
    {
        return $provider->type->takesKey() && !$provider->keyFromEnvironment();
    }

    /** Seals $key and stores it for $provider, replacing the one stored. */
    public function put(ProviderConfig $provider, #[\SensitiveParameter] string $key, string $actorId): void
    {
        // sealed for this provider: copied into another provider's row, it does not open
        $sealed = $this->box->seal($key, $provider->name);
        $row = [
            'provider' => $provider->name,
            'ciphertext' => base64_encode($sealed->ciphertext),
            'nonce' => base64_encode($sealed->nonce),
            'key_id' => $sealed->keyId,
            'updated_by' => $actorId,
            'updated_at' => self::now(),
        ];

        $this->query()->upsert([$row], ['provider'], ['ciphertext', 'nonce', 'key_id', 'updated_by', 'updated_at']);
    }

    /** Removes the key stored for $provider; whether there was one. */
    public function delete(ProviderConfig $provider): bool
    {
        return $this->query()->where('provider', '=', $provider->name)->delete() > 0;
    }

    /**
     * The key an administrator stored for $provider, opened, or null when
     * none is stored, it was sealed with another key, or it does not open
     * (altered, or sealed for another provider).
     */
    public function key(ProviderConfig $provider): ?string
    {
        $row = $this->query()->select('ciphertext', 'nonce', 'key_id')->where('provider', '=', $provider->name)->first();

        if (!is_array($row)) {
            return null;
        }

        $ciphertext = base64_decode((string) $row['ciphertext'], true);
        $nonce = base64_decode((string) $row['nonce'], true);

        if ($ciphertext === false || $nonce === false) {
            return null;
        }

        try {
            return $this->box->open(new Sealed($ciphertext, $nonce, (string) $row['key_id']), $provider->name);
        } catch (SealedWithAnotherKeyException | \UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Whether a key is stored for $provider that key() would open, as far
     * as can be told without opening it: a row, well-formed, sealed under
     * VECTOR_AI_KEY as it is now. Nothing is decrypted; a row altered in
     * the database passes here and fails key().
     */
    public function stored(ProviderConfig $provider): bool
    {
        $row = $this->query()->select('nonce', 'key_id')->where('provider', '=', $provider->name)->first();

        if (!is_array($row) || !$this->box->canSeal() || !hash_equals((string) $this->box->keyId(), (string) $row['key_id'])) {
            return false;
        }

        $nonce = base64_decode((string) $row['nonce'], true);

        return $nonce !== false && strlen($nonce) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function statusOf(ProviderConfig $provider, ?array $row): array
    {
        if ($provider->keyFromEnvironment()) {
            return ['set' => true, 'managedBy' => 'environment', 'storable' => false];
        }

        if ($provider->type === ProviderType::Bedrock) {
            // signed with the AWS default credential chain, never a key here
            return ['set' => false, 'managedBy' => 'environment', 'storable' => false];
        }

        if (!$provider->type->takesKey()) {
            return ['set' => false, 'managedBy' => null, 'storable' => false];
        }

        $status = ['set' => $row !== null, 'managedBy' => 'admin', 'storable' => $this->box->canSeal()];

        if ($row !== null) {
            $status['updatedAt'] = (new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
            $status['updatedBy'] = (string) $row['updated_by'];

            if (!$this->box->canSeal()) {
                // stored, but nothing opens it until the operator sets VECTOR_AI_KEY; re-entering it is refused
                $status['problem'] = 'Set VECTOR_AI_KEY to use the stored key.';
            } elseif ($row['key_id'] !== $this->box->keyId()) {
                $status['sealedWithAnotherKey'] = true;
                $status['problem'] = (new SealedWithAnotherKeyException())->getMessage();
            }
        }

        return $status;
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function query(): Query
    {
        return $this->db->query()->table(self::TABLE);
    }
}
