<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\FailingJob;
use App\Tests\Vector\Queues\Fixtures\OtherJob;
use App\Tests\Vector\Queues\Fixtures\ScriptedQueue;
use App\Tests\Vector\Queues\Fixtures\SendInvoiceJob;
use Kinetis\Queue\Events\JobFailedPermanently;
use Kinetis\Queue\Events\JobReleased;
use Kinetis\Queue\Events\JobSucceeded;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueWorker;
use Kinetis\Vector\Queues\Metrics\JobOutcome;

/**
 * The discovered listener writes one row per (minute, queue, class),
 * through the same EventDispatcher the worker dispatches to.
 */
final class QueueMetricsRecordingTest extends MetricsTestCase
{
    public function testEachOutcomeIncrementsOnlyItsOwnCounterAndTheDurations(): void
    {
        $this->dispatch(new JobSucceeded(OtherJob::class, 'qa-mail', 1, 12.5));
        self::assertSame([self::row('qa-mail', OtherJob::class, succeeded: 1, total: 12.5, max: 12.5)], $this->rows());
        $this->deleteAndCheck();

        $this->dispatch(new JobReleased(OtherJob::class, 'qa-mail', 1, new \RuntimeException('again'), 7.25));
        self::assertSame([self::row('qa-mail', OtherJob::class, released: 1, total: 7.25, max: 7.25)], $this->rows());
        $this->deleteAndCheck();

        $this->dispatch(new JobFailedPermanently(OtherJob::class, 'qa-mail', 3, new \RuntimeException('gave up'), [], null, 40.0));
        self::assertSame([self::row('qa-mail', OtherJob::class, failed: 1, total: 40.0, max: 40.0)], $this->rows());
    }

    public function testEventsInOneMinuteMergeIntoOneRow(): void
    {
        $this->dispatch(new JobSucceeded(OtherJob::class, 'qa-mail', 1, 30.0));
        $this->now = self::MINUTE + 59;
        $this->dispatch(new JobSucceeded(OtherJob::class, 'qa-mail', 1, 10.0));
        $this->dispatch(new JobReleased(OtherJob::class, 'qa-mail', 1, new \RuntimeException('again'), 45.5));
        $this->now = self::MINUTE + 1;
        $this->dispatch(new JobFailedPermanently(OtherJob::class, 'qa-mail', 2, new \RuntimeException('gave up'), [], null, 4.5));

        // total the sum, max the largest, last the latest — not the last written
        self::assertSame(
            [self::row('qa-mail', OtherJob::class, succeeded: 2, released: 1, failed: 1, total: 90.0, max: 45.5, last: self::MINUTE + 59)],
            $this->rows(),
        );
    }

    public function testADifferentMinuteQueueOrClassIsADifferentRow(): void
    {
        $this->dispatch(new JobSucceeded(OtherJob::class, 'qa-mail', 1, 1.0));
        $this->dispatch(new JobSucceeded(OtherJob::class, 'qa-reports', 1, 2.0));
        $this->dispatch(new JobSucceeded(SendInvoiceJob::class, 'qa-mail', 1, 3.0));
        $this->now = self::MINUTE + 60;
        $this->dispatch(new JobSucceeded(OtherJob::class, 'qa-mail', 1, 4.0));

        self::assertSame([
            self::row('qa-mail', OtherJob::class, succeeded: 1, total: 1.0, max: 1.0),
            self::row('qa-mail', OtherJob::class, succeeded: 1, total: 4.0, max: 4.0, bucket: self::MINUTE + 60, last: self::MINUTE + 60),
            self::row('qa-mail', SendInvoiceJob::class, succeeded: 1, total: 3.0, max: 3.0),
            self::row('qa-reports', OtherJob::class, succeeded: 1, total: 2.0, max: 2.0),
        ], $this->rows());
    }

    /** A job class differing only in case is another class: the column compares byte-exactly. */
    public function testClassesAreMatchedExactly(): void
    {
        $this->metricsStore()->record('qa-mail', OtherJob::class, JobOutcome::Succeeded, 1.0, self::NOW);
        $this->metricsStore()->record('qa-mail', strtolower(OtherJob::class), JobOutcome::Succeeded, 1.0, self::NOW);

        self::assertCount(2, $this->rows());
    }

    /** End to end: the worker settles three real deliveries, and the listener it dispatches to counts each. */
    public function testTheWorkersOwnEventsAreRecorded(): void
    {
        $queue = new ScriptedQueue([
            new QueuedJob(OtherJob::class, ['report' => 'q3'], 'h1', 'qa-mail'),
            new QueuedJob(FailingJob::class, ['reason' => 'flaky'], 'h2', 'qa-mail', attempts: 1, maxAttempts: 3),
            new QueuedJob(FailingJob::class, ['reason' => 'broken'], 'h3', 'qa-mail', attempts: 3, maxAttempts: 3),
        ]);
        $worker = new QueueWorker($this->app, $queue);

        self::assertTrue($worker->processNext(0, ['qa-mail']));
        self::assertTrue($worker->processNext(0, ['qa-mail']));
        self::assertTrue($worker->processNext(0, ['qa-mail']));

        self::assertSame(['ack', 'release', 'fail'], $queue->settled);
        $rows = $this->rows();
        self::assertCount(2, $rows);
        self::assertSame([FailingJob::class, 0, 1, 1], [$rows[0]['class'], $rows[0]['succeeded'], $rows[0]['released'], $rows[0]['failed']]);
        self::assertSame([OtherJob::class, 1, 0, 0], [$rows[1]['class'], $rows[1]['succeeded'], $rows[1]['released'], $rows[1]['failed']]);
        self::assertSame(self::MINUTE, $rows[0]['bucket']);
        self::assertGreaterThan(0.0, $rows[1]['total']);
    }

    private function deleteAndCheck(): void
    {
        $this->deleteTestRows();
        self::assertSame([], $this->rows());
    }

    /** @return array{bucket: int, queue: string, class: string, succeeded: int, released: int, failed: int, total: float, max: float, last: int} */
    private static function row(string $queue, string $class, int $succeeded = 0, int $released = 0, int $failed = 0, float $total = 0.0, float $max = 0.0, int $bucket = self::MINUTE, int $last = self::NOW): array
    {
        return ['bucket' => $bucket, 'queue' => $queue, 'class' => $class, 'succeeded' => $succeeded, 'released' => $released, 'failed' => $failed, 'total' => $total, 'max' => $max, 'last' => $last];
    }
}
