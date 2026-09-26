<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Http\CurrentUserInterface;

/**
 * A base for application policies: every decision defaults to the one
 * the access policy already made (allow), so a policy overrides only
 * what it restricts. Restore and force-delete follow delete, and attach
 * and detach follow update, unless overridden.
 */
abstract class ResourcePolicy implements ResourcePolicyInterface
{
    public function viewAny(CurrentUserInterface $user): bool|AuthorizationResponse
    {
        return true;
    }

    /** @param array<string, mixed> $record */
    public function view(CurrentUserInterface $user, array $record): bool|AuthorizationResponse
    {
        return true;
    }

    public function create(CurrentUserInterface $user): bool|AuthorizationResponse
    {
        return true;
    }

    /** @param array<string, mixed> $record */
    public function update(CurrentUserInterface $user, array $record): bool|AuthorizationResponse
    {
        return true;
    }

    /** @param array<string, mixed> $record */
    public function delete(CurrentUserInterface $user, array $record): bool|AuthorizationResponse
    {
        return true;
    }

    /** @param array<string, mixed> $record */
    public function restore(CurrentUserInterface $user, array $record): bool|AuthorizationResponse
    {
        return $this->delete($user, $record);
    }

    /** @param array<string, mixed> $record */
    public function forceDelete(CurrentUserInterface $user, array $record): bool|AuthorizationResponse
    {
        return $this->delete($user, $record);
    }

    public function export(CurrentUserInterface $user): bool|AuthorizationResponse
    {
        return true;
    }

    public function runAction(CurrentUserInterface $user, string $action): bool|AuthorizationResponse
    {
        return true;
    }

    /** Whether the user may make a shared view the one this resource's list opens with for everyone. */
    public function setDefaultView(CurrentUserInterface $user): bool|AuthorizationResponse
    {
        return true;
    }

    public function viewField(CurrentUserInterface $user, string $field): bool|AuthorizationResponse
    {
        return true;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $related
     */
    public function attach(CurrentUserInterface $user, array $record, string $relation, array $related): bool|AuthorizationResponse
    {
        return $this->update($user, $record);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $related
     */
    public function detach(CurrentUserInterface $user, array $record, string $relation, array $related): bool|AuthorizationResponse
    {
        return $this->update($user, $record);
    }
}
