<?php

declare(strict_types=1);

namespace App\Vector;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\ResourcePolicy;

/**
 * Users are administered but not created from the admin (a user needs a
 * password the resource does not expose), and nobody deletes themselves.
 */
final class UserPolicy extends ResourcePolicy
{
    public function viewAny(CurrentUserInterface $user): bool
    {
        return true;
    }

    public function view(CurrentUserInterface $user, array $record): bool
    {
        return true;
    }

    public function create(CurrentUserInterface $user): AuthorizationResponse
    {
        return AuthorizationResponse::deny('Users register through the application, not the admin.');
    }

    public function update(CurrentUserInterface $user, array $record): bool
    {
        return true;
    }

    public function delete(CurrentUserInterface $user, array $record): bool|AuthorizationResponse
    {
        return (string) ($record['id'] ?? '') === (string) $user->id()
            ? AuthorizationResponse::deny('You cannot delete your own account.')
            : true;
    }
}
