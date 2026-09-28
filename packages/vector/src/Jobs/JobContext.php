<?php

declare(strict_types=1);

namespace Kinetis\Vector\Jobs;

use Kinetis\Authorization\Exception\AuthorizationException;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Auth\ActorResolverInterface;
use Kinetis\Vector\Auth\VectorAccessInterface;
use Kinetis\Vector\Exception\VectorException;
use Kinetis\Vector\Store\JobStore;
use Kinetis\Vector\Store\NotificationStore;
use Psr\Log\LoggerInterface;

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
        private LoggerInterface $log,
    ) {}

    /**
     * @param string|null $impersonatorId who was impersonating the actor when the job was started: the worker's log and audit rows name them, as the request's did
     * @throws JobFailedException when the actor no longer exists or lost admin access
     */
    public function actAs(string $actorId, ?string $impersonatorId = null): CurrentUserInterface
    {
        $user = $this->actors->resolve($actorId);

        if ($user === null || !$this->access->canAccessVector($user)) {
            throw new JobFailedException("Actor {$actorId} cannot run this job: unknown, or no longer admitted to the admin.");
        }

        $this->scope->instance(CurrentUserInterface::class, $user);
        $this->scope->instance(ActorContext::class, new ActorContext($user, $impersonatorId));

        return $user;
    }

    /** A service built on the job's scope after actAs() registered the identity. */
    public function get(string $class): object
    {
        $service = $this->scope->get($class);

        return is_object($service) ? $service : throw new \LogicException("{$class} did not resolve to an object.");
    }

    /**
     * Runs a job body against its row: running → done, or failed with the
     * message, and a notification either way. A row that is not queued —
     * a redelivery of a job that already ran, or was already marked
     * failed — is left alone: every Vector job runs at most once.
     */
    public function run(int $jobId, string $actorId, string $title, \Closure $body): void
    {
        if (!$this->jobs->start($jobId)) {
            return;
        }

        try {
            $user = $this->actAs($actorId, $this->jobs->find($jobId)['impersonatorId'] ?? null);
            /** @var array{result: array<string, mixed>, processed?: int, file?: string|null, fileName?: string|null, fileType?: string|null, notice?: string} $outcome */
            $outcome = $body($user);
            $this->jobs->finish($jobId, $outcome['result'], $outcome['processed'] ?? null, $outcome['file'] ?? null, $outcome['fileName'] ?? null, $outcome['fileType'] ?? null);
            $this->notifications->notify($actorId, $title, $outcome['notice'] ?? null, "/jobs/{$jobId}", 'success');
        } catch (\Throwable $e) {
            $failure = self::reportable($e);
            $this->jobs->fail($jobId, $failure);
            $this->notifications->notify($actorId, "{$title} failed", $failure, "/jobs/{$jobId}", 'error');

            throw $e;
        }
    }

    /**
     * What the job row and the notification say about a failure: a
     * message Vector or the framework wrote for the admin, verbatim; any
     * other exception — a driver's, a library's — carries connection
     * details and SQL the admin should not read, so those go to the log
     * and the row gets a generic line.
     */
    private function reportable(\Throwable $e): string
    {
        if ($e instanceof VectorException || $e instanceof ValidationException || $e instanceof AuthorizationException || $e instanceof \LengthException) {
            return $e->getMessage();
        }

        $this->log->error('A Vector job failed: ' . $e->getMessage(), ['exception' => $e]);

        return 'The job failed unexpectedly; the server log has the details.';
    }
}
