<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Auth;

use Kinetis\Vector\Auth\ActorContext;

/**
 * Who may see and change the queue dashboard. Asked only for a request
 * Vector's own `vector` middleware group already admitted, so the actor
 * is always an authenticated user VectorAccessInterface let in; a false
 * answer is a 403.
 *
 * The package binds ViewOnlyQueueDashboardPolicy on AppScope; an
 * application binds its own implementation in bootstrap.php, which runs
 * later and wins. One instance answers for every request a worker
 * handles, so an implementation holds no request state.
 */
interface QueueDashboardPolicyInterface
{
    /** The overview, the failed list, a failed job's detail and the job metrics. */
    public function view(ActorContext $actor): bool;

    /** Pushing failed jobs back onto their queue. */
    public function retry(ActorContext $actor): bool;

    /** Deleting one failed-job record without retrying it. */
    public function forget(ActorContext $actor): bool;

    /** Deleting every failed-job record older than an age. */
    public function prune(ActorContext $actor): bool;

    /** Discarding every job waiting on $queue. */
    public function clear(ActorContext $actor, string $queue): bool;
}
