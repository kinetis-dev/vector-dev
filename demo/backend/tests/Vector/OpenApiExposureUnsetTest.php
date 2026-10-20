<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/** Served: false. OPENAPI_ENVIRONMENTS is empty, which names no environment, as unset does. */
final class OpenApiExposureUnsetTest extends OpenApiExposureTestCase
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
