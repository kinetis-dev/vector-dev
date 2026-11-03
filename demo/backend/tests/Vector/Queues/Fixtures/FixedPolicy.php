<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues\Fixtures;

use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;

/** A policy that answers the same for every actor: $view for view(), $mutate for every mutation. */
final readonly class FixedPolicy implements QueueDashboardPolicyInterface
{
    public function __construct(private bool $view, private bool $mutate) {}

    public function view(ActorContext $actor): bool
    {
        return $this->view;
    }

    public function retry(ActorContext $actor): bool
    {
        return $this->mutate;
    }

    public function forget(ActorContext $actor): bool
    {
        return $this->mutate;
    }

    public function prune(ActorContext $actor): bool
    {
        return $this->mutate;
    }

    public function clear(ActorContext $actor, string $queue): bool
    {
        return $this->mutate;
    }
}
