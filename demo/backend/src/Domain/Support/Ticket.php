<?php

declare(strict_types=1);

namespace App\Domain\Support;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\ManyToMany;
use Kinetis\Orm\Attributes\Version;

/**
 * A support ticket, on PostgreSQL. The customer is referenced by value
 * — an e-mail and the MySQL customer's id — because a relationship never
 * spans two connections.
 */
#[Entity(table: 'tickets', connection: 'support')]
final class Ticket
{
    #[Id(generated: true)]
    private ?int $id = null;

    private string $number;

    private string $subject;

    private string $body;

    private TicketStatus $status;

    private TicketPriority $priority;

    private Channel $channel;

    private string $customerEmail;

    private ?int $customerRef = null;

    #[BelongsTo]
    private Team $team;

    #[BelongsTo]
    private ?Agent $assignee;

    /** @var list<Label> */
    #[ManyToMany(target: Label::class, table: 'ticket_label', joinColumn: 'ticket_id', inverseJoinColumn: 'label_id')]
    private array $labels;

    /** @var list<TicketReply> */
    #[HasMany(target: TicketReply::class, mappedBy: 'ticket', owned: true)]
    private array $replies;

    private ?string $metadata = null;

    private int $compensationCents;

    private string $currency;

    private ?int $satisfaction = null;

    private bool $isEscalated;

    private ?DateTimeImmutable $dueAt = null;

    private ?DateTimeImmutable $resolvedAt = null;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    private ?DateTimeImmutable $deletedAt = null;

    #[Version]
    private int $version = 1;

    public function __construct(string $number, string $subject, string $body, Team $team, Channel $channel, string $customerEmail, ?int $customerRef, DateTimeImmutable $createdAt)
    {
        $this->number = $number;
        $this->subject = $subject;
        $this->body = $body;
        $this->team = $team;
        $this->channel = $channel;
        $this->customerEmail = $customerEmail;
        $this->customerRef = $customerRef;
        $this->status = TicketStatus::Open;
        $this->priority = TicketPriority::Normal;
        $this->compensationCents = 0;
        $this->currency = 'EUR';
        $this->assignee = null;
        $this->isEscalated = false;
        $this->labels = [];
        $this->replies = [];
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function prioritize(TicketPriority $priority, ?DateTimeImmutable $dueAt): void
    {
        $this->priority = $priority;
        $this->dueAt = $dueAt;
        $this->isEscalated = $priority === TicketPriority::Urgent;
    }

    public function assign(?Agent $agent): void
    {
        $this->assignee = $agent;
    }

    /** @param list<Label> $labels */
    public function label(array $labels): void
    {
        $this->labels = $labels;
    }

    public function reply(string $author, string $body, bool $internal, DateTimeImmutable $at): void
    {
        $this->replies[] = new TicketReply($this, $author, $body, $internal, $at);

        if (!$internal && $this->status === TicketStatus::Open) {
            $this->status = TicketStatus::Pending;
        }
    }

    public function resolve(DateTimeImmutable $at, ?int $satisfaction, int $compensationCents = 0): void
    {
        $this->status = TicketStatus::Resolved;
        $this->resolvedAt = $at;
        $this->satisfaction = $satisfaction;
        $this->compensationCents = $compensationCents;
        $this->updatedAt = $at;
    }

    public function close(): void
    {
        $this->status = TicketStatus::Closed;
    }

    public function annotate(?string $metadataJson): void
    {
        $this->metadata = $metadataJson;
    }

    public function escalate(): void
    {
        $this->isEscalated = true;
        $this->priority = TicketPriority::Urgent;
    }

    public function status(): TicketStatus
    {
        return $this->status;
    }
}
