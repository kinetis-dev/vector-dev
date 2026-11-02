<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues\Fixtures;

use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;

/** Hands out the deliveries it was given, in order, and records how the worker settled each one. */
final class ScriptedQueue implements QueueInterface
{
    /** @var list<string> "ack", "release" or "fail" per settled delivery, in order */
    public array $settled = [];

    /** @param list<QueuedJob> $deliveries */
    public function __construct(private array $deliveries) {}

    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void {}

    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        return array_shift($this->deliveries);
    }

    public function ack(QueuedJob $job): void
    {
        $this->settled[] = 'ack';
    }

    public function release(QueuedJob $job, int $delaySeconds = 0): void
    {
        $this->settled[] = 'release';
    }

    public function fail(QueuedJob $job): void
    {
        $this->settled[] = 'fail';
    }

    public function size(string $queue = 'default'): int
    {
        return count($this->deliveries);
    }
}
