<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\User;
use Kinetis\AuthJwt\Exception\RefreshTokenUnavailableException;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\Responses\JsonResponse;
use Kinetis\Http\Responses\PlainTextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The API's sign-in, for the standalone admin: a fifteen-minute access
 * token and a single-use refresh token that renews both. Failed attempts
 * are throttled per e-mail and per client address (LoginAttempts); a
 * refresh reloads the user, so a changed role takes effect at the next
 * renewal; logout revokes the session's whole token family.
 *
 * A redeemed refresh token presented again is a replay: one of the two
 * holders is not the owner. It revokes the family (RefreshTokenFamilies),
 * so every token rotated from that sign-in stops renewing, and it answers
 * exactly what an unknown token does.
 *
 * A session has an absolute end: twelve hours after the sign-in. A
 * refresh hands out a refresh token that expires when the one it
 * replaced would have, never twelve hours from now, so a tab that keeps
 * renewing itself still has to sign in again by then.
 */
final readonly class LoginController
{
    public const int ACCESS_TTL_SECONDS = 900;
    public const int REFRESH_TTL_SECONDS = 43_200;

    public function __construct(
        private JwtIssuer $issuer,
        private Credentials $credentials,
        private LoginAttempts $attempts,
        private RefreshTokenFamilies $families,
    ) {}

    #[Post('/auth/login')]
    public function login(#[Body] LoginRequest $data, ServerRequestInterface $request): ResponseInterface
    {
        $address = self::address($request);

        try {
            $wait = $this->attempts->lockedFor($data->email, $address);

            if ($wait > 0) {
                return ErrorResponse::create(429, sprintf('Too many failed sign-ins. Try again in %d minutes.', (int) ceil($wait / 60)), ['Retry-After' => (string) $wait]);
            }

            $user = $this->credentials->verify($data->email, $data->password);

            if ($user === null) {
                $this->attempts->failed($data->email, $address);

                return ErrorResponse::create(401, 'Invalid credentials.');
            }

            $this->attempts->succeeded($data->email, $address);

            return $this->tokens($user);
        } catch (LoginUnavailableException $e) {
            return ErrorResponse::create(503, $e->getMessage());
        }
    }

    /** Redeems a refresh token — once — for a new pair; a second time revokes its whole family. */
    #[Post('/auth/refresh')]
    public function refresh(#[Body] RefreshRequest $data): ResponseInterface
    {
        try {
            $redeemed = $this->attempts->refreshTokens()->redeem($data->refreshToken);

            if ($redeemed === null) {
                $this->revokeFamilyOfRedeemed($data->refreshToken);

                return self::invalidRefreshToken();
            }

            $endsAt = $redeemed['claims']['sessionEndsAt'] ?? null;
            $family = $redeemed['claims']['family'] ?? null;

            if (!is_int($endsAt) || !is_string($family)) {
                return self::invalidRefreshToken();
            }

            // remembered before anything else is decided, so a replay of this token is caught whatever follows
            $this->families->redeemed($data->refreshToken, $family, $endsAt);

            if ($this->families->isRevoked($family)) {
                return self::invalidRefreshToken();
            }

            $user = $this->credentials->find($redeemed['subject']);

            if ($user === null) {
                return self::invalidRefreshToken();
            }

            if ($endsAt <= time()) {
                return ErrorResponse::create(401, 'The session has reached its end; sign in again.');
            }

            return $this->tokens($user, $endsAt, $family);
        } catch (LoginUnavailableException $e) {
            return ErrorResponse::create(503, $e->getMessage());
        } catch (RefreshTokenUnavailableException) {
            return ErrorResponse::create(503, 'The session could not be renewed; try again.');
        }
    }

    /**
     * Signs this session out: no refresh token of its family renews anything any more. The access
     * token runs out on its own.
     */
    #[Post('/auth/logout')]
    public function logout(#[Body] RefreshRequest $data): ResponseInterface
    {
        try {
            $redeemed = $this->attempts->refreshTokens()->redeem($data->refreshToken);
            $endsAt = $redeemed['claims']['sessionEndsAt'] ?? null;
            $family = $redeemed['claims']['family'] ?? null;

            if (is_int($endsAt) && is_string($family)) {
                $this->families->revoke($family, $endsAt);
            } elseif ($redeemed === null) {
                $this->revokeFamilyOfRedeemed($data->refreshToken);
            }
        } catch (LoginUnavailableException $e) {
            return ErrorResponse::create(503, $e->getMessage());
        } catch (RefreshTokenUnavailableException) {
            return ErrorResponse::create(503, 'The refresh token could not be revoked; try again.');
        }

        return PlainTextResponse::create('', 204, ['Cache-Control' => 'no-store']);
    }

    /**
     * @param int|null $sessionEndsAt when the session ends, as a Unix timestamp; null starts a new session now
     * @param string|null $family the session's token family; null starts a new one
     * @throws LoginUnavailableException
     */
    private function tokens(User $user, ?int $sessionEndsAt = null, ?string $family = null): ResponseInterface
    {
        $claims = ['name' => $user->name(), 'vector' => $user->isAdmin()];
        $endsAt = $sessionEndsAt ?? time() + self::REFRESH_TTL_SECONDS;
        $remaining = max(1, $endsAt - time());
        $family ??= $this->families->start();

        try {
            // the refresh token remembers the session's end and family, and lives no longer than the session
            $refresh = $this->attempts->refreshTokens()->issue((string) $user->id(), ['sessionEndsAt' => $endsAt, 'family' => $family], ttlSeconds: $remaining);
        } catch (RefreshTokenUnavailableException) {
            // never stored: hand out no token that cannot be redeemed
            return ErrorResponse::create(503, 'The session could not be stored; try again.');
        }

        return JsonResponse::create([
            'accessToken' => $this->issuer->issue((string) $user->id(), $claims, ttlSeconds: self::ACCESS_TTL_SECONDS),
            'tokenType' => 'Bearer',
            'expiresIn' => self::ACCESS_TTL_SECONDS,
            'refreshToken' => $refresh,
            'refreshExpiresIn' => $remaining,
            'user' => ['id' => $user->id(), 'name' => $user->name(), 'email' => $user->email()],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * A token the store no longer holds: when it was redeemed before, this is a replay, and its family is revoked.
     *
     * @throws RefreshTokenUnavailableException
     */
    private function revokeFamilyOfRedeemed(#[\SensitiveParameter] string $token): void
    {
        $used = $this->families->redeemedFamily($token);

        if ($used !== null) {
            $this->families->revoke($used['family'], $used['sessionEndsAt']);
        }
    }

    /** One answer for every refused token — unknown, expired, replayed or revoked — so none of them is told apart. */
    private static function invalidRefreshToken(): ResponseInterface
    {
        return ErrorResponse::create(401, 'Invalid or expired refresh token.');
    }

    /** The client's address as this worker sees it: behind the demo's nginx, the connecting peer. */
    public static function address(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) && $address !== '' ? $address : 'unknown';
    }
}
