<?php

declare(strict_types=1);

namespace App\Vector\Support\Actions;

use App\Domain\Support\Ticket;
use App\Domain\Support\TicketStatus;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;

/** An action on PostgreSQL entities: flushed by the "support" connection's manager. */
final class EscalateTickets extends VectorAction
{
    #[\Override]
    public function label(): string
    {
        return 'Escalate';
    }

    #[\Override]
    public function icon(): string
    {
        return 'mdi-alarm-light-outline';
    }

    #[\Override]
    public function canRun(CurrentUserInterface $user, ?array $record): bool
    {
        return $record === null || !in_array($record['status'] ?? null, [TicketStatus::Resolved->value, TicketStatus::Closed->value], true);
    }

    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        foreach ($request->entities() as $ticket) {
            if ($ticket instanceof Ticket) {
                $ticket->escalate();
            }
        }

        return ActionResponse::message("{$request->count()} escalated.");
    }
}
