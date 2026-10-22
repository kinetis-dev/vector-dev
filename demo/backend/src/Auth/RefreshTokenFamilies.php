<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\AuthJwt\Exception\RefreshTokenUnavailableException;
use Psr\SimpleCache\CacheException;
use Psr\SimpleCache\CacheInterface;

/**
 * Reuse detection for the API's refresh tokens, on the application cache
 * (Redis), as the OAuth 2.0 Security BCP describes it. A sign-in starts a
 * family; every refresh token rotated from it carries the family's id.
 * A redeemed token is remembered — by its hash, never the token itself —
 * until the session ends, so presenting it again is recognised as a
 * replay and revokes the whole family: whichever of the owner and a thief
 * renewed first, the other one's chain stops too.
 *
 * Fails closed: a cache that cannot be read or written throws
 * RefreshTokenUnavailableException, and the renewal is refused.
 */
final readonly class RefreshTokenFamilies
{
    public function __construct(private CacheInterface $cache) {}

    /** A new family, for a sign-in. */
    public function start(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Remembers that $token was redeemed, and in which family, until the session ends.
     *
     * @throws RefreshTokenUnavailableException
     */
    public function redeemed(#[\SensitiveParameter] string $token, string $family, int $sessionEndsAt): void
    {
        $this->write(self::usedKey($token), ['family' => $family, 'sessionEndsAt' => $sessionEndsAt], $sessionEndsAt);
    }

    /**
     * The family $token belonged to when it was redeemed, or null when it never was.
     *
     * @return array{family: string, sessionEndsAt: int}|null
     * @throws RefreshTokenUnavailableException
     */
    public function redeemedFamily(#[\SensitiveParameter] string $token): ?array
    {
        $used = $this->read(self::usedKey($token));

        if (!is_array($used) || !is_string($used['family'] ?? null) || !is_int($used['sessionEndsAt'] ?? null)) {
            return null;
        }

        return ['family' => $used['family'], 'sessionEndsAt' => $used['sessionEndsAt']];
    }

    /** @throws RefreshTokenUnavailableException */
    public function revoke(string $family, int $sessionEndsAt): void
    {
        $this->write(self::revokedKey($family), true, $sessionEndsAt);
    }

    /** @throws RefreshTokenUnavailableException */
    public function isRevoked(string $family): bool
    {
        return $this->read(self::revokedKey($family)) === true;
    }

    /** @throws RefreshTokenUnavailableException */
    private function write(string $key, mixed $value, int $sessionEndsAt): void
    {
        try {
            $stored = $this->cache->set($key, $value, max(1, $sessionEndsAt - time()));
        } catch (CacheException $e) {
            throw new RefreshTokenUnavailableException('The refresh token family could not be stored.', 0, $e);
        }

        if (!$stored) {
            throw new RefreshTokenUnavailableException('The refresh token family could not be stored.');
        }
    }

    /** @throws RefreshTokenUnavailableException */
    private function read(string $key): mixed
    {
        try {
            return $this->cache->get($key);
        } catch (CacheException $e) {
            throw new RefreshTokenUnavailableException('The refresh token family could not be read.', 0, $e);
        }
    }

    private static function usedKey(#[\SensitiveParameter] string $token): string
    {
        return 'jwt-refresh-used.' . hash('sha256', $token);
    }

    private static function revokedKey(string $family): string
    {
        return 'jwt-refresh-family-revoked.' . hash('sha256', $family);
    }
}
