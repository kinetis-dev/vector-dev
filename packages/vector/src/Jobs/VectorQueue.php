<?php

declare(strict_types=1);

namespace Kinetis\Vector\Jobs;

use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Container\AppScope;
use Kinetis\Queue\DisposableQueueInterface;
use Kinetis\Queue\Job;
use Kinetis\Queue\QueueContract;
use Kinetis\Queue\QueueFactory;
use Kinetis\Queue\QueueInterface;
use Kinetis\Queue\Exception\QueueUnavailableException;
use Kinetis\Vector\Auth\ActorResolverInterface;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Exception\VectorException;
use Kinetis\Vector\Store\JobStore;

/**
 * Where Vector's background jobs go: the queue name `VECTOR_QUEUE`
 * (default `default`), on the application's own `QueueInterface`, or on
 * the named queue connection `VECTOR_QUEUE_CONNECTION` when set. A named
 * connection is built by kinetis/queue's own `QueueFactory`: its backend
 * is `QUEUE_<NAME>_CONNECTION` — redis, sql, sqs or rabbitmq — and it
 * reads that backend's keys under the same name (`REDIS_<NAME>_*`,
 * `DB_<NAME>_*`, …). A worker serves it with
 *
 *     kinetis queue:work --connection=<name> --queue=<VECTOR_QUEUE>
 *
 * One instance per worker, bound on AppScope. The backend is built once,
 * on the first push, and disposed with the application scope; the
 * application's own `QueueInterface` stays the application's. Building
 * may open a connection, so availability reads configuration only, and a
 * backend the queue package refuses to build refuses the push with the
 * queue package's own reason.
 */
final class VectorQueue
{
    /** kinetis/queue's queue-name grammar: letters, digits, hyphens and underscores, 1–80 characters. */
    private const string QUEUE_NAME = '/^[A-Za-z0-9_-]{1,80}$/D';

    private ?QueueInterface $built = null;

    private function __construct(
        private readonly AppScope $app,
        private readonly Config $config,
        public readonly string $queue,
        public readonly ?string $connection,
    ) {}

    public static function fromConfig(AppScope $app, Config $config): self
    {
        $queue = trim($config->string('VECTOR_QUEUE', 'default'));
        $queue = $queue === '' ? 'default' : $queue;

        if (preg_match(self::QUEUE_NAME, $queue) !== 1) {
            throw new \InvalidArgumentException("VECTOR_QUEUE \"{$queue}\" is not a queue name: letters, digits, hyphens and underscores, up to 80 characters.");
        }

        $connection = trim($config->string('VECTOR_QUEUE_CONNECTION', ''));

        if ($connection === '') {
            return new self($app, $config, $queue, null);
        }

        QueueContract::assertValidConnectionName($connection, 'VECTOR_QUEUE_CONNECTION');

        return new self($app, $config, $queue, $connection);
    }

    /**
     * Why Vector cannot queue, or null when it can: a backend to push to
     * and an ActorResolverInterface to rebuild the admin in the worker.
     * Read from configuration alone; nothing is built or opened.
     */
    public function unavailableReason(): ?string
    {
        if (!$this->app->has(ActorResolverInterface::class)) {
            return 'Background jobs need an ActorResolverInterface bound by the application.';
        }

        if ($this->connection === null) {
            return $this->app->has(QueueInterface::class) ? null : 'Background jobs need a queue: set QUEUE_CONNECTION, or VECTOR_QUEUE_CONNECTION for a queue of Vector\'s own.';
        }

        $key = $this->typeKey();

        return $this->config->get($key) === null
            ? "VECTOR_QUEUE_CONNECTION names the queue connection \"{$this->connection}\", but {$key} is not set."
            : null;
    }

    public function available(): bool
    {
        return $this->unavailableReason() === null;
    }

    /**
     * Pushes a Vector job onto Vector's queue name, on Vector's backend,
     * for exactly one attempt: an import re-run would create every row
     * again, an export or action re-run would repeat work a failed row
     * already reported, so a failure is final whatever the worker's own
     * default. The job body must still tolerate a redelivery a backend
     * makes on its own (a connection drop), which JobContext refuses.
     */
    public function push(Job $job): void
    {
        $reason = $this->unavailableReason();

        if ($reason !== null) {
            throw new ForbiddenOperationException($reason);
        }

        $this->backend()->push($job, queue: $this->queue, maxAttempts: 1);
    }

    /**
     * Pushes the job a row was created for; a push that fails — a backend
     * the queue package refuses to build, a connection it cannot open —
     * marks the row failed rather than leaving it queued forever, then
     * rethrows so the request answers with the reason.
     */
    public function enqueue(JobStore $jobs, int $jobId, Job $job): void
    {
        try {
            $this->push($job);
        } catch (\Throwable $e) {
            // a backend's own message names hosts and ports: the row gets Vector's reasons only, the log the rest
            $jobs->fail($jobId, $e instanceof VectorException ? $e->getMessage() : JobContext::UNEXPECTED);

            throw $e;
        }
    }

    /** The backend type of a named connection, for display: `sql`, `redis`, …; null on the application's queue. */
    public function type(): ?string
    {
        return $this->connection === null ? null : $this->config->get($this->typeKey());
    }

    private function backend(): QueueInterface
    {
        if ($this->connection === null) {
            $queue = $this->app->get(QueueInterface::class);

            return $queue instanceof QueueInterface ? $queue : throw new \LogicException('QueueInterface did not resolve to a queue.');
        }

        if ($this->built !== null) {
            return $this->built;
        }

        try {
            $queue = QueueFactory::fromConfig($this->config, $this->connection);
        } catch (MissingConfigException | QueueUnavailableException | \InvalidArgumentException $e) {
            // a backend type it does not know, a package not installed, a key the backend requires
            throw new ForbiddenOperationException($e->getMessage(), previous: $e);
        }

        if ($queue instanceof DisposableQueueInterface) {
            $this->app->onDispose($queue->dispose(...));
        }

        return $this->built = $queue;
    }

    private function typeKey(): string
    {
        return Config::scopedKey('QUEUE_CONNECTION', (string) $this->connection);
    }
}
