<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues\Fixtures;

use Kinetis\Queue\Job;

/** A job whose handler always throws. */
final readonly class FailingJob implements Job
{
    public function __construct(public string $reason) {}

    public function handle(): void
    {
        throw new \RuntimeException($this->reason);
    }
}
