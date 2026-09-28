<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\Vector\Auth\PasswordHasherInterface;

/**
 * The hash the demo's login verifies: PHP's default, the same call the
 * User entity makes when an account is created. Bound on AppScope; holds
 * nothing.
 */
final readonly class DemoPasswordHasher implements PasswordHasherInterface
{
    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
