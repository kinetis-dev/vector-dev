<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\Queues\Fixtures\OtherJob;
use App\Tests\Vector\Queues\Fixtures\SendInvoiceJob;
use Kinetis\Vector\Queues\Metrics\JobOutcome;

/** `GET /vector/queues/metrics`, as the demo's admin calls it. */
final class QueueMetricsEndpointTest extends MetricsTestCase
{
    public function testTheDefaultWindowIsTheLastHourZeroFilled(): void
    {
        $this->record('qa-mail', OtherJob::class, JobOutcome::Succeeded, 10.0, self::NOW);
        $this->record('qa-mail', OtherJob::class, JobOutcome::Succeeded, 20.0, self::NOW - 600);

        $data = $this->metrics([]);

        self::assertSame(self::MINUTE - 59 * 60, $data['from']);
        self::assertSame(self::MINUTE, $data['to']);
        self::assertSame(60, $data['bucketSeconds']);
        self::assertCount(60, $data['buckets']);
        self::assertSame(range(self::MINUTE - 59 * 60, self::MINUTE, 60), array_column($data['buckets'], 'bucket'));

        $empty = ['succeeded' => 0, 'released' => 0, 'failed' => 0, 'averageDurationMs' => 0, 'maxDurationMs' => 0];

        foreach ($data['buckets'] as $bucket) {
            $expected = match ($bucket['bucket']) {
                self::MINUTE => ['succeeded' => 1, 'released' => 0, 'failed' => 0, 'averageDurationMs' => 10, 'maxDurationMs' => 10],
                self::MINUTE - 600 => ['succeeded' => 1, 'released' => 0, 'failed' => 0, 'averageDurationMs' => 20, 'maxDurationMs' => 20],
                default => $empty,
            };
            self::assertEquals(['bucket' => $bucket['bucket'], ...$expected], $bucket);
        }
    }

    public function testAnExplicitWindowReadsOnlyItsOwnMinutes(): void
    {
        $this->record('qa-mail', OtherJob::class, JobOutcome::Succeeded, 1.0, self::MINUTE - 120);
        $this->record('qa-mail', OtherJob::class, JobOutcome::Succeeded, 1.0, self::MINUTE - 60);
        $this->record('qa-mail', OtherJob::class, JobOutcome::Succeeded, 1.0, self::MINUTE);

        // each bound is taken to the start of its minute, and both minutes count
        $data = $this->metrics(['from' => (string) (self::MINUTE - 61), 'to' => (string) (self::MINUTE - 1)]);

        self::assertSame([self::MINUTE - 120, self::MINUTE - 60], array_column($data['buckets'], 'bucket'));
        self::assertSame([1, 1], array_column($data['buckets'], 'succeeded'));
        self::assertSame(2, $data['queues'][0]['succeeded']);
    }

    public function testTotalsPerQueueAndPerClassAndTheFilters(): void
    {
        // qa-mail, OtherJob: 3 succeeded, 1 released, 1 failed
        foreach ([[JobOutcome::Succeeded, 10.0], [JobOutcome::Succeeded, 20.0], [JobOutcome::Succeeded, 30.0], [JobOutcome::Released, 50.0], [JobOutcome::Failed, 90.0]] as [$outcome, $ms]) {
            $this->record('qa-mail', OtherJob::class, $outcome, $ms, self::MINUTE + 5);
        }

        // qa-mail, SendInvoiceJob: only releases — nothing finished, so no failure rate
        $this->record('qa-mail', SendInvoiceJob::class, JobOutcome::Released, 4.0, self::MINUTE - 300);
        // qa-reports, SendInvoiceJob: only failures
        $this->record('qa-reports', SendInvoiceJob::class, JobOutcome::Failed, 8.0, self::MINUTE - 120);
        $this->record('qa-reports', SendInvoiceJob::class, JobOutcome::Failed, 2.0, self::MINUTE - 60);
        // a queue the dashboard does not watch is never read
        $this->record('qa-elsewhere', OtherJob::class, JobOutcome::Failed, 1.0, self::MINUTE);

        $data = $this->metrics([]);

        self::assertEquals([
            ['queue' => 'qa-mail', 'succeeded' => 3, 'released' => 2, 'failed' => 1, 'averageDurationMs' => 34.0, 'maxDurationMs' => 90.0, 'failureRate' => 0.25, 'lastProcessedAt' => self::MINUTE + 5],
            ['queue' => 'qa-reports', 'succeeded' => 0, 'released' => 0, 'failed' => 2, 'averageDurationMs' => 5.0, 'maxDurationMs' => 8.0, 'failureRate' => 1.0, 'lastProcessedAt' => self::MINUTE - 60],
        ], $data['queues']);

        self::assertEquals([
            ['class' => OtherJob::class, 'succeeded' => 3, 'released' => 1, 'failed' => 1, 'averageDurationMs' => 40.0, 'maxDurationMs' => 90.0, 'failureRate' => 0.25, 'lastProcessedAt' => self::MINUTE + 5],
            ['class' => SendInvoiceJob::class, 'succeeded' => 0, 'released' => 1, 'failed' => 2, 'averageDurationMs' => 4.667, 'maxDurationMs' => 8.0, 'failureRate' => 1.0, 'lastProcessedAt' => self::MINUTE - 60],
        ], $data['classes']);

        // the minute: summed over every queue and class in it
        $minute = $data['buckets'][59];
        self::assertSame([self::MINUTE, 3, 1, 1], [$minute['bucket'], $minute['succeeded'], $minute['released'], $minute['failed']]);

        // queue filter
        $mail = $this->metrics(['queue' => 'qa-mail']);
        self::assertSame(['qa-mail'], array_column($mail['queues'], 'queue'));
        self::assertSame([OtherJob::class, SendInvoiceJob::class], array_column($mail['classes'], 'class'));
        self::assertSame(0, $mail['buckets'][57]['failed'], 'qa-reports\' failure is not in qa-mail\'s minutes');

        // class filter, with a leading backslash
        $invoice = $this->metrics(['class' => '\\' . SendInvoiceJob::class]);
        self::assertSame(['qa-mail', 'qa-reports'], array_column($invoice['queues'], 'queue'));
        self::assertNull($invoice['queues'][0]['failureRate'], 'released only: nothing finished');
        self::assertSame(0, $invoice['queues'][0]['succeeded']);
        self::assertSame([SendInvoiceJob::class], array_column($invoice['classes'], 'class'));

        // both
        $both = $this->metrics(['queue' => 'qa-reports', 'class' => OtherJob::class]);
        self::assertSame([], $both['queues']);
        self::assertSame([], $both['classes']);
        self::assertSame(0, array_sum(array_column($both['buckets'], 'failed')));
    }

    public function testAnUnwatchedQueueIsRefusedAsTheFailedListRefusesIt(): void
    {
        $this->client->get('/vector/queues/metrics', ['queue' => 'qa-elsewhere'], $this->adminHeaders())
            ->assertValidationError('queue')
            ->assertJsonPath('errors.0.code', 'invalid_choice');
        $this->client->get('/vector/queues/failed', ['queue' => 'qa-elsewhere'], $this->adminHeaders())
            ->assertValidationError('queue')
            ->assertJsonPath('errors.0.code', 'invalid_choice');
    }

    public function testABadWindowIsA422(): void
    {
        $headers = $this->adminHeaders();
        $get = fn (array $query) => $this->client->get('/vector/queues/metrics', $query, $headers);

        // inverted
        $get(['from' => (string) self::MINUTE, 'to' => (string) (self::MINUTE - 60)])->assertValidationError('to');
        // from in the future, to defaulting to now
        $get(['from' => (string) (self::MINUTE + 60)])->assertValidationError('to');
        // 7 days is the most: 10 080 minutes, and one more is refused
        self::assertCount(10_080, $this->metrics(['from' => (string) (self::MINUTE - 10_079 * 60), 'to' => (string) self::MINUTE])['buckets']);
        $get(['from' => (string) (self::MINUTE - 10_080 * 60), 'to' => (string) self::MINUTE])->assertValidationError('from');
        // not numbers
        $get(['from' => 'yesterday'])->assertValidationError('from');
        $get(['to' => '1800000000.5'])->assertValidationError('to');
        $get(['to' => '-60'])->assertValidationError('to');
        $get(['from' => ['1', '2']])->assertValidationError('from');
        // and the rest of the query
        $get(['class' => 'no spaces allowed'])->assertValidationError('class');
        $get(['minutes' => '60'])->assertValidationError('minutes');
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function metrics(array $query): array
    {
        $body = $this->client->get('/vector/queues/metrics', $query, $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($body);
        self::assertIsArray($body['data']);

        /** @var array<string, mixed> */
        return $body['data'];
    }

    private function record(string $queue, string $class, JobOutcome $outcome, float $durationMs, int $at): void
    {
        $this->metricsStore()->record($queue, $class, $outcome, $durationMs, $at);
    }
}
