<?php

declare(strict_types=1);

namespace App\Jobs;

use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Job;

/**
 * The demo's flaky job: it "syncs" a warehouse's stock and fails
 * $failurePercent percent of its attempts, so the queue dashboard
 * (/queues) has real retries, failures and durations to show. Pushed
 * by `demo:queue-flaky`.
 *
 * $apiKey is #[Sensitive]: the worker's logs, and the dashboard's
 * failed-job detail (JobSerializer::redact()), show it as `[redacted]`.
 * It is a made-up value; the job calls nothing.
 */
final readonly class SyncWarehouseStockJob implements Job
{
    public function __construct(
        public int $warehouseId,
        #[Sensitive]
        public string $apiKey,
        public int $failurePercent = 40,
    ) {}

    public function handle(): void
    {
        if (random_int(1, 100) <= $this->failurePercent) {
            throw new \RuntimeException("Warehouse {$this->warehouseId} did not answer the stock sync.");
        }
    }
}
