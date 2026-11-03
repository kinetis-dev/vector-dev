<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Metrics;

use Kinetis\Container\AppScope;
use Kinetis\Events\Listener;
use Kinetis\Queue\Events\JobFailedPermanently;
use Kinetis\Queue\Events\JobReleased;
use Kinetis\Queue\Events\JobSucceeded;
use Kinetis\Vector\Queues\QueueDashboard;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records every attempt kinetis/queue's worker settles — succeeded,
 * released for a retry, failed permanently — into its minute's row of
 * `vector_queue_metrics`, keyed by queue and job class (the events carry
 * no connection name). Discovered from its #[Listener] methods under the
 * `extra.kinetis.scan` root, Kinetis\Vector\ — nothing registers it.
 *
 * Metrics must never cost a job its outcome: the worker dispatches these
 * events only after the backend has settled the job, and a failed write
 * is caught here, logged as a warning at most once a minute per worker
 * ({@see MetricsFailureThrottle}), and never rethrown.
 *
 * VECTOR_QUEUE_METRICS off ({@see QueueDashboard::metricsEnabled()}),
 * every event returns before anything is resolved: no write, no metrics
 * store, no database link, no warning. That is why the store is
 * resolved from the application scope on each write rather than
 * injected — an injected one would be built, with its link, for every
 * job of an application that turned metrics off.
 *
 * Autowired per job scope, so an instance sees one job's events at most;
 * it keeps nothing from one event to the next anyway. Each event's
 * fields go straight into one upsert, and no job argument is read.
 */
final readonly class RecordJobMetrics
{
    public function __construct(
        private QueueDashboard $dashboard,
        private AppScope $app,
        private MetricsClock $clock,
        private MetricsFailureThrottle $throttle,
        private LoggerInterface $logger,
    ) {}

    #[Listener]
    public function succeeded(JobSucceeded $event): void
    {
        $this->record($event->queue, $event->class, JobOutcome::Succeeded, $event->durationMs);
    }

    #[Listener]
    public function released(JobReleased $event): void
    {
        $this->record($event->queue, $event->class, JobOutcome::Released, $event->durationMs);
    }

    #[Listener]
    public function failed(JobFailedPermanently $event): void
    {
        $this->record($event->queue, $event->class, JobOutcome::Failed, $event->durationMs);
    }

    private function record(string $queue, string $class, JobOutcome $outcome, float $durationMs): void
    {
        if (!$this->dashboard->metricsEnabled()) {
            return;
        }

        try {
            // shared for the worker's lifetime once built (QueueDashboard::register() binds it), so this is a lookup
            // after the first job; a store that cannot be built is a failed write like any other
            $store = $this->app->get(JobMetricsStore::class);

            if (!$store instanceof JobMetricsStore) {
                throw new \LogicException(JobMetricsStore::class . ' is bound to something else.');
            }

            $store->record($queue, $class, $outcome, $durationMs, $this->clock->now());
        } catch (Throwable $e) {
            $this->report($e, $queue, $class);
        }
    }

    private function report(Throwable $e, string $queue, string $class): void
    {
        try {
            if (!$this->throttle->allow($this->clock->now())) {
                return;
            }

            $this->logger->warning(
                'Recording queue metrics failed; the job itself is unaffected. Further failures in this worker are not logged for '
                . MetricsFailureThrottle::INTERVAL_SECONDS . " seconds: {$e->getMessage()}",
                ['exception' => $e, 'job' => ['class' => $class, 'queue' => $queue]],
            );
        } catch (Throwable) {
            // a logger that throws must not reach the worker either
        }
    }
}
