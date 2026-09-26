<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\VectorAccessInterface;

/**
 * The demo admits a user to Vector when the identity its middleware
 * built says so — from the token's `vector` claim in bearer mode, from
 * the session in cookie mode, from the users table in a worker — all
 * set from the user's is_admin column, so no query runs per request.
 */
final readonly class DemoVectorAccess implements VectorAccessInterface
{
    public function canAccessVector(CurrentUserInterface $user): bool
    {
        return $user instanceof DemoActor && $user->isAdmin;
    }
}
