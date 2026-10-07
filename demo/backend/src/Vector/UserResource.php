<?php

declare(strict_types=1);

namespace App\Vector;

use App\Domain\User;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Email;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Password;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\VectorResource;
use Kinetis\Vector\Write\WriteContext;

/**
 * Explicit so the password hash never appears anywhere: the password
 * field is on the edit form alone, hashed by the application's bound
 * hasher, and left as stored when the form sends it empty. The hooks
 * keep the admin reachable: nobody demotes themself, and the last admin
 * is neither demoted nor deleted — both would lock everyone out until
 * someone reaches the database.
 */
#[Resource(entity: User::class, group: 'Access', icon: 'mdi-shield-account-outline', title: 'name')]
final class UserResource extends VectorResource
{
    #[\Override]
    public function policy(): string
    {
        return UserPolicy::class;
    }

    #[\Override]
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('name')->required()->max(120)->sortable()->searchable(),
            Email::make('email')->required()->max(255)->unique()->sortable()->searchable(),
            Boolean::make('isAdmin', 'Admin')->sortable()->filterable(),
            Password::make('passwordHash', 'New password')->hashed()->min(8)
                ->help('Leave empty to keep the current password.'),
            DateTime::make('createdAt')->readonly()->sortable()->hideFromForms(),
        ];
    }

    #[\Override]
    public function searchSubtitle(): string
    {
        return 'email';
    }

    #[\Override]
    public function beforeUpdate(object $entity, WriteContext $context): void
    {
        if (!$entity instanceof User || $entity->isAdmin()) {
            return;
        }

        if ($entity->id() === $context->user->id()) {
            throw new ForbiddenOperationException('You cannot remove your own admin access.');
        }

        if ($this->otherAdmins($entity, $context) === 0) {
            throw new ForbiddenOperationException('This is the last admin; make someone else an admin first.');
        }
    }

    #[\Override]
    public function beforeDelete(object $entity, WriteContext $context): void
    {
        if ($entity instanceof User && $entity->isAdmin() && $this->otherAdmins($entity, $context) === 0) {
            throw new ForbiddenOperationException('This is the last admin; make someone else an admin first.');
        }
    }

    /** How many admins the table holds besides this one, as stored now. */
    private function otherAdmins(User $user, WriteContext $context): int
    {
        return $context->entities->builder()->table('users')->where('is_admin', '=', 1)->where('id', '!=', $user->id())->count();
    }
}
