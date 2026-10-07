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

/** Orders per day over the range, one grouped statement. */
final class OrdersTrend extends VectorWidget
{
    public function __construct(private readonly SqlLink $db) {}

    #[\Override]
    public function label(): string
    {
        return 'Orders per day';
    }

    #[\Override]
    public function type(): string
    {
        return self::TREND;
    }

    #[\Override]
    public function width(): int
    {
        return 6;
    }

    #[\Override]
    public function cacheSeconds(): int
    {
        return 60;
    }

    /** The same series for every admin: no user, no scope in the query. */
    #[\Override]
    public function cacheShared(): bool
    {
        return true;
    }

    #[\Override]
    public function calculate(WidgetRequest $request): WidgetResult
    {
        $rows = (new Query($this->db instanceof MysqlLink || $this->db instanceof PostgresLink ? $this->db : throw new \LogicException('Unsupported link.')))
            ->table('orders')
            ->selectRaw('DATE(placed_at) AS day, COUNT(*) AS orders')
            ->where('placed_at', '>=', $request->sql($request->from))
            ->where('placed_at', '<', $request->sql($request->to))
            ->groupBy('day')
            ->orderBy('day', 'ASC')
            ->get();
        $byDay = [];

        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = (int) $row['orders'];
        }

        $points = [];
        $total = 0;

        $first = $request->to->setTime(0, 0)->modify('-' . ($request->days() - 1) . ' days');

        for ($day = $first; $day <= $request->to; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $points[] = ['x' => $key, 'y' => $byDay[$key] ?? 0];
            $total += $byDay[$key] ?? 0;
        }

        return WidgetResult::trend($points, 'number', $total);
    }
}
