<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Assistant;

use Kinetis\Runtime\ClientConnectionInterface;

/**
 * Whether the application itself stopped an assistant run's provider
 * stream because the browser left. shouldStop() is the closure a stream
 * asks before every event it reads (StoppableHttpClient, through
 * ProviderResolver::for()); once it answered true (the connection gone)
 * the stream closes, and fired() is true for the rest of the run. That is
 * the one way a stream stops, so fired() is the only evidence that a
 * provider's failure was the departure's doing rather than the provider's:
 * a connection that is merely gone decides whether frames are written,
 * never how a run is logged or charged. A Bedrock call never asks it.
 *
 * One per run, created with the request's connection; never shared.
 */
final class DepartureSignal
{
    private bool $fired = false;

    public function __construct(private readonly ClientConnectionInterface $connection) {}

    /** Asked by the stream before every event: true once the browser has gone, and from then on. */
    public function shouldStop(): bool
    {
        if (!$this->fired && $this->connection->isGone()) {
            $this->fired = true;
        }

        return $this->fired;
    }

    /** Whether shouldStop() has answered true: the stream was stopped by the departure. */
    public function fired(): bool
    {
        return $this->fired;
    }
}
