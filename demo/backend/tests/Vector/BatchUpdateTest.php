<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

/**
 * `POST /vector/r/{resource}/batch` with `action: update` writes the same
 * values to several records: validated once, authorized and audited per
 * record, and flushed in one transaction.
 */
final class BatchUpdateTest extends VectorTestCase
{
    public function testTheSameValuesReachEverySelectedRecordWithAnAuditEntryEach(): void
    {
        $h = $this->realAdminHeaders();
        $rows = $this->products(3);
        $ids = array_column($rows, 'id');

        try {
            $this->client->post('/vector/r/products/batch', body: ['action' => 'update', 'ids' => $ids, 'fields' => ['isFeatured' => true, 'stock' => 7]], headers: $h)
                ->assertOk()
                ->assertJsonPath('affected', 3);

            foreach ($ids as $id) {
                $this->client->get("/vector/r/products/{$id}", headers: $h)->assertOk()
                    ->assertJsonPath('data.isFeatured', true)
                    ->assertJsonPath('data.stock', 7);
                $trail = $this->client->get("/vector/r/products/{$id}/audit", headers: $h)->assertOk()->json();
                self::assertIsArray($trail);
                self::assertSame('update', $trail['data'][0]['event']);
                self::assertSame(7, $trail['data'][0]['changes']['stock']['to']);
            }

            // a selection by filter works the same way
            $this->client->post('/vector/r/products/batch', body: ['action' => 'update', 'all' => ['filter' => ['sku' => ['in' => implode(',', array_column($rows, 'sku'))]]], 'fields' => ['stock' => 8]], headers: $h)
                ->assertOk()
                ->assertJsonPath('affected', 3);
            self::assertSame([8, 8, 8], array_map('intval', array_column($this->shop()->table('products')->select('stock')->whereIn('id', $ids)->orderBy('id', 'ASC')->get(), 'stock')));
        } finally {
            $this->restore($rows);
        }
    }

    public function testInvalidValuesAndEmptyChangesAreRefusedBeforeAnythingIsWritten(): void
    {
        $h = $this->realAdminHeaders();
        $rows = $this->products(2);
        $ids = array_column($rows, 'id');

        $this->client->post('/vector/r/products/batch', body: ['action' => 'update', 'ids' => $ids, 'fields' => ['stock' => 'many']], headers: $h)->assertStatus(422);
        $this->client->post('/vector/r/products/batch', body: ['action' => 'update', 'ids' => $ids, 'fields' => []], headers: $h)->assertStatus(422);
        $this->client->post('/vector/r/products/batch', body: ['action' => 'update', 'ids' => $ids, 'fields' => [1, 2]], headers: $h)->assertStatus(422);
        $this->client->post('/vector/r/products/batch', body: ['action' => 'update', 'ids' => $ids, 'fields' => ['bogus' => 1]], headers: $h)->assertStatus(422);

        self::assertSame(array_column($rows, 'stock'), array_column($this->current($ids), 'stock'));
    }

    public function testAConflictOnOneRecordChangesNone(): void
    {
        $h = $this->realAdminHeaders();
        $rows = $this->products(2);
        $ids = array_column($rows, 'id');

        // one SKU for two records breaks the unique index: the whole batch is refused
        $this->client->post('/vector/r/products/batch', body: ['action' => 'update', 'ids' => $ids, 'fields' => ['sku' => 'SAME-SKU', 'stock' => 99]], headers: $h)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.path', ['sku']);

        self::assertSame(array_column($rows, 'sku'), array_column($this->current($ids), 'sku'));
        self::assertSame(array_column($rows, 'stock'), array_column($this->current($ids), 'stock'));
    }

    /** @return list<array{id: int, sku: string, stock: int, isFeatured: bool}> */
    private function products(int $count): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'sku' => (string) $r['sku'], 'stock' => (int) $r['stock'], 'isFeatured' => (bool) $r['is_featured']],
            $this->shop()->table('products')->select('id', 'sku', 'stock', 'is_featured')->where('deleted_at', '=', null)->where('status', '=', 'active')->orderBy('id', 'DESC')->limit($count)->get());
    }

    /**
     * @param list<int> $ids
     * @return list<array{id: int, sku: string, stock: int, isFeatured: bool}>
     */
    private function current(array $ids): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'sku' => (string) $r['sku'], 'stock' => (int) $r['stock'], 'isFeatured' => (bool) $r['is_featured']],
            $this->shop()->table('products')->select('id', 'sku', 'stock', 'is_featured')->whereIn('id', $ids)->orderBy('id', 'DESC')->get());
    }

    /** @param list<array{id: int, sku: string, stock: int, isFeatured: bool}> $rows */
    private function restore(array $rows): void
    {
        foreach ($rows as $row) {
            $this->shop()->table('products')->where('id', '=', $row['id'])->update(['stock' => $row['stock'], 'is_featured' => $row['isFeatured'] ? 1 : 0]);
        }
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
