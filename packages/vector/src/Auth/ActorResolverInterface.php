<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

use Kinetis\Http\CurrentUserInterface;

/**
 * Rebuilds the identity a background job runs as. A job carries only the
 * actor's id; in the worker there is no request, so the application
 * turns that id back into the user object its policies expect. Bound on
 * AppScope by the application; without it, nothing is queued.
 */
interface ActorResolverInterface
{
    public function resolve(string $actorId): ?CurrentUserInterface;
}
