<?php

declare(strict_types=1);

namespace App\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Store\SettingsStore;

/**
 * The endpoint behind the demo's custom "Stock overview" page (a runtime
 * extension in public/vector-extensions/stock-overview.js). It sits in
 * Vector's own `vector` middleware group, so the admin's credentials and
 * Vector's access policy apply exactly as on Vector's routes: an
 * application endpoint a custom page calls needs nothing else.
 */
#[Hidden]
#[Middleware('@vector')]
final readonly class StockOverviewController
{
    public function __construct(
        private MysqlLink $db,
        private SettingsStore $settings,
        private VectorRegistry $registry,
    ) {}

    /** @return array{threshold: int, categories: list<array{id: int, name: string, products: int, units: int, low: int}>} */
    #[Get('/vector-demo/stock-overview')]
    public function overview(): array
    {
        $threshold = (int) ($this->settings->values($this->registry->settingsGroup('store'))['lowStockThreshold'] ?? 10);
        $rows = (new Query($this->db))
            ->table('products')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw('categories.id AS id, categories.name AS name, COUNT(*) AS products, SUM(products.stock) AS units, SUM(CASE WHEN products.stock <= ? THEN 1 ELSE 0 END) AS low', [$threshold])
            ->where('products.deleted_at', '=', null)
            ->where('products.status', '=', 'active')
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('categories.name', 'ASC')
            ->get();

        return [
            'threshold' => $threshold,
            'categories' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'name' => (string) $r['name'], 'products' => (int) $r['products'], 'units' => (int) $r['units'], 'low' => (int) $r['low'],
            ], $rows),
        ];
    }
}
