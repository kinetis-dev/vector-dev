<?php

declare(strict_types=1);

namespace Kinetis\Vector\Jobs;

use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\ActorResolverInterface;
use Kinetis\Vector\Auth\VectorAccessInterface;
use Kinetis\Vector\Store\JobStore;
use Kinetis\Vector\Store\NotificationStore;

/**
 * What every Vector job does first: rebuild the actor on the job's own
 * request scope so the same services the controllers use — policies,
 * the writer, the reader — answer for that admin, and mark the job row
 * running. The worker gives each job a fresh scope, so registering the
 * identity here leaks into nothing else.
 */
final readonly class JobContext
{
    public function __construct(
        private RequestScope $scope,
        private ActorResolverInterface $actors,
        private VectorAccessInterface $access,
        public JobStore $jobs,
        public NotificationStore $notifications,
    ) {}

    /** @throws \RuntimeException when the actor no longer exists or lost admin access */
    public function actAs(string $actorId): CurrentUserInterface
    {
        $user = $this->actors->resolve($actorId);

        if ($user === null || !$this->access->canAccessVector($user)) {
            throw new \RuntimeException("Actor {$actorId} cannot run this job: unknown, or no longer admitted to the admin.");
        }

        $this->scope->instance(CurrentUserInterface::class, $user);

        return $user;
    }

    /** A service built on the job's scope after actAs() registered the identity. */
    public function get(string $class): object
    {
        $service = $this->scope->get($class);

        return is_object($service) ? $service : throw new \LogicException("{$class} did not resolve to an object.");
    }

    /** Runs a job body against its row: running → done, or failed with the message, and a notification either way. */
    public function run(int $jobId, string $actorId, string $title, \Closure $body): void
    {
        $this->jobs->start($jobId);

        try {
            $user = $this->actAs($actorId);
            /** @var array{result: array<string, mixed>, processed?: int, file?: string|null, fileName?: string|null, fileType?: string|null, notice?: string} $outcome */
            $outcome = $body($user);
            $this->jobs->finish($jobId, $outcome['result'], $outcome['processed'] ?? null, $outcome['file'] ?? null, $outcome['fileName'] ?? null, $outcome['fileType'] ?? null);
            $this->notifications->notify($actorId, $title, $outcome['notice'] ?? null, "/jobs/{$jobId}", 'success');
        } catch (\Throwable $e) {
            $this->jobs->fail($jobId, $e->getMessage());
            $this->notifications->notify($actorId, "{$title} failed", $e->getMessage(), "/jobs/{$jobId}", 'error');

            throw $e;
        }
    }
}
