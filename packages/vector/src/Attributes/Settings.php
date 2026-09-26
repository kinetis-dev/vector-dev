<?php

declare(strict_types=1);

namespace Kinetis\Vector\Attributes;

use Attribute;

/**
 * Marks a SettingsGroup subclass for discovery. Compile-time data, like
 * #[Resource]: the registry reads it under `kinetis build` and at boot
 * in development, never at request time. The scanner considers only
 * files that carry an attribute, so a group without one is invisible.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Settings
{
    public function __construct() {}
}
