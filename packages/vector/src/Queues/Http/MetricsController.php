<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Http;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;
use Kinetis\Vector\Queues\Metrics\JobMetricsStore;
use Kinetis\Vector\Queues\Metrics\MetricsClock;
use Kinetis\Vector\Queues\Metrics\MetricsQuery;
use Kinetis\Vector\Queues\QueueDashboard;
use Kinetis\Vector\Queues\QueueNames;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The job metrics, in the same `vector` middleware group as
 * {@see QueuesController}, behind the same gate — the Kernel's own 404
 * unless a queue is configured and the policy's view() lets the actor
 * look — asked before anything else, and that same 404 when
 * VECTOR_QUEUE_METRICS turned the metrics off. They are Vector's own table, on
 * Vector's own connection, so they need nothing of
 * kinetis/queue-failed-jobs. Then the query is validated (an unwatched
 * queue or a bad window is a 422), and only then is the metrics store
 * resolved: a refused caller or a bad query never builds the store, nor
 * the database link under it. Only the
 * queues VECTOR_QUEUES names are read, and only the window asked for:
 * three grouped reads of `vector_queue_metrics`, each bounded by the
 * window's buckets.
 *
 * @phpstan-import-type Aggregate from JobMetricsStore
 */
#[RoutePrefix('/vector/queues')]
#[Middleware('@vector')]
final readonly class MetricsController
{
    public function __construct(
        private QueueNames $queues,
        private QueueDashboardPolicyInterface $policy,
        private ActorContext $actor,
        private MetricsClock $clock,
        private QueueDashboard $dashboard,
    ) {}

    /**
     * Query: `queue`, `class`, `from` and `to` (unix seconds; the last
     * 60 minutes by default, at most 7 days) — see {@see MetricsQuery}.
     *
     * @return array{data: array<string, mixed>}
     */
    #[Get('/metrics')]
    public function metrics(ServerRequestInterface $request, RequestScope $scope): array
    {
        if (!$this->dashboard->available($this->policy, $this->actor) || !$this->dashboard->metricsEnabled()) {
            throw QueueDashboard::notFound($request);
        }

        $query = MetricsQuery::fromQueryParams($request->getQueryParams(), $this->queues, $this->clock->now());

        // resolved here, not injected: a method parameter would be built before the policy is asked
        $store = $scope->get(JobMetricsStore::class);

        if (!$store instanceof JobMetricsStore) {
            throw new \LogicException(JobMetricsStore::class . ' is bound to something else.');
        }

        $perMinute = $this->read($store, $query, 'bucket');
        $buckets = [];

        for ($bucket = $query->from; $bucket <= $query->to; $bucket += JobMetricsStore::BUCKET_SECONDS) {
            $row = $perMinute[$bucket] ?? null;
            $buckets[] = ['bucket' => $bucket, ...self::counts($row)];
        }

        $queues = [];

        foreach ($this->read($store, $query, 'queue') as $queue => $row) {
            $queues[] = ['queue' => (string) $queue, ...self::totals($row)];
        }

        $classes = [];

        foreach ($this->read($store, $query, 'job_class') as $class => $row) {
            $classes[] = ['class' => (string) $class, ...self::totals($row)];
        }

        return ['data' => [
            'from' => $query->from,
            'to' => $query->to,
            'bucketSeconds' => JobMetricsStore::BUCKET_SECONDS,
            'buckets' => $buckets,
            'queues' => $queues,
            'classes' => $classes,
        ]];
    }

    /**
     * @param 'bucket'|'queue'|'job_class' $by
     * @return array<array-key, Aggregate>
     */
    private function read(JobMetricsStore $store, MetricsQuery $query, string $by): array
    {
        return $store->aggregate($by, $query->from, $query->to, $this->queues->names, $query->queue, $query->class);
    }

    /**
     * A minute's counts and durations; every number 0 for a minute no attempt ended in.
     *
     * @param Aggregate|null $row
     * @return array{succeeded: int, released: int, failed: int, averageDurationMs: float, maxDurationMs: float}
     */
    private static function counts(?array $row): array
    {
        if ($row === null) {
            return ['succeeded' => 0, 'released' => 0, 'failed' => 0, 'averageDurationMs' => 0.0, 'maxDurationMs' => 0.0];
        }

        $attempts = $row['succeeded'] + $row['released'] + $row['failed'];

        return [
            'succeeded' => $row['succeeded'],
            'released' => $row['released'],
            'failed' => $row['failed'],
            'averageDurationMs' => $attempts === 0 ? 0.0 : round($row['totalDurationMs'] / $attempts, 3),
            'maxDurationMs' => round($row['maxDurationMs'], 3),
        ];
    }

    /**
     * A queue's or a class's totals over the window. `failureRate` is the
     * share of finished jobs — succeeded or failed permanently — that
     * failed, null when none finished (only releases); a release is an
     * attempt, not an outcome. `lastProcessedAt` is unix seconds.
     *
     * @param Aggregate $row
     * @return array{succeeded: int, released: int, failed: int, failureRate: float|null, averageDurationMs: float, maxDurationMs: float, lastProcessedAt: int}
     */
    private static function totals(array $row): array
    {
        $finished = $row['succeeded'] + $row['failed'];

        return [
            ...self::counts($row),
            'failureRate' => $finished === 0 ? null : round($row['failed'] / $finished, 4),
            'lastProcessedAt' => $row['lastProcessedAt'],
        ];
    }
}
