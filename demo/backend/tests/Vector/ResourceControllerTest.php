<?php

declare(strict_types=1);

namespace App\Tests\Vector;

final class ResourceControllerTest extends VectorTestCase
{
    public function testAListIsPagedSortedAndCarriesRelationDisplayValues(): void
    {
        $response = $this->client->get('/vector/r/products', query: ['perPage' => 5, 'sort' => '-priceCents,name'], headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('currentPage', 1)
            ->assertJsonPath('perPage', 5);

        $page = $response->json();
        self::assertIsArray($page);
        self::assertCount(5, $page['data']);
        self::assertGreaterThan(5, $page['total']);

        $prices = array_column($page['data'], 'priceCents');
        $sorted = $prices;
        rsort($sorted);
        self::assertSame($sorted, $prices, 'sorted by price, descending');

        $first = $page['data'][0];
        self::assertSame(['id', 'label'], array_keys($first['category']), 'a belongsTo is an id and its title');
        self::assertArrayHasKey('count', $first['tags'], 'a manyToMany lists as a count');
        self::assertIsBool($first['isFeatured']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $first['createdAt']);
        self::assertArrayNotHasKey('description', $first, 'a field hidden from the index is not selected');
    }

    public function testAListPageIsABoundedNumberOfStatements(): void
    {
        $statements = $this->countStatements(function (): void {
            $this->client->get('/vector/r/products', query: ['perPage' => 50], headers: $this->adminHeaders())->assertOk();
        });

        // COUNT, the page, the category labels, the tag counts — never one per row.
        self::assertLessThanOrEqual(4, $statements);
        self::assertGreaterThanOrEqual(2, $statements);

        // a search through the category's title and the name's full-text index adds no statement
        $link = $this->app->get(\Kinetis\Persistence\Contract\MysqlLink::class);
        self::assertInstanceOf(\Kinetis\Persistence\Contract\MysqlLink::class, $link);
        $laptops = (int) (new \Kinetis\QueryBuilder\Query($link))->table('categories')->where('name', '=', 'Laptops')->value('id');
        $pages = [];
        $filtered = $this->countStatements(function () use ($laptops, &$pages): void {
            $pages[] = $this->client->get('/vector/r/products', query: ['perPage' => 50, 'filter' => ['category' => (string) $laptops]], headers: $this->adminHeaders())->assertOk()->json();
        });
        $searched = $this->countStatements(function () use (&$pages): void {
            $pages[] = $this->client->get('/vector/r/products', query: ['perPage' => 50, 'q' => 'Laptop'], headers: $this->adminHeaders())->assertOk()->json();
        });
        self::assertIsArray($pages[0]);
        self::assertIsArray($pages[1]);
        self::assertGreaterThan(0, $pages[0]['total']);
        self::assertSame(array_column($pages[0]['data'], 'id'), array_column($pages[1]['data'], 'id'), 'the same records');
        self::assertSame(4, $searched, 'COUNT, the page, the category labels, the tag counts');
        self::assertSame($filtered, $searched);
    }

    public function testAShowCarriesEveryShowFieldAndTheHasOneAndHasManyDisplays(): void
    {
        $link = $this->app->get(\Kinetis\Persistence\Contract\MysqlLink::class);
        self::assertInstanceOf(\Kinetis\Persistence\Contract\MysqlLink::class, $link);
        $first = (int) (new \Kinetis\QueryBuilder\Query($link))->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->value('id');

        $this->client->get("/vector/r/products/{$first}", headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('permissions.update', true);

        $customer = $this->client->get('/vector/r/customers', query: ['perPage' => 1], headers: $this->adminHeaders())
            ->assertOk()
            ->json();
        self::assertIsArray($customer);
        $record = $customer['data'][0];
        self::assertArrayHasKey('orders', $record);
        self::assertArrayHasKey('count', $record['orders']);
        self::assertTrue($record['profile'] === null || array_keys($record['profile']) === ['id', 'label']);
    }

    public function testRefusalsNameTheirReason(): void
    {
        $this->client->get('/vector/r/nope', headers: $this->adminHeaders())->assertNotFound();
        $this->client->get('/vector/r/products/999999', headers: $this->adminHeaders())->assertNotFound();
        $this->client->get('/vector/r/products/abc', headers: $this->adminHeaders())->assertNotFound();
        $this->client->get('/vector/r/products', query: ['sort' => 'bogus'], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['sort' => 'description'], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['perPage' => 5000], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['page' => 0], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/products')->assertStatus(401);
        $this->client->get('/vector/r/products', headers: $this->editorHeaders())->assertStatus(403);
    }

    public function testSequentialRequestsInOneApplicationDoNotShareIdentity(): void
    {
        $this->client->get('/vector/r/products', query: ['perPage' => 1], headers: $this->adminHeaders())->assertOk();
        $this->client->get('/vector/r/products', query: ['perPage' => 1], headers: $this->editorHeaders())->assertStatus(403);
        $this->client->get('/vector/r/products', query: ['perPage' => 1])->assertStatus(401);
        $this->client->get('/vector/r/products', query: ['perPage' => 1], headers: $this->adminHeaders())->assertOk();
    }
}
