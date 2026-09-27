<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\AuthJwt\Exception\RefreshTokenUnavailableException;
use Kinetis\AuthJwt\RefreshTokenStore;
use Kinetis\Security\AttemptThrottle;
use Kinetis\Security\Exception\AttemptThrottleUnavailableException;
use Psr\SimpleCache\CacheInterface;

/**
 * Brute-force protection for both sign-ins, and the refresh tokens the
 * API issues, on the application cache (Redis). Authentication is the
 * application's in Vector's design; this is the demo's worked example.
 *
 * Failures are never counted per e-mail alone: anyone could then lock
 * a victim out by typing their address with wrong passwords. They are
 * counted per e-mail *from one client address* — five in fifteen
 * minutes lock that pair, so an attacker locks only themselves while the
 * account's owner signs in from their own address — and per client
 * address, more loosely (twenty), so one address cannot work through
 * many accounts, yet one office behind a shared address is not locked
 * out by a few typos. A success clears both. The throttles are built on
 * use, not at boot: without Redis the cache stores nothing, both refuse
 * it, and signing in answers 503 instead of the application failing to
 * start.
 */
final readonly class LoginAttempts
{
    public function __construct(private CacheInterface $cache) {}

    /**
     * Seconds until another attempt is allowed; 0 when it is now.
     *
     * @throws LoginUnavailableException
     */
    public function lockedFor(string $email, string $address): int
    {
        [$byAccount, $byAddress] = $this->throttles();
        $wait = 0;

        foreach ([[$byAccount, self::account($email, $address)], [$byAddress, $address]] as [$throttle, $identifier]) {
            if ($throttle->tooManyAttempts($identifier)) {
                $wait = max($wait, $throttle->availableInSeconds($identifier), 1);
            }
        }

        return $wait;
    }

    /** @throws LoginUnavailableException */
    public function failed(string $email, string $address): void
    {
        [$byAccount, $byAddress] = $this->throttles();
        $byAccount->recordFailure(self::account($email, $address));
        $byAddress->recordFailure($address);
    }

    /** @throws LoginUnavailableException */
    public function succeeded(string $email, string $address): void
    {
        [$byAccount, $byAddress] = $this->throttles();
        $byAccount->clear(self::account($email, $address));
        $byAddress->clear($address);
    }

    /** @throws LoginUnavailableException */
    public function refreshTokens(): RefreshTokenStore
    {
        try {
            return new RefreshTokenStore($this->cache);
        } catch (RefreshTokenUnavailableException $e) {
            throw LoginUnavailableException::because($e);
        }
    }

    /**
     * @return array{AttemptThrottle, AttemptThrottle}
     * @throws LoginUnavailableException
     */
    private function throttles(): array
    {
        try {
            return [
                new AttemptThrottle($this->cache, 5, 900, namespace: 'login'),
                new AttemptThrottle($this->cache, 20, 900, namespace: 'login-address'),
            ];
        } catch (AttemptThrottleUnavailableException $e) {
            throw LoginUnavailableException::because($e);
        }
    }

    /** One e-mail from one address, unambiguously: neither part's content can shift into the other. */
    private static function account(string $email, string $address): string
    {
        return json_encode([mb_strtolower(trim($email)), $address], JSON_THROW_ON_ERROR);
    }
}
