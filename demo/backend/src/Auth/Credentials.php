<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\User;
use Kinetis\Orm\EntityManager;

/**
 * Looks a user up by e-mail and checks the password. Request-scoped
 * through EntityManager, so it never outlives the request that used it.
 * An unknown e-mail still verifies against a hash, so the answer takes
 * the same time whether the account exists or not.
 */
final readonly class Credentials
{
    /** A hash of nothing anyone types, verified when no user matches. */
    private const string NOBODY = '$2y$12$JCS6ZLnPylNira9TpQfC3OXIndCNiulFltYb7OorU3FDlPYWET84G';

    public function __construct(private EntityManager $entities) {}

    public function verify(string $email, string $password): ?User
    {
        $user = $this->entities->repository(User::class)
            ->query()
            ->where('email', '=', $email)
            ->first();

        if ($user === null) {
            password_verify($password, self::NOBODY);

            return null;
        }

        return $user->verifyPassword($password) ? $user : null;
    }

    /** The user a refresh token names, as they are now: a changed name or role reaches the next access token. */
    public function find(string $id): ?User
    {
        return preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1
            ? $this->entities->repository(User::class)->query()->where('id', '=', (int) $id)->first()
            : null;
    }
}
