<?php

declare(strict_types=1);

namespace App\Vector\Support;

use App\Domain\Support\Team;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\Country;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\HasMany;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\VectorResource;

/** A support team on the PostgreSQL connection "support", with the countries it serves in a jsonb list. */
#[Resource(entity: Team::class, slug: 'support-teams', label: 'Teams', group: 'Support', icon: 'mdi-account-group-outline', title: 'name')]
final class TeamResource extends VectorResource
{
    #[\Override]
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('name')->required()->max(120)->sortable()->searchable(),
            Text::make('slug')->required()->max(120)->sortable()->searchable(),
            Text::make('email')->max(255)->sortable()->searchable(),
            Country::make('countries', 'Countries served')->multiple()->filterable()->badge(),
            DateTime::make('createdAt')->readonly()->sortable()->filterable()->hideFromForms(),
            DateTime::make('updatedAt')->readonly()->sortable()->filterable()->hideFromForms(),
            HasMany::to('agents', AgentResource::class),
        ];
    }
}
