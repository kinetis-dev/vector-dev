<?php

declare(strict_types=1);

namespace App\Domain\Support;

use DateTimeImmutable;
use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use stdClass;

#[Entity(table: 'ticket_replies', connection: 'support')]
final class TicketReply
{
    #[Id(generated: true)]
    private ?int $id = null;

    #[BelongsTo]
    private Ticket $ticket;

    private string $author;

    private string $body;

    private bool $isInternal;

    private DateTimeImmutable $createdAt;

    /** @var array<array-key, mixed>|stdClass|null free-form metadata: an array|stdClass property keeps {} an object */
    private array|stdClass|null $metadata = null;

    /** @param array<array-key, mixed>|stdClass|null $metadata */
    public function __construct(Ticket $ticket, string $author, string $body, bool $isInternal, DateTimeImmutable $createdAt, array|stdClass|null $metadata = null)
    {
        $this->ticket = $ticket;
        $this->author = $author;
        $this->body = $body;
        $this->isInternal = $isInternal;
        $this->createdAt = $createdAt;
        $this->metadata = $metadata;
    }
}
