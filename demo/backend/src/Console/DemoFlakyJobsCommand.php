<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\SyncWarehouseStockJob;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Queue\QueueInterface;

/**
 * Pushes `--count=<n>` (20) SyncWarehouseStockJob onto the `default`
 * queue — one VECTOR_QUEUES watches and the worker serves — each
 * failing `--failure=<percent>` (40) of its attempts, with up to
 * MAX_ATTEMPTS attempts. The worker then fills the queue dashboard: a
 * retry is a release in its metrics, and a job out of attempts lands in
 * its Failed tab with the API key redacted.
 *
 * $output and $errors are injectable (defaulting to STDOUT and STDERR)
 * for testability: a #[Command] method takes zero parameters or exactly
 * one CommandArguments.
 */
final readonly class DemoFlakyJobsCommand
{
    public const string QUEUE = 'default';

    public const int MAX_ATTEMPTS = 3;

    private const int MAX_COUNT = 1000;

    /**
     * Both are typed mixed because PHP has no native resource type.
     *
     * @param resource $output
     * @param resource $errors
     */
    public function __construct(
        private QueueInterface $queue,
        private mixed $output = STDOUT,
        private mixed $errors = STDERR,
    ) {}

    #[Command('demo:queue-flaky', description: 'Queues flaky demo jobs for the queue dashboard (--count=20, --failure=40)')]
    public function run(CommandArguments $arguments): int
    {
        $count = $this->number($arguments, 'count', 20, 1, self::MAX_COUNT);
        $failure = $this->number($arguments, 'failure', 40, 0, 100);

        if ($count === null || $failure === null) {
            fwrite($this->errors, '--count takes a whole number from 1 to ' . self::MAX_COUNT . ", --failure a percentage from 0 to 100.\n");

            return 1;
        }

        for ($i = 1; $i <= $count; $i++) {
            $this->queue->push(new SyncWarehouseStockJob($i, 'demo-key-' . bin2hex(random_bytes(8)), $failure), queue: self::QUEUE, maxAttempts: self::MAX_ATTEMPTS);
        }

        fwrite($this->output, "Queued {$count} flaky job(s) on \"" . self::QUEUE . "\", each failing {$failure}% of its attempts, up to " . self::MAX_ATTEMPTS . " attempts.\n");

        return 0;
    }

    private function number(CommandArguments $arguments, string $name, int $default, int $min, int $max): ?int
    {
        if (!$arguments->hasOption($name)) {
            return $default;
        }

        $value = $arguments->option($name) ?? '';

        return preg_match('/^\d{1,4}$/D', $value) === 1 && (int) $value >= $min && (int) $value <= $max ? (int) $value : null;
    }
}
