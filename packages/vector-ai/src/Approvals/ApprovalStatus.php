<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Approvals;

/** Where a proposed change stands. Only `pending` can be decided; every other status is final. */
enum ApprovalStatus: string
{
    /** Proposed, waiting for the admin. */
    case Pending = 'pending';

    /** Approved, and being executed by the request that approved it. */
    case Executing = 'executing';

    /** Approved and executed; the row holds the result. */
    case Executed = 'executed';

    /** Rejected by the admin. */
    case Rejected = 'rejected';

    /** Not decided before it expired. */
    case Expired = 'expired';

    /** Approved, but the change could not be made; the row holds why. */
    case Failed = 'failed';

    /** @return list<self> the statuses of a row that was decided one way or another, which the sweep deletes after a while */
    public static function decided(): array
    {
        return [self::Executed, self::Rejected, self::Expired, self::Failed];
    }
}
