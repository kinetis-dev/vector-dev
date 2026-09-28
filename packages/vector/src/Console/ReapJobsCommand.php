<?php

declare(strict_types=1);

namespace Kinetis\Vector\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Vector\Jobs\StalledJobs;

/**
 * Marks stalled background jobs failed: rows still `running` whose
 * worker stopped reporting progress longer ago than the Background jobs
 * setting allows. For a scheduler to run now and then; the jobs page
 * shows such rows as stalled meanwhile.
 */
final readonly class ReapJobsCommand
{
    public function __construct(private StalledJobs $stalled) {}

    #[Command('vector:jobs:reap', description: 'Marks background jobs that stopped reporting progress as failed, after the minutes the Background jobs setting allows.')]
    public function reap(): int
    {
        $count = $this->stalled->reap();
        fwrite(STDOUT, $count === 0
            ? "No stalled jobs (running rows silent for {$this->stalled->minutes()} minutes or more).\n"
            : "{$count} stalled job(s) marked failed.\n");

        return 0;
    }
}
