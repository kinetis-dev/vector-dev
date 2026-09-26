<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

/** The write path against the seeded MySQL: every record it creates, it removes. */
final class RecordWriterTest extends VectorTestCase
{
    public function testCreateUpdateConflictAndDeleteOfAProduct(): void
    {
        $categoryId = $this->firstId('categories');
        $tagIds = $this->ids('tags', 2);
        $sku = 'SKU-TEST-' . bin2hex(random_bytes(4));

        $created = $this->client->post('/vector/r/products', body: [
            'sku' => $sku, 'name' => 'Test product', 'slug' => 'test-product-' . $sku, 'status' => 'draft',
            'category' => $categoryId, 'priceCents' => 1999, 'currency' => 'EUR', 'stock' => 3, 'weightKg' => 0.5,
            'isFeatured' => false, 'tags' => $tagIds,
        ], headers: $this->adminHeaders())
            ->assertCreated()
            ->assertJsonPath('data.sku', $sku)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.category.id', $categoryId)
            ->assertJsonPath('data.tags.count', 2)
            ->assertJsonPath('permissions.update', true)
            ->json();
        self::assertIsArray($created);
        $id = $created['data']['id'];
        self::assertIsInt($id);
        self::assertMatchesRegularExpression('/^\d{4}-/', $created['data']['createdAt'], 'Vector stamped createdAt');

        // a duplicate unique key lands on the field
        $this->client->post('/vector/r/products', body: [
            'sku' => $sku, 'name' => 'Dup', 'slug' => 'dup-' . $sku, 'status' => 'draft', 'category' => $categoryId,
            'priceCents' => 1, 'currency' => 'EUR', 'stock' => 0, 'weightKg' => 1, 'isFeatured' => false,
        ], headers: $this->adminHeaders())
            ->assertValidationError('sku');

        // update with the current version succeeds and replaces the join collection
        $this->client->request('PATCH', "/vector/r/products/{$id}", body: ['name' => 'Renamed', 'tags' => [$tagIds[0]]], headers: [...$this->adminHeaders(), 'If-Match' => '"1"'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.tags.count', 1);

        // the same stale version now conflicts
        $this->client->request('PATCH', "/vector/r/products/{$id}", body: ['stock' => 9], headers: [...$this->adminHeaders(), 'If-Match' => '"1"'])
            ->assertStatus(409);

        // refusals
        $this->client->request('PATCH', "/vector/r/products/{$id}", body: ['status' => 'nope', 'bogus' => 1, 'createdAt' => '2020-01-01T00:00:00Z', 'category' => 999999999], headers: $this->adminHeaders())
            ->assertValidationError('status')
            ->assertValidationError('bogus')
            ->assertValidationError('createdAt')
            ->assertValidationError('category');

        $this->client->get("/vector/r/products/{$id}/tags", headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('total', 1);

        // products soft-delete: the row stays, marked, out of the default list, restorable
        $this->client->delete("/vector/r/products/{$id}", headers: $this->adminHeaders())->assertStatus(204);
        $this->client->get("/vector/r/products/{$id}", headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data._trashed', true)
            ->assertJsonPath('permissions.update', false)
            ->assertJsonPath('permissions.restore', true);
        $this->client->get('/vector/r/products', query: ['filter' => ['sku' => $sku]], headers: $this->adminHeaders())->assertOk()->assertJsonPath('total', 0);
        $this->client->get('/vector/r/products', query: ['filter' => ['sku' => $sku, 'trashed' => 'only']], headers: $this->adminHeaders())->assertOk()->assertJsonPath('total', 1);
        $this->client->post("/vector/r/products/{$id}/restore", headers: $this->adminHeaders())->assertOk()->assertJsonPath('data._trashed', false);
        $this->client->request('DELETE', "/vector/r/products/{$id}", headers: $this->adminHeaders(), query: ['force' => '1'])->assertStatus(204);
        $this->client->get("/vector/r/products/{$id}", headers: $this->adminHeaders())->assertNotFound();

        // record_ids is a JSON column on both dialects; match the id after decoding, not with LIKE
        $events = array_values(array_filter(
            $this->vectorQuery()->table('vector_action_events')->where('resource', '=', 'products')->orderBy('id', 'ASC')->get(),
            static fn (array $event): bool => in_array((string) $id, json_decode((string) $event['record_ids'], true, 4, JSON_THROW_ON_ERROR), true),
        ));
        self::assertSame(['create', 'update', 'delete', 'restore', 'forceDelete'], array_column($events, 'action'));
    }

    public function testAnOwnedAggregateIsDeletedWithItsChildrenAndBatchDeleteCounts(): void
    {
        $customerId = $this->firstId('customers');
        $productId = $this->firstId('products');
        $number = 'ORD-TEST-' . bin2hex(random_bytes(3));

        $order = $this->client->post('/vector/r/orders', body: [
            'number' => $number, 'customer' => $customerId, 'status' => 'pending', 'currency' => 'EUR',
            'placedAt' => '2026-09-24T10:00:00Z',
            'lines' => [['product' => $productId, 'quantity' => 2, 'unitPriceCents' => 100]],
        ], headers: $this->adminHeaders())->assertCreated()->json();
        self::assertIsArray($order);
        $orderId = $order['data']['id'];
        self::assertSame(200, $order['data']['totalCents']);

        $this->client->post('/vector/r/order-lines', body: ['order' => $orderId, 'product' => $productId, 'quantity' => 1, 'unitPriceCents' => 50], headers: $this->adminHeaders())
            ->assertCreated()
            ->assertJsonPath('data.order.id', $orderId);
        $this->client->get("/vector/r/orders/{$orderId}/lines", headers: $this->adminHeaders())->assertOk()->assertJsonPath('total', 2);

        $this->client->post('/vector/r/orders/batch', body: ['action' => 'delete', 'ids' => [$orderId]], headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $lines = (new Query($this->link()))->table('order_lines')->where('order_id', '=', $orderId)->count();
        self::assertSame(0, $lines, 'the owned lines went with the order');
    }

    public function testAPolicyDecidesWhatTheDescriptorAndTheWriterAllow(): void
    {
        $this->client->get('/vector/schema/users', headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('permissions.create', false);
        $this->client->post('/vector/r/users', body: ['name' => 'x', 'email' => 'x@example.com'], headers: $this->adminHeaders())
            ->assertStatus(403);

        $self = (new Query($this->link()))->table('users')->where('email', '=', 'admin@vector.local')->first();
        self::assertIsArray($self);
        $headers = ['Authorization' => 'Bearer ' . $this->tokenFor((string) $self['id'])];
        $this->client->get("/vector/r/users/{$self['id']}", headers: $headers)->assertOk()->assertJsonPath('permissions.delete', false);
        $this->client->delete("/vector/r/users/{$self['id']}", headers: $headers)->assertStatus(403);
    }

    public function testMalformedBodiesAreRefusedBeforeAnyWrite(): void
    {
        $this->client->raw('POST', '/vector/r/products', '{not json', ['Content-Type' => 'application/json', ...$this->adminHeaders()])->assertStatus(422);
        $this->client->raw('POST', '/vector/r/products', '[1,2]', ['Content-Type' => 'application/json', ...$this->adminHeaders()])->assertStatus(422);
        $this->client->post('/vector/r/products/batch', body: ['action' => 'nuke', 'ids' => [1]], headers: $this->adminHeaders())->assertValidationError('action');
        $this->client->post('/vector/r/products', body: ['name' => 'x'], headers: $this->editorHeaders())->assertStatus(403);
    }

    private function link(): MysqlLink
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return $link;
    }

    private function firstId(string $table): int
    {
        $row = (new Query($this->link()))->table($table)->select('id')->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);

        return (int) $row['id'];
    }

    /** @return list<int> */
    private function ids(string $table, int $count): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], (new Query($this->link()))->table($table)->select('id')->orderBy('id', 'ASC')->limit($count)->get());
    }
}
