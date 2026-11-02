<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues\Fixtures;

use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Job;

/** A job with one #[Sensitive] argument: the dashboard must never show its value. */
final readonly class SendInvoiceJob implements Job
{
    public function __construct(
        public int $invoiceId,
        #[Sensitive]
        public string $apiToken,
    ) {}

    public function handle(): void {}
}
