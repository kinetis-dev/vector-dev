<?php

declare(strict_types=1);

namespace Kinetis\Vector\Exception;

/** An operation the resource cannot perform at all, whatever the policy says. */
final class ForbiddenOperationException extends VectorException
{
    #[\Override]
    public function httpStatus(): int
    {
        return 403;
    }
}
