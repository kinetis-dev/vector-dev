<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues\Fixtures;

use Kinetis\Queue\ClearableQueueInterface;
use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;

/** A clearable queue that runs nothing: it counts the jobs pushed per queue, and a clear discards them. */
final class ClearableRecordingQueue implements ClearableQueueInterface
{
    /** @var array<string, list<class-string>> */
    public array $waiting = [];

    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $this->waiting[$queue][] = $job::class;
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
        return count($this->waiting[$queue] ?? []);
    }

    public function clear(string $queue = 'default'): int
    {
        $removed = $this->size($queue);
        unset($this->waiting[$queue]);

        return $removed;
    }
}
