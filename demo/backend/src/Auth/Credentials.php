<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\User;
use Kinetis\Orm\EntityManager;

/**
 * Looks a user up by e-mail and checks the password. Request-scoped
 * through EntityManager, so it never outlives the request that used it.
 */
final readonly class Credentials
{
    public function __construct(private EntityManager $entities) {}

    public function verify(string $email, string $password): ?User
    {
        $user = $this->entities->repository(User::class)
            ->query()
            ->where('email', '=', $email)
            ->first();

        if ($user === null || !$user->verifyPassword($password)) {
            return null;
        }

        return $user;
    }
}
