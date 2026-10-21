<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;

/**
 * Served: false. OPENAPI_ENVIRONMENTS is absent from the configuration
 * the application boots with, although `.env` sets it to `development`.
 */
final class OpenApiExposureUnsetTest extends OpenApiExposureTestCase
{
    protected function environments(): ?string
    {
        return null;
    }

    protected function served(): bool
    {
        return false;
    }

    public function testTheKeyIsGenuinelyAbsent(): void
    {
        // the process has it, from .env; the booted configuration does not
        self::assertSame('development', getenv('OPENAPI_ENVIRONMENTS'));
        $config = $this->app->get(Config::class);
        self::assertInstanceOf(Config::class, $config);
        self::assertNull($config->get('OPENAPI_ENVIRONMENTS'));
        self::assertSame('development', $config->get('APP_ENV'));
    }
}
