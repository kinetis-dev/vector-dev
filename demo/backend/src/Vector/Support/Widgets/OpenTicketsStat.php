<?php

declare(strict_types=1);

namespace App\Vector\Support\Widgets;

use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetRequest;
use Kinetis\Vector\Widgets\WidgetResult;

/** Tickets opened in the range against the range before, on PostgreSQL. */
final class OpenTicketsStat extends VectorWidget
{
    public function __construct(private readonly EntityManagerRegistry $managers) {}

    #[\Override]
    public function label(): string
    {
        return 'New tickets';
    }

    #[\Override]
    public function type(): string
    {
        return self::STAT;
    }

    #[\Override]
    public function icon(): string
    {
        return 'mdi-lifebuoy';
    }

    #[\Override]
    public function width(): int
    {
        return 3;
    }

    #[\Override]
    public function calculate(WidgetRequest $request): WidgetResult
    {
        $count = fn (\DateTimeImmutable $from, \DateTimeImmutable $to): int => $this->managers->manager('support')->builder()
            ->table('tickets')
            ->where('deleted_at', '=', null)
            ->where('created_at', '>=', $request->sql($from))
            ->where('created_at', '<', $request->sql($to))
            ->count();

        return WidgetResult::stat($count($request->from, $request->to), $count($request->previousFrom, $request->previousTo), 'number', 'on the support database');
    }
}
