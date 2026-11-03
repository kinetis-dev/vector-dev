<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Metrics;

/** How one attempt of a job ended, as kinetis/queue's worker reports it — each the name of its counter column. */
enum JobOutcome: string
{
    case Succeeded = 'succeeded';
    case Released = 'released';
    case Failed = 'failed';
}
