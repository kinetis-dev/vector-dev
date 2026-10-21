<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/** Served: true. OPENAPI_ENVIRONMENTS names the environment, matched ignoring case and space. */
final class OpenApiExposureIncludedTest extends OpenApiExposureTestCase
{
    protected function environments(): string
    {
        return ' Development ,staging';
    }

    protected function served(): bool
    {
        return true;
    }
}
