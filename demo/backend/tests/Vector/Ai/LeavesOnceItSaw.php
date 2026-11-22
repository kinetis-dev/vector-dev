<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Runtime\ClientConnectionInterface;

/** A connection whose browser leaves once $left says so: what it was sent so far, say. */
final readonly class LeavesOnceItSaw implements ClientConnectionInterface
{
    /** @param \Closure(): bool $left */
    public function __construct(private \Closure $left) {}

    #[\Override]
    public function isGone(): bool
    {
        return ($this->left)();
    }
}
