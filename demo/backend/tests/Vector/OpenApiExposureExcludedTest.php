<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/** Served: false. the environment is not one OPENAPI_ENVIRONMENTS names. */
final class OpenApiExposureExcludedTest extends OpenApiExposureTestCase
{
    protected function environments(): string
    {
        return 'production, staging';
    }

    protected function served(): bool
    {
        return false;
    }
}
