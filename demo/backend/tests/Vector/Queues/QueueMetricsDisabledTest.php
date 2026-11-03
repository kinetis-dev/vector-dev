<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\FailingJob;
use App\Tests\Vector\Queues\Fixtures\OtherJob;
use App\Tests\Vector\Queues\Fixtures\RecordingLink;
use App\Tests\Vector\Queues\Fixtures\RecordingLogger;
use App\Tests\Vector\Queues\Fixtures\ScriptedQueue;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Queue\Events\JobFailedPermanently;
use Kinetis\Queue\Events\JobReleased;
use Kinetis\Queue\Events\JobSucceeded;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueWorker;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Queues\Metrics\JobMetricsStore;
use Kinetis\Vector\Queues\QueueDashboard;
use Psr\Log\LoggerInterface;

/**
 * VECTOR_QUEUE_METRICS=false: the dashboard is there, the metrics are
 * not. The listener writes nothing and logs nothing, and never builds the
 * metrics store — whose factory here counts its calls and builds a real
 * store on Vector's connection, or one on a database that is down — and
 * /vector/queues/metrics is the Kernel's own 404 for an actor who may
 * view, before the query is validated.
 */
final class QueueMetricsDisabledTest extends MetricsTestCase
{
    private int $storesBuilt = 0;

    /** Whether the store's factory builds it on a database that refuses every statement, as a missing table would. */
    private bool $broken = false;

    private RecordingLogger $logger;

    /** @return array<string, string> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'VECTOR_QUEUE_METRICS' => 'false'];
    }

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->logger = new RecordingLogger();
        $app->instance(LoggerInterface::class, $this->logger);
        $app->bind(JobMetricsStore::class, function (AppScope $app): JobMetricsStore {
            $this->storesBuilt++;

            if ($this->broken) {
                return new JobMetricsStore(new RecordingLink());
            }

            $vector = $app->get(VectorDatabase::class);
            self::assertInstanceOf(VectorDatabase::class, $vector);

            return new JobMetricsStore($vector->link());
        });
    }

    public function testTheFlagIsReadAtBoot(): void
    {
        $dashboard = $this->app->get(QueueDashboard::class);
        self::assertInstanceOf(QueueDashboard::class, $dashboard);
        self::assertFalse($dashboard->metricsEnabled());
    }

    public function testNoOutcomeIsRecordedAndTheStoreIsNeverBuilt(): void
    {
        $this->dispatch(new JobSucceeded(OtherJob::class, 'qa-mail', 1, 12.5));
        $this->dispatch(new JobReleased(OtherJob::class, 'qa-mail', 1, new \RuntimeException('again'), 7.25));
        $this->dispatch(new JobFailedPermanently(OtherJob::class, 'qa-mail', 3, new \RuntimeException('gave up'), [], null, 40.0));

        self::assertSame([], $this->rows());
        self::assertSame(0, $this->storesBuilt);
        self::assertSame([], $this->logger->at('warning'));
    }

    /** End to end: the worker settles a success and a permanent failure exactly as it would, and nothing is counted. */
    public function testTheWorkersOwnEventsAreNotRecorded(): void
    {
        $queue = new ScriptedQueue([
            new QueuedJob(OtherJob::class, ['report' => 'q3'], 'h1', 'qa-mail'),
            new QueuedJob(FailingJob::class, ['reason' => 'broken'], 'h2', 'qa-mail', attempts: 3, maxAttempts: 3),
        ]);
        $worker = new QueueWorker($this->app, $queue);

        self::assertTrue($worker->processNext(0, ['qa-mail']));
        self::assertTrue($worker->processNext(0, ['qa-mail']));

        self::assertSame(['ack', 'fail'], $queue->settled);
        self::assertSame([], $this->rows());
        self::assertSame(0, $this->storesBuilt);
        self::assertSame([], $this->logger->at('warning'));
    }

    /** A metrics database that is down — or a table that is missing — goes unnoticed: nothing asks it anything. */
    public function testABrokenMetricsDatabaseLogsNoWarning(): void
    {
        $this->broken = true;

        $this->dispatch(new JobSucceeded(OtherJob::class, 'qa-mail', 1, 5.0));
        $this->dispatch(new JobFailedPermanently(OtherJob::class, 'qa-mail', 3, new \RuntimeException('gave up'), [], null, 40.0));

        self::assertSame(0, $this->storesBuilt);
        self::assertSame([], $this->logger->at('warning'));
    }

    public function testTheMetricsAreA404ForAnActorWhoMayViewBeforeTheQueryIsValidated(): void
    {
        $headers = $this->adminHeaders();

        // the dashboard itself is there for this actor
        $this->client->get('/vector/queues', headers: $headers)->assertOk();

        $this->client->get('/vector/queues/metrics', headers: $headers)->assertNotFound();
        $this->client->get('/vector/queues/metrics', ['queue' => 'qa-mail'], $headers)->assertNotFound();
        // a bad window would be a 422 with metrics on
        $this->client->get('/vector/queues/metrics', ['from' => 'yesterday'], $headers)->assertNotFound();
        $this->client->get('/vector/queues/metrics', ['queue' => 'qa-elsewhere'], $headers)->assertNotFound();

        self::assertSame(0, $this->storesBuilt);
    }

    public function testTheSchemaHasQueuesButNoMetrics(): void
    {
        $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()
            ->assertJsonPath('capabilities.queues', true)
            ->assertJsonPath('capabilities.queueMetrics', false);
    }
}
