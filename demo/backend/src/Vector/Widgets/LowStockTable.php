<?php

declare(strict_types=1);

namespace App\Vector\Widgets;

use App\Vector\Settings\StoreSettings;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Store\SettingsStore;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetRequest;
use Kinetis\Vector\Widgets\WidgetResult;

/** The active products at or below the store's low-stock threshold — a settings value read at request time. */
final class LowStockTable extends VectorWidget
{
    public function __construct(
        private readonly SqlLink $db,
        private readonly SettingsStore $settings,
        private readonly VectorRegistry $registry,
    ) {}

    #[\Override]
    public function label(): string
    {
        return 'Low stock';
    }

    #[\Override]
    public function type(): string
    {
        return self::TABLE;
    }

    #[\Override]
    public function icon(): string
    {
        return 'mdi-package-variant-closed-remove';
    }

    #[\Override]
    public function ranges(): array
    {
        return [];
    }

    #[\Override]
    public function width(): int
    {
        return 6;
    }

    #[\Override]
    public function calculate(WidgetRequest $request): WidgetResult
    {
        $threshold = (int) ($this->settings->values($this->registry->settingsGroup('store'))['lowStockThreshold'] ?? 10);
        $rows = (new Query($this->db instanceof MysqlLink || $this->db instanceof PostgresLink ? $this->db : throw new \LogicException('Unsupported link.')))
            ->table('products')
            ->select('id', 'sku', 'name', 'stock')
            ->where('status', '=', 'active')
            ->where('deleted_at', '=', null)
            ->where('stock', '<=', $threshold)
            ->orderBy('stock', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(10)
            ->get();

        return WidgetResult::table(
            [['key' => 'sku', 'label' => 'SKU'], ['key' => 'name', 'label' => 'Product'], ['key' => 'stock', 'label' => 'Stock', 'align' => 'end']],
            array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'sku' => (string) $row['sku'], 'name' => (string) $row['name'], 'stock' => (int) $row['stock'], '_link' => '/r/products/' . (int) $row['id']], $rows),
            '/r/products?filter[stock][lte]=' . $threshold . '&filter[status]=active',
        );
    }
}
