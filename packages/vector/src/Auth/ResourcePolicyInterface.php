<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Http\CurrentUserInterface;

/**
 * The application's authorization for one resource, evaluated through
 * kinetis/authorization's Gate. A plain class the request container
 * builds, so it may inject what a controller may. `$record` is the
 * serialized row, never an entity.
 */
interface ResourcePolicyInterface
{
    public function viewAny(CurrentUserInterface $user): bool|AuthorizationResponse;

    /** @param array<string, mixed> $record */
    public function view(CurrentUserInterface $user, array $record): bool|AuthorizationResponse;

    public function create(CurrentUserInterface $user): bool|AuthorizationResponse;

    /** @param array<string, mixed> $record */
    public function update(CurrentUserInterface $user, array $record): bool|AuthorizationResponse;

    /** @param array<string, mixed> $record */
    public function delete(CurrentUserInterface $user, array $record): bool|AuthorizationResponse;

    /**
     * Restoring a soft-deleted record.
     *
     * @param array<string, mixed> $record
     */
    public function restore(CurrentUserInterface $user, array $record): bool|AuthorizationResponse;

    /**
     * Removing a soft-deleting resource's row for good.
     *
     * @param array<string, mixed> $record
     */
    public function forceDelete(CurrentUserInterface $user, array $record): bool|AuthorizationResponse;

    /** Downloading the list as a file. */
    public function export(CurrentUserInterface $user): bool|AuthorizationResponse;

    /**
     * Running a named action; the action's own canRun() decides
     * visibility per record on top of this.
     */
    public function runAction(CurrentUserInterface $user, string $action): bool|AuthorizationResponse;

    /**
     * Whether the user sees one field at all. A hidden field leaves the
     * descriptor, every serialized record, the audit trail and exports,
     * and cannot be filtered, sorted, grouped, searched or written by
     * that user. Decided per resource, not per record.
     */
    public function viewField(CurrentUserInterface $user, string $field): bool|AuthorizationResponse;

    /**
     * Adding $related to $record's many-to-many $relation.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $related
     */
    public function attach(CurrentUserInterface $user, array $record, string $relation, array $related): bool|AuthorizationResponse;

    /**
     * Removing $related from $record's many-to-many $relation.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $related
     */
    public function detach(CurrentUserInterface $user, array $record, string $relation, array $related): bool|AuthorizationResponse;

    /** Making a shared view the one this resource's list opens with for everyone. */
    public function setDefaultView(CurrentUserInterface $user): bool|AuthorizationResponse;
}
