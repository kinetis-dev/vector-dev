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

/** A resource widget on the products list: the catalogue by status, and how much of it is published. */
final class ProductsByStatus extends VectorWidget
{
    public function __construct(private readonly SqlLink $db) {}

    #[\Override]
    public function type(): string
    {
        return self::PROGRESS;
    }

    #[\Override]
    public function label(): string
    {
        return 'Published';
    }

    #[\Override]
    public function ranges(): array
    {
        return [];
    }

    #[\Override]
    public function width(): int
    {
        return 4;
    }

    #[\Override]
    public function calculate(WidgetRequest $request): WidgetResult
    {
        $query = (new Query($this->db instanceof MysqlLink || $this->db instanceof PostgresLink ? $this->db : throw new \LogicException('Unsupported link.')))
            ->table('products')->where('deleted_at', '=', null);
        $total = (clone $query)->count();
        $active = (clone $query)->where('status', '=', 'active')->count();

        return WidgetResult::progress($active, $total, 'number', "{$active} of {$total} products are active");
    }
}
