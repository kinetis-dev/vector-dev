<?php

declare(strict_types=1);

namespace Kinetis\Vector\Jobs;

use Kinetis\Vector\Exception\VectorException;

/**
 * A job stopped for a reason its admin should read: the selection is
 * gone, the actor lost access, the action declined. Any other failure is
 * reported generically and logged (JobContext::run()).
 */
final class JobFailedException extends VectorException
{
    #[\Override]
    public function httpStatus(): int
    {
        return 422;
    }
}
