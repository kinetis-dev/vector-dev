<?php

declare(strict_types=1);

namespace App\Tests\Vector\Fixtures\UiExtensionPackage;

/** Left by FixtureBootstrap: whether Vector's extension modules were bound when it ran. */
final readonly class FixtureBooted
{
    public function __construct(public bool $sawVectorModules) {}
}
