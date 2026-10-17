<?php

declare(strict_types=1);

namespace App\Vector;

use App\Domain\User;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Email;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\VectorResource;

/**
 * A second resource over the users table: the public face of an
 * account, without its admin flag or password. Users stays the
 * relation target, so a relation to a user links there. Its policy
 * refuses create and delete, which belong to UserResource and its
 * last-admin guard.
 */
#[Resource(entity: User::class, slug: 'profiles', label: 'Profiles', labelSingular: 'Profile', group: 'Access', icon: 'mdi-card-account-details-outline', title: 'name')]
final class ProfileResource extends VectorResource
{
    #[\Override]
    public function policy(): string
    {
        return ProfilePolicy::class;
    }

    #[\Override]
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('name')->required()->max(120)->sortable()->searchable(),
            Email::make('email')->readonly()->sortable()->searchable(),
            DateTime::make('createdAt')->readonly()->sortable()->hideFromForms(),
        ];
    }

    #[\Override]
    public function replicable(): bool
    {
        return false;
    }

    #[\Override]
    public function globallySearchable(): bool
    {
        return false;
    }
}
