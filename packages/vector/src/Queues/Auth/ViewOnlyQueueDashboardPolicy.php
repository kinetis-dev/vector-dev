<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Auth;

use Kinetis\Vector\Auth\ActorContext;

/**
 * Vector's default: anyone Vector admits may look, and nobody may
 * retry, forget, prune or clear until the application binds a policy
 * that says who may.
 */
final readonly class ViewOnlyQueueDashboardPolicy implements QueueDashboardPolicyInterface
{
    #[\Override]
    public function view(ActorContext $actor): bool
    {
        return true;
    }

    #[\Override]
    public function retry(ActorContext $actor): bool
    {
        return false;
    }

    #[\Override]
    public function forget(ActorContext $actor): bool
    {
        return false;
    }

    #[\Override]
    public function prune(ActorContext $actor): bool
    {
        return false;
    }

    #[\Override]
    public function clear(ActorContext $actor, string $queue): bool
    {
        return false;
    }
}
