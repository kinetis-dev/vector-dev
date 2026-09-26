<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

/**
 * A user object that knows it is being driven by someone else. The
 * application's identity carries the impersonator when it re-issued a
 * token or swapped a session; Vector reads it for the log and the UI.
 */
interface ImpersonatedUserInterface
{
    public function impersonatorId(): string|int|null;
}
