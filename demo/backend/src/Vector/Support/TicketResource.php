<?php

declare(strict_types=1);

namespace App\Vector\Support;

use App\Domain\Support\Ticket;
use App\Domain\Support\TicketPriority;
use App\Domain\Support\TicketStatus;
use App\Vector\Support\Actions\EscalateTickets;
use App\Vector\Support\Widgets\TicketsByStatus;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Badge;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\Currency;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Email;
use Kinetis\Vector\Fields\Enum;
use Kinetis\Vector\Fields\HasMany;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Json;
use Kinetis\Vector\Fields\ManyToMany;
use Kinetis\Vector\Fields\Money;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Fields\Textarea;
use Kinetis\Vector\VectorResource;

/**
 * The support desk's main resource, on PostgreSQL: every field shape the
 * MySQL products exercise — enums, a belongsTo, a nullable belongsTo, a
 * manyToMany, an owned hasMany, money, JSON, booleans, instants, soft
 * deletion, a version — so both dialects are covered by one feature set.
 */
#[Resource(entity: Ticket::class, group: 'Support', icon: 'mdi-lifebuoy', title: 'subject')]
final class TicketResource extends VectorResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('number')->required()->max(20)->sortable()->searchable()->filterable(),
            Text::make('subject')->required()->max(200)->sortable()->searchable()->filterable(),
            Textarea::make('body')->required()->hideFromIndex(),
            Enum::make('status')->of(TicketStatus::class)
                ->colors(['open' => 'info', 'pending' => 'warning', 'resolved' => 'success', 'closed' => 'grey'])
                ->sortable()->filterable(),
            Enum::make('priority')->of(TicketPriority::class)
                ->colors(['low' => 'grey', 'normal' => 'primary', 'high' => 'warning', 'urgent' => 'error'])
                ->sortable()->filterable(),
            Enum::make('channel')->of(\App\Domain\Support\Channel::class)->filterable()->hideFromIndex(),
            Email::make('customerEmail', 'Customer')->required()->max(255)->searchable()->filterable(),
            Number::make('customerRef', 'Customer id (shop)')->hideFromIndex()->help('The shop customer on the MySQL connection, by id: a relationship never spans two databases.'),
            BelongsTo::to('team', TeamResource::class)->sortable()->filterable(),
            BelongsTo::to('assignee', AgentResource::class)->dependsOn('team')->sortable()->filterable(),
            ManyToMany::to('labels', LabelResource::class),
            HasMany::to('replies', TicketReplyResource::class),
            Money::make('compensationCents', 'Compensation')->currencyField('currency')->sortable()->filterable()->summarize('sum'),
            Currency::make('currency')->hideFromIndex(),
            Number::make('satisfaction')->sortable()->filterable()->summarize('avg'),
            Boolean::make('isEscalated', 'Escalated')->sortable()->filterable(),
            Json::make('metadata')->hideFromIndex(),
            DateTime::make('dueAt', 'Due')->sortable()->filterable(),
            DateTime::make('resolvedAt')->hideFromIndex()->filterable(),
            DateTime::make('createdAt')->readonly()->sortable()->hideFromForms(),
            DateTime::make('updatedAt')->readonly()->hideFromForms()->hideFromIndex(),
        ];
    }

    public function badge(): Badge
    {
        return Badge::count(['status' => 'open'], 'info', 'open tickets');
    }

    public function searchSubtitle(): string
    {
        return 'number';
    }

    public function defaultSort(): array
    {
        return ['-createdAt'];
    }

    public function softDeletes(): string
    {
        return 'deletedAt';
    }

    public function actions(): array
    {
        return [EscalateTickets::class];
    }

    public function widgets(): array
    {
        return [TicketsByStatus::class];
    }
}
