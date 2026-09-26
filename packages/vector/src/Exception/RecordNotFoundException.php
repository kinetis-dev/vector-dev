<?php

declare(strict_types=1);

namespace Kinetis\Vector\Exception;

final class RecordNotFoundException extends VectorException
{
    public static function of(string $resource, string $id): self
    {
        return new self("No {$resource} record has the identifier \"{$id}\".");
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
