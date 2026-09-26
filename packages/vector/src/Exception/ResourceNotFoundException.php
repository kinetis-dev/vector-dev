<?php

declare(strict_types=1);

namespace Kinetis\Vector\Exception;

final class ResourceNotFoundException extends VectorException
{
    public static function slug(string $slug): self
    {
        return new self("No resource is registered as \"{$slug}\".");
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
