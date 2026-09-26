<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;

/** Stands in for the application's own queue: records every push, runs nothing. */
final class RecordingQueue implements QueueInterface
{
    /** @var list<array{job: class-string, queue: string}> */
    public array $pushed = [];

    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $this->pushed[] = ['job' => $job::class, 'queue' => $queue];
    }

    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        return null;
    }

    public function ack(QueuedJob $job): void {}

    public function release(QueuedJob $job, int $delaySeconds = 0): void {}

    public function fail(QueuedJob $job): void {}

    public function size(string $queue = 'default'): int
    {
        return 0;
    }
}
