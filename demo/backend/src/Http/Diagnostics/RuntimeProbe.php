<?php

declare(strict_types=1);

namespace App\Http\Diagnostics;

/**
 * One per application boot, registered on AppScope in bootstrap.php: a
 * random id for the boot and how many requests it has served. Under a
 * persistent worker the same boot answers request after request, so the
 * count climbs; under PHP-FPM every request is a boot of its own, and
 * the count is always 1. It holds nothing about any request.
 */
final class RuntimeProbe
{
    public readonly string $bootId;

    private int $served = 0;

    public function __construct()
    {
        $this->bootId = bin2hex(random_bytes(8));
    }

    /** Counts this request and returns how many this boot has served, this one included. */
    public function served(): int
    {
        return ++$this->served;
    }
}
