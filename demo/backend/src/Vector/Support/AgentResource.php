<?php

declare(strict_types=1);

namespace App\Vector\Support;

use App\Domain\Support\Agent;
use App\Domain\Support\AgentRole;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\Date;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Email;
use Kinetis\Vector\Fields\Enum;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\VectorResource;

#[Resource(entity: Agent::class, group: 'Support', icon: 'mdi-headset', title: 'name')]
final class AgentResource extends VectorResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('name')->required()->max(120)->sortable()->searchable()->filterable(),
            Email::make('email')->required()->max(255)->sortable()->searchable(),
            BelongsTo::to('team', TeamResource::class)->sortable()->filterable(),
            Enum::make('role')->of(AgentRole::class)->colors(['manager' => 'primary', 'lead' => 'info', 'agent' => 'grey'])->sortable()->filterable(),
            Boolean::make('isActive', 'Active')->sortable()->filterable(),
            Date::make('joinedOn', 'Joined')->sortable()->filterable(),
            DateTime::make('createdAt')->readonly()->hideFromForms()->hideFromIndex(),
            DateTime::make('updatedAt')->readonly()->hideFromForms()->hideFromIndex(),
        ];
    }
}
