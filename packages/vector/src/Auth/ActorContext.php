<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

use Kinetis\Http\CurrentUserInterface;

/**
 * Who a write is recorded as: the current user, and whoever is
 * impersonating them. Under HTTP the request container builds it from
 * the identity the application registered, which says so itself when
 * it implements ImpersonatedUserInterface; in a queue worker there is no
 * such identity, so JobContext registers one carrying the impersonator
 * the job row remembered. Read by every log and audit row Vector writes.
 */
final readonly class ActorContext
{
    public ?string $impersonatorId;

    public function __construct(public CurrentUserInterface $user, ?string $impersonatorId = null)
    {
        $inherent = $user instanceof ImpersonatedUserInterface ? $user->impersonatorId() : null;
        $this->impersonatorId = $impersonatorId ?? ($inherent === null ? null : (string) $inherent);
    }

    public function actorId(): string
    {
        return (string) $this->user->id();
    }
}
