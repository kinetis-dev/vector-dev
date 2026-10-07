<?php

declare(strict_types=1);

namespace App\Vector\Widgets;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetRequest;
use Kinetis\Vector\Widgets\WidgetResult;

/** Orders in the range by status. */
final class OrdersByStatus extends VectorWidget
{
    private const array COLORS = ['pending' => 'warning', 'paid' => 'info', 'shipped' => 'success', 'cancelled' => 'error'];

    public function __construct(private readonly SqlLink $db) {}

    #[\Override]
    public function label(): string
    {
        return 'Orders by status';
    }

    #[\Override]
    public function type(): string
    {
        return self::PARTITION;
    }

    #[\Override]
    public function width(): int
    {
        return 3;
    }

    #[\Override]
    public function calculate(WidgetRequest $request): WidgetResult
    {
        $rows = (new Query($this->db instanceof MysqlLink || $this->db instanceof PostgresLink ? $this->db : throw new \LogicException('Unsupported link.')))
            ->table('orders')
            ->select('status')
            ->selectRaw('COUNT(*) AS orders')
            ->where('placed_at', '>=', $request->sql($request->from))
            ->where('placed_at', '<', $request->sql($request->to))
            ->groupBy('status')
            ->orderBy('status', 'ASC')
            ->get();

        return WidgetResult::partition(array_map(static fn (array $row): array => [
            'label' => ucfirst((string) $row['status']),
            'value' => (int) $row['orders'],
            'color' => self::COLORS[(string) $row['status']] ?? null,
        ], $rows));
    }
}
