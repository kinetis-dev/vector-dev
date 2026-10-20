<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/** Served: false. OPENAPI_ENVIRONMENTS is set and empty, which names no environment. */
final class OpenApiExposureEmptyTest extends OpenApiExposureTestCase
{
    protected function environments(): string
    {
        return '';
    }

    protected function served(): bool
    {
        return false;
    }
}
