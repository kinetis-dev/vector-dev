<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;

/**
 * The demo's queue dashboard: anyone Vector admits may look, and only an
 * admin — DemoActor::$isAdmin, set from the users table's is_admin column
 * — may retry, forget, prune or clear.
 */
final readonly class DemoQueueDashboardPolicy implements QueueDashboardPolicyInterface
{
    #[\Override]
    public function view(ActorContext $actor): bool
    {
        return true;
    }

    #[\Override]
    public function retry(ActorContext $actor): bool
    {
        return self::isAdmin($actor);
    }

    #[\Override]
    public function forget(ActorContext $actor): bool
    {
        return self::isAdmin($actor);
    }

    #[\Override]
    public function prune(ActorContext $actor): bool
    {
        return self::isAdmin($actor);
    }

    #[\Override]
    public function clear(ActorContext $actor, string $queue): bool
    {
        return self::isAdmin($actor);
    }

    private static function isAdmin(ActorContext $actor): bool
    {
        return $actor->user instanceof DemoActor && $actor->user->isAdmin;
    }
}
