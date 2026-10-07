<?php

declare(strict_types=1);

namespace App\Vector\Support;

use App\Domain\Support\TicketReply;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Markdown;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\VectorResource;

#[Resource(entity: TicketReply::class, slug: 'ticket-replies', label: 'Ticket replies', group: 'Support', icon: 'mdi-reply-outline', title: 'author')]
final class TicketReplyResource extends VectorResource
{
    #[\Override]
    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::to('ticket', TicketResource::class)->sortable()->filterable(),
            Text::make('author')->required()->max(120)->sortable()->searchable(),
            // technical support text: Markdown source, edited WYSIWYG, shown rendered
            Markdown::make('body')->required()->hideFromIndex()->columnSpan('full'),
            Boolean::make('isInternal', 'Internal')->sortable()->filterable(),
            DateTime::make('createdAt')->readonly()->hideFromForms()->sortable()->filterable(),
        ];
    }
}
