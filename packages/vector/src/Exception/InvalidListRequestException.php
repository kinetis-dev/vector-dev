<?php

declare(strict_types=1);

namespace Kinetis\Vector\Exception;

final class InvalidListRequestException extends VectorException
{
    #[\Override]
    public function httpStatus(): int
    {
        return 422;
    }
}
