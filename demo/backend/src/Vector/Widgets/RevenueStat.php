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

/** Revenue of paid and shipped orders in the range, against the range before it. */
final class RevenueStat extends VectorWidget
{
    public function __construct(private readonly SqlLink $db) {}

    public function label(): string
    {
        return 'Revenue';
    }

    public function type(): string
    {
        return self::STAT;
    }

    public function icon(): string
    {
        return 'mdi-cash-multiple';
    }

    public function width(): int
    {
        return 3;
    }

    public function cacheSeconds(): int
    {
        return 60;
    }

    public function calculate(WidgetRequest $request): WidgetResult
    {
        $sum = fn (\DateTimeImmutable $from, \DateTimeImmutable $to): int => (int) ($this->query()->table('orders')
            ->whereIn('status', ['paid', 'shipped'])
            ->where('placed_at', '>=', $request->sql($from))
            ->where('placed_at', '<', $request->sql($to))
            ->sum('total_cents') ?? 0);

        return WidgetResult::stat($sum($request->from, $request->to), $sum($request->previousFrom, $request->previousTo), 'money:EUR', 'paid and shipped orders');
    }

    private function query(): Query
    {
        return new Query($this->db instanceof MysqlLink || $this->db instanceof PostgresLink ? $this->db : throw new \LogicException('Unsupported link.'));
    }
}
