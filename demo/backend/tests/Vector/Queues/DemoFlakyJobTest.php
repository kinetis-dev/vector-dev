<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Console\DemoFlakyJobsCommand;
use App\Jobs\SyncWarehouseStockJob;
use App\Tests\Vector\RecordingQueue;
use Kinetis\Console\CommandArguments;
use Kinetis\Queue\JobSerializer;

/**
 * The demo's flaky job, which fills the queue dashboard: it fails the
 * share of attempts it is told to, `demo:queue-flaky` queues it on a
 * watched queue with retries, and its #[Sensitive] API key never leaves
 * the server — not through JobSerializer::redact(), nor the failed-job
 * detail built on it.
 */
final class DemoFlakyJobTest extends QueuesTestCase
{
    private const string KEY = 'demo-key-0123456789abcdef';

    public function testItFailsAsOftenAsItIsTold(): void
    {
        (new SyncWarehouseStockJob(1, self::KEY, 0))->handle();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Warehouse 7 did not answer the stock sync.');
        (new SyncWarehouseStockJob(7, self::KEY, 100))->handle();
    }

    public function testTheApiKeyIsRedacted(): void
    {
        self::assertSame(
            ['warehouseId' => 7, 'apiKey' => JobSerializer::REDACTED, 'failurePercent' => 100],
            JobSerializer::redact(SyncWarehouseStockJob::class, ['warehouseId' => 7, 'apiKey' => self::KEY, 'failurePercent' => 100]),
        );
    }

    public function testTheFailedJobDetailShowsTheApiKeyRedacted(): void
    {
        $id = $this->recordFailure('qa-mail', 1_800_000_000, SyncWarehouseStockJob::class, ['warehouseId' => 7, 'apiKey' => self::KEY, 'failurePercent' => 100]);

        $response = $this->client->get("/vector/queues/failed/{$id}", headers: $this->adminHeaders())->assertOk()
            ->assertJsonPath('data.class', SyncWarehouseStockJob::class)
            ->assertJsonPath('data.args.warehouseId', 7)
            ->assertJsonPath('data.args.apiKey', JobSerializer::REDACTED);

        self::assertStringNotContainsString(self::KEY, $response->body());
    }

    public function testTheCommandQueuesTheJobsOnAWatchedQueueWithRetries(): void
    {
        $queue = new RecordingQueue();
        [$code, $out] = $this->command($queue, '--count=3', '--failure=50');

        self::assertSame(0, $code);
        self::assertSame(array_fill(0, 3, ['job' => SyncWarehouseStockJob::class, 'queue' => 'default', 'maxAttempts' => DemoFlakyJobsCommand::MAX_ATTEMPTS]), $queue->pushed);
        self::assertSame("Queued 3 flaky job(s) on \"default\", each failing 50% of its attempts, up to 3 attempts.\n", $out);
        // the queue it uses is one the demo's dashboard watches (.env.example's VECTOR_QUEUES)
        self::assertContains(DemoFlakyJobsCommand::QUEUE, self::demoQueues());
    }

    /** The dashboard watches what the demo's worker serves (docker-compose.yml), in the same order. */
    public function testTheDemoDashboardWatchesExactlyTheQueuesItsWorkerServes(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 4) . '/docker-compose.yml');
        if (preg_match('/"queue:work", "--queue=([^"]+)"/', $compose, $m) !== 1) {
            self::fail('docker-compose.yml runs no `queue:work --queue=…` worker.');
        }

        self::assertSame(array_map(trim(...), explode(',', $m[1])), self::demoQueues());
    }

    public function testTheCommandRefusesABadCountOrShareAndQueuesNothing(): void
    {
        foreach (['--count=0', '--count=1001', '--count=x', '--failure=101', '--failure=-1'] as $bad) {
            $queue = new RecordingQueue();
            [$code, $out] = $this->command($queue, $bad);

            self::assertSame(1, $code, $bad);
            self::assertSame([], $queue->pushed, $bad);
            self::assertStringContainsString('--count takes a whole number from 1 to 1000', $out);
        }
    }

    /** @return array{int, string} the exit code, and what it wrote to either stream */
    private function command(RecordingQueue $queue, string ...$argv): array
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($errors);

        $code = (new DemoFlakyJobsCommand($queue, $output, $errors))->run(CommandArguments::parse(array_values($argv)));
        rewind($output);
        rewind($errors);

        return [$code, (string) stream_get_contents($output) . (string) stream_get_contents($errors)];
    }

    /** @return list<string> */
    private static function demoQueues(): array
    {
        $env = (string) file_get_contents(dirname(__DIR__, 3) . '/.env.example');
        if (preg_match('/^VECTOR_QUEUES=(.*)$/m', $env, $m) !== 1) {
            self::fail('.env.example sets no VECTOR_QUEUES.');
        }

        return array_map(trim(...), explode(',', $m[1]));
    }
}
