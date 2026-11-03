<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventDispatcher;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Queues\Metrics\JobMetricsStore;
use Kinetis\Vector\Queues\Metrics\MetricsClock;

/**
 * The job metrics over the demo's real `vector_queue_metrics` table — on
 * Vector's own connection (VECTOR_DB_CONNECTION), beside `vector_jobs` — on
 * the tests' own queue names, at a clock the test sets: NOW, half a
 * minute into its minute, unless a test moves it. Every row on the
 * tests' queues is deleted before and after each test.
 */
abstract class MetricsTestCase extends QueuesTestCase
{
    /** 2027-01-15T08:00:30Z: thirty seconds into the minute that begins at MINUTE. */
    protected const int NOW = 1_800_000_030;

    protected const int MINUTE = 1_800_000_000;

    protected int $now = self::NOW;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(MetricsClock::class, new MetricsClock(fn (): int => $this->now));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteTestRows();
    }

    protected function tearDown(): void
    {
        $this->deleteTestRows();
        parent::tearDown();
    }

    /** Dispatches $event the way the worker does: through a fresh scope's EventDispatcher, to every discovered listener. */
    protected function dispatch(object $event): void
    {
        $scope = $this->app->createRequestScope();

        try {
            $dispatcher = $scope->get(EventDispatcher::class);
            self::assertInstanceOf(EventDispatcher::class, $dispatcher);
            $dispatcher->dispatch($event);
        } finally {
            $scope->dispose();
        }
    }

    /**
     * The tests' rows, ordered by queue, class and minute.
     *
     * @return list<array{bucket: int, queue: string, class: string, succeeded: int, released: int, failed: int, total: float, max: float, last: int}>
     */
    protected function rows(): array
    {
        $rows = [];
        $sql = 'SELECT bucket, queue, job_class, succeeded, released, failed, total_duration_ms, max_duration_ms, last_processed_at FROM '
            . JobMetricsStore::TABLE . ' WHERE queue IN (?, ?, ?, ?) ORDER BY queue, job_class, bucket';

        foreach ($this->link()->execute($sql, [...self::QUEUES, 'qa-elsewhere']) as $row) {
            $rows[] = [
                'bucket' => (int) $row['bucket'],
                'queue' => (string) $row['queue'],
                'class' => (string) $row['job_class'],
                'succeeded' => (int) $row['succeeded'],
                'released' => (int) $row['released'],
                'failed' => (int) $row['failed'],
                'total' => (float) $row['total_duration_ms'],
                'max' => (float) $row['max_duration_ms'],
                'last' => (int) $row['last_processed_at'],
            ];
        }

        return $rows;
    }

    protected function metricsStore(): JobMetricsStore
    {
        $store = $this->app->get(JobMetricsStore::class);
        self::assertInstanceOf(JobMetricsStore::class, $store);

        return $store;
    }

    /** Vector's own connection, the one the metrics store is built on. */
    private function link(): \Kinetis\Persistence\Contract\SqlLink
    {
        $vector = $this->app->get(VectorDatabase::class);
        self::assertInstanceOf(VectorDatabase::class, $vector);

        return $vector->link();
    }

    protected function deleteTestRows(): void
    {
        $this->link()->execute('DELETE FROM ' . JobMetricsStore::TABLE . ' WHERE queue IN (?, ?, ?, ?)', [...self::QUEUES, 'qa-elsewhere']);
    }
}
