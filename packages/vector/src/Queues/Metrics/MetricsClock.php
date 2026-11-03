<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Metrics;

use Closure;

/**
 * The current unix time, for the metrics' minute buckets, the endpoint's
 * default window and the prune cutoff. {@see \Kinetis\Vector\Queues\QueueDashboard::register()}
 * binds the system clock; a test binds one of its own.
 */
final readonly class MetricsClock
{
    /** @var Closure(): int */
    private Closure $now;

    /** @param (Closure(): int)|null $now */
    public function __construct(?Closure $now = null)
    {
        $this->now = $now ?? time(...);
    }

    public function now(): int
    {
        return ($this->now)();
    }
}
