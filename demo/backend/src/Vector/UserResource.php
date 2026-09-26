<?php

declare(strict_types=1);

namespace App\Vector;

use App\Domain\User;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Email;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\VectorResource;

/** Explicit so the password hash never appears anywhere. */
#[Resource(entity: User::class, group: 'Access', icon: 'mdi-shield-account-outline', title: 'name')]
final class UserResource extends VectorResource
{
    public function policy(): string
    {
        return UserPolicy::class;
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('name')->required()->max(120)->sortable()->searchable(),
            Email::make('email')->required()->max(255)->sortable()->searchable(),
            Boolean::make('isAdmin', 'Admin')->sortable()->filterable(),
            DateTime::make('createdAt')->readonly()->sortable()->hideFromForms(),
        ];
    }

    public function searchSubtitle(): string
    {
        return 'email';
    }
}
