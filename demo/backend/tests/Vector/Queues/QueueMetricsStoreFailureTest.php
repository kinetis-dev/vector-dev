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
use Kinetis\Queue\Events\JobSucceeded;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueWorker;
use Kinetis\Vector\Queues\Metrics\JobMetricsStore;
use Kinetis\Vector\Queues\Metrics\MetricsFailureThrottle;
use Kinetis\Vector\Queues\Metrics\RecordJobMetrics;

/**
 * A metrics database that refuses every write: the listener swallows
 * the failure, logs it as a warning — once a minute per worker — and
 * every job still settles exactly as it would have.
 */
final class QueueMetricsStoreFailureTest extends MetricsTestCase
{
    private RecordingLink $down;

    private RecordingLogger $logger;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->down = new RecordingLink();
        $this->logger = new RecordingLogger();
        $app->instance(JobMetricsStore::class, new JobMetricsStore($this->down));
        $app->instance(\Psr\Log\LoggerInterface::class, $this->logger);
    }

    public function testTheListenerSwallowsAndLogsAFailedWrite(): void
    {
        $scope = $this->app->createRequestScope();
        $listener = $scope->get(RecordJobMetrics::class);
        self::assertInstanceOf(RecordJobMetrics::class, $listener);

        // returns normally: nothing reaches the dispatcher, so nothing reaches the worker
        $listener->succeeded(new JobSucceeded(OtherJob::class, 'qa-mail', 1, 5.0));
        $scope->dispose();

        self::assertCount(1, $this->down->statements, 'the write was attempted');
        $warnings = $this->logger->at('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Connection refused', $warnings[0]['message']);
        self::assertSame(['class' => OtherJob::class, 'queue' => 'qa-mail'], $warnings[0]['context']['job']);
    }

    public function testEveryJobSettlesAsItWouldHaveAndTheFailureIsLoggedOnceAMinute(): void
    {
        $queue = new ScriptedQueue([
            new QueuedJob(OtherJob::class, ['report' => 'q3'], 'h1', 'qa-mail'),
            new QueuedJob(FailingJob::class, ['reason' => 'flaky'], 'h2', 'qa-mail', attempts: 1, maxAttempts: 3),
            new QueuedJob(FailingJob::class, ['reason' => 'broken'], 'h3', 'qa-mail', attempts: 3, maxAttempts: 3),
            new QueuedJob(OtherJob::class, ['report' => 'q4'], 'h4', 'qa-mail'),
        ]);
        $worker = new QueueWorker($this->app, $queue);

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($worker->processNext(0, ['qa-mail']));
        }

        // a minute on, the next failure is logged again
        $this->now += MetricsFailureThrottle::INTERVAL_SECONDS;
        self::assertTrue($worker->processNext(0, ['qa-mail']));

        self::assertSame(['ack', 'release', 'fail', 'ack'], $queue->settled);
        self::assertCount(4, $this->down->statements, 'every outcome was attempted');
        self::assertCount(2, $this->logger->at('warning'));

        // and nothing reached the worker's own net for a throwing listener, which logs
        // "A <Event> listener failed for job \"<class>\": <message>" at error level (kinetis/queue
        // v1.9.0 QueueWorker::dispatch() and runBestEffort())
        foreach ($this->logger->at('error') as $line) {
            self::assertDoesNotMatchRegularExpression('/^A Job(Succeeded|Released|FailedPermanently) listener failed for job "/', $line['message']);
        }
    }
}
