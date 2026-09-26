<?php

declare(strict_types=1);

namespace App\Vector\Support\Widgets;

use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetRequest;
use Kinetis\Vector\Widgets\WidgetResult;

/** Open work by status, on the support connection: one grouped statement on PostgreSQL. */
final class TicketsByStatus extends VectorWidget
{
    private const array COLORS = ['open' => 'info', 'pending' => 'warning', 'resolved' => 'success', 'closed' => 'grey'];

    public function __construct(private readonly EntityManagerRegistry $managers) {}

    public function label(): string
    {
        return 'Tickets by status';
    }

    public function type(): string
    {
        return self::PARTITION;
    }

    public function width(): int
    {
        return 6;
    }

    public function calculate(WidgetRequest $request): WidgetResult
    {
        $rows = $this->managers->manager('support')->builder()
            ->table('tickets')
            ->select('status')
            ->selectRaw('COUNT(*) AS tickets')
            ->where('deleted_at', '=', null)
            ->where('created_at', '>=', $request->sql($request->from))
            ->where('created_at', '<', $request->sql($request->to))
            ->groupBy('status')
            ->orderBy('status', 'ASC')
            ->get();

        return WidgetResult::partition(array_map(static fn (array $row): array => [
            'label' => ucfirst((string) $row['status']),
            'value' => (int) $row['tickets'],
            'color' => self::COLORS[(string) $row['status']] ?? null,
        ], $rows));
    }
}
