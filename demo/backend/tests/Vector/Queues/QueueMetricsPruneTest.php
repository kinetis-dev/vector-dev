<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\OtherJob;
use Kinetis\Console\CommandArguments;
use Kinetis\Vector\Queues\Console\PruneMetricsCommand;
use Kinetis\Vector\Queues\Metrics\JobOutcome;
use Kinetis\Vector\Queues\Metrics\MetricsClock;

/**
 * `vector:queues:prune-metrics` deletes the rows whose minute began more
 * than --days ago, keeps the rest, and says how many it deleted. The
 * clock is set back to 2001, so the cutoff is older than any row the
 * demo itself wrote: only the test's own rows can be deleted, and the
 * count printed is exactly theirs.
 */
final class QueueMetricsPruneTest extends MetricsTestCase
{
    private const int DAY = 86_400;

    /** 2001-01-01T00:00:00Z, the start of a minute. */
    private const int BASE = 978_307_200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = self::BASE + 30;
    }

    public function testRowsOlderThanTheDefaultSevenDaysAreDeleted(): void
    {
        $cutoff = self::BASE - 7 * self::DAY;
        $this->record($cutoff - 60);   // a minute older than the cutoff
        $this->record($cutoff - 3 * self::DAY);
        $this->record($cutoff);        // the cutoff's own minute stays
        $this->record(self::BASE);

        [$code, $out] = $this->prune();

        self::assertSame(0, $code);
        self::assertSame([$cutoff, self::BASE], array_column($this->rows(), 'bucket'));
        self::assertSame("Deleted 2 queue metric row(s) older than 7 day(s).\n", $out);
    }

    public function testDaysSetsTheCutoff(): void
    {
        $this->record(self::BASE - 2 * self::DAY - 60);
        $this->record(self::BASE - self::DAY);

        [$code, $out] = $this->prune('--days=2');

        self::assertSame(0, $code);
        self::assertSame([self::BASE - self::DAY], array_column($this->rows(), 'bucket'));
        self::assertSame("Deleted 1 queue metric row(s) older than 2 day(s).\n", $out);

        // nothing left past the cutoff: zero, said so
        [, $again] = $this->prune('--days=2');
        self::assertSame("Deleted 0 queue metric row(s) older than 2 day(s).\n", $again);
        self::assertCount(1, $this->rows());
    }

    public function testABadDaysIsRefusedAndDeletesNothing(): void
    {
        $this->record(self::BASE - 30 * self::DAY);

        foreach (['--days=0', '--days=-1', '--days=seven', '--days=1.5', '--days=', '--days', '--days=36501'] as $bad) {
            [$code, $out] = $this->prune($bad);
            self::assertSame(1, $code, $bad);
            self::assertStringContainsString('--days needs a whole number of days from 1 to 36500', $out, $bad);
        }

        self::assertCount(1, $this->rows());
    }

    private function record(int $at): void
    {
        $this->metricsStore()->record('qa-mail', OtherJob::class, JobOutcome::Succeeded, 1.0, $at);
    }

    /** @return array{int, string} the exit code and what the command wrote, refusals last */
    private function prune(string ...$argv): array
    {
        $clock = $this->app->get(MetricsClock::class);
        self::assertInstanceOf(MetricsClock::class, $clock);
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($errors);

        $code = (new PruneMetricsCommand($this->metricsStore(), $clock, $output, $errors))->run(CommandArguments::parse(array_values($argv)));
        rewind($output);
        rewind($errors);

        return [$code, (string) stream_get_contents($output) . (string) stream_get_contents($errors)];
    }
}
