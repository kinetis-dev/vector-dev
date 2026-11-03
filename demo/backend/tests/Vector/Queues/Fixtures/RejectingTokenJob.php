<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues\Fixtures;

use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Job;

/** A job whose constructor refuses its token — quoting it in the exception message, as careless code does. */
final readonly class RejectingTokenJob implements Job
{
    public function __construct(
        #[Sensitive]
        public string $apiToken,
    ) {
        throw new \InvalidArgumentException("The token {$apiToken} has been revoked.");
    }

    public function handle(): void {}
}
