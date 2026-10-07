<?php

declare(strict_types=1);

namespace App\Vector\Actions;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;

/** A standalone action: a CSV of products per status, downloaded straight from the dialog. */
final class CatalogSummary extends VectorAction
{
    public function __construct(private readonly SqlLink $db) {}

    #[\Override]
    public function label(): string
    {
        return 'Download catalog summary';
    }

    #[\Override]
    public function icon(): string
    {
        return 'mdi-file-chart-outline';
    }

    #[\Override]
    public function standalone(): bool
    {
        return true;
    }

    #[\Override]
    public function showOnDetail(): bool
    {
        return false;
    }

    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        $link = $this->db;
        $csv = "status,products,stock\r\n";

        if ($link instanceof \Kinetis\Persistence\Contract\MysqlLink || $link instanceof \Kinetis\Persistence\Contract\PostgresLink) {
            $rows = (new Query($link))->table('products')
                ->select('status')
                ->selectRaw('COUNT(*) AS products, SUM(stock) AS stock')
                ->where('deleted_at', '=', null)
                ->groupBy('status')
                ->orderBy('status', 'ASC')
                ->get();

            foreach ($rows as $row) {
                $csv .= "{$row['status']},{$row['products']},{$row['stock']}\r\n";
            }
        }

        return ActionResponse::download($csv, 'catalog-summary.csv');
    }
}
