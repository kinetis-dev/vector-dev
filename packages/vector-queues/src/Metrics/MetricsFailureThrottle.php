<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Metrics;

/**
 * How often a failed metrics write is logged: once per INTERVAL_SECONDS
 * per worker. A database that is down fails every job's write, and one
 * warning a minute says so without burying the worker's own log.
 *
 * Bound once on AppScope by {@see \Kinetis\Vector\Queues\PackageBootstrap},
 * so its one piece of state — when it last allowed a line — is shared by
 * every job a worker runs, and is an instance property, never a static.
 * It holds nothing about any job.
 */
final class MetricsFailureThrottle
{
    public const int INTERVAL_SECONDS = 60;

    private ?int $lastLoggedAt = null;

    /** Whether a failure at $now may be logged; when it may, the next one is held back for INTERVAL_SECONDS. */
    public function allow(int $now): bool
    {
        if ($this->lastLoggedAt !== null && $now - $this->lastLoggedAt < self::INTERVAL_SECONDS) {
            return false;
        }

        $this->lastLoggedAt = $now;

        return true;
    }
}
