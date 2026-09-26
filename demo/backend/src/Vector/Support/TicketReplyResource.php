<?php

declare(strict_types=1);

namespace App\Vector\Support;

use App\Domain\Support\TicketReply;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\VectorResource;

#[Resource(entity: TicketReply::class, slug: 'ticket-replies', label: 'Ticket replies', group: 'Support', icon: 'mdi-reply-outline', title: 'author')]
final class TicketReplyResource extends VectorResource {}
