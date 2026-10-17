<?php

declare(strict_types=1);

namespace App\Vector;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\ResourcePolicy;

/** Profiles are viewed and renamed; accounts are created and deleted (and restored, which follows delete) under Users. */
final class ProfilePolicy extends ResourcePolicy
{
    #[\Override]
    public function create(CurrentUserInterface $user): AuthorizationResponse
    {
        return AuthorizationResponse::deny('Accounts are created under Users.');
    }

    /** @param array<string, mixed> $record */
    #[\Override]
    public function delete(CurrentUserInterface $user, array $record): AuthorizationResponse
    {
        return AuthorizationResponse::deny('Accounts are deleted under Users.');
    }
}
