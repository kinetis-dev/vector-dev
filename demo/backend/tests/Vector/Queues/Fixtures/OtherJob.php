<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues\Fixtures;

use Kinetis\Queue\Job;

final readonly class OtherJob implements Job
{
    public function __construct(public string $report) {}

    public function handle(): void {}
}
