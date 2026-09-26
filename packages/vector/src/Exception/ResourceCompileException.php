<?php

declare(strict_types=1);

namespace Kinetis\Vector\Exception;

use RuntimeException;

/**
 * A resource definition the registry refuses, raised from `kinetis build`
 * or a development boot with the class, the field and the reason.
 */
final class ResourceCompileException extends RuntimeException
{
    public static function resource(string $class, string $reason): self
    {
        return new self("Resource {$class}: {$reason}");
    }

    public static function field(string $class, string $field, string $reason): self
    {
        return new self("Resource {$class}, field \"{$field}\": {$reason}");
    }
}
