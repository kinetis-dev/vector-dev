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
 *
 * `origin` names the channel a write came through when it was not the
 * admin's own hand (a short token such as `assistant`, which History
 * shows beside the entry) and is null otherwise. Whoever writes through
 * another channel registers an ActorContext carrying it on that
 * request's scope before anything that writes is resolved.
 */
final readonly class ActorContext
{
    /** What an origin token may be: a lowercase word of at most 40 characters. */
    public const string ORIGIN_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';

    public ?string $impersonatorId;

    public ?string $origin;

    /** @throws \InvalidArgumentException for an origin that is no token */
    public function __construct(public CurrentUserInterface $user, ?string $impersonatorId = null, ?string $origin = null)
    {
        $inherent = $user instanceof ImpersonatedUserInterface ? $user->impersonatorId() : null;
        $this->impersonatorId = $impersonatorId ?? ($inherent === null ? null : (string) $inherent);

        if ($origin !== null && preg_match(self::ORIGIN_PATTERN, $origin) !== 1) {
            throw new \InvalidArgumentException('An origin is a lowercase token of at most 40 characters, such as "assistant".');
        }

        $this->origin = $origin;
    }

    public function actorId(): string
    {
        return (string) $this->user->id();
    }
}
