<?php

declare(strict_types=1);

namespace App\Tests\Vector\Fixtures\UiExtensionPackage;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;

/**
 * The fixture package's own bootstrap. It registers nothing with Vector:
 * its extensions are declared in composer.json. It leaves a marker so a
 * test can show it ran before Vector's bootstrap.
 */
final readonly class FixtureBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $app->instance(FixtureBooted::class, new FixtureBooted($app->has(\Kinetis\Vector\Ui\ExtensionModules::class)));
    }
}
