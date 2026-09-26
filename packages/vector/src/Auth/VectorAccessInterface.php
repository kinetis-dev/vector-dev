<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

use Kinetis\Http\CurrentUserInterface;

/**
 * Decides whether an authenticated user may enter the admin at all. The
 * application binds one implementation on AppScope; it must hold no
 * request state, because one instance answers for every request a
 * worker handles.
 *
 * The check receives whatever object the application's authentication
 * middleware registered as CurrentUserInterface — a JwtUser, a session
 * user — so an application reads a claim, a column or a role from it
 * however it stores that fact.
 */
interface VectorAccessInterface
{
    public function canAccessVector(CurrentUserInterface $user): bool;
}
