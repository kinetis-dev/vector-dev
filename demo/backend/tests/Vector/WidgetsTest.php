<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/** Dashboards and widgets: every card type, ranges, resource cards and the per-worker cache. */
final class WidgetsTest extends VectorTestCase
{
    public function testTheDashboardListsItsCardsAndEachComputesForARange(): void
    {
        $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->assertJsonPath('dashboards.0.name', 'main')->assertJsonPath('dashboards.0.label', 'Overview');

        $page = $this->client->get('/vector/dashboards/main', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($page);
        self::assertSame(['revenue-stat', 'orders-by-status', 'open-tickets-stat', 'orders-trend', 'low-stock-table'], array_column($page['data']['widgets'], 'name'));
        self::assertArrayNotHasKey('class', $page['data']['widgets'][0]);
        self::assertSame('7d', array_key_first($page['data']['widgets'][0]['ranges']));

        $stat = $this->client->get('/vector/dashboards/main/widgets/revenue-stat', query: ['range' => '1y'], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($stat);
        self::assertSame('stat', $stat['data']['type']);
        self::assertSame('money:EUR', $stat['data']['format']);
        self::assertGreaterThan(0, $stat['data']['value']);
        self::assertFalse($stat['data']['cached']);
        $again = $this->client->get('/vector/dashboards/main/widgets/revenue-stat', query: ['range' => '1y'], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($again);
        self::assertTrue($again['data']['cached'], 'a widget with cacheSeconds is served from the worker memo');

        $trend = $this->client->get('/vector/dashboards/main/widgets/orders-trend', query: ['range' => '30d'], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($trend);
        self::assertSame('trend', $trend['data']['type']);
        self::assertCount(30, $trend['data']['points']);
        self::assertSame(array_sum(array_column($trend['data']['points'], 'y')), $trend['data']['total']);

        $partition = $this->client->get('/vector/dashboards/main/widgets/orders-by-status', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($partition);
        self::assertSame('partition', $partition['data']['type']);
        self::assertSame('7d', $partition['data']['range'], 'the first range is the default');

        $table = $this->client->get('/vector/dashboards/main/widgets/low-stock-table', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($table);
        self::assertSame('table', $table['data']['type']);
        self::assertSame(['sku', 'name', 'stock'], array_column($table['data']['columns'], 'key'));
        self::assertStringStartsWith('/r/products/', $table['data']['rows'][0]['_link'] ?? '/r/products/');

        $progress = $this->client->get('/vector/r/products/widgets/products-by-status', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($progress);
        self::assertSame('progress', $progress['data']['type']);
        self::assertGreaterThan(0, $progress['data']['target']);

        $this->client->get('/vector/dashboards/main/widgets/revenue-stat', query: ['range' => '5y'], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/dashboards/main/widgets/nope', headers: $this->adminHeaders())->assertNotFound();
        $this->client->get('/vector/dashboards/nope', headers: $this->adminHeaders())->assertNotFound();
        $this->client->get('/vector/dashboards/main', headers: $this->editorHeaders())->assertStatus(403);
    }

    public function testTheStoreSettingsGroupDrivesTheLowStockWidget(): void
    {
        $this->client->get('/vector/settings/store', headers: $this->adminHeaders())->assertOk()->assertJsonPath('data.values.lowStockThreshold', 10);
        $this->client->request('PUT', '/vector/settings/store', body: ['storeName' => 'Vector Demo Store', 'lowStockThreshold' => 0], headers: $this->adminHeaders())->assertOk();
        $table = $this->client->get('/vector/dashboards/main/widgets/low-stock-table', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($table);

        foreach ($table['data']['rows'] as $row) {
            self::assertSame(0, $row['stock']);
        }

        self::assertStringContainsString('lte]=0', $table['data']['link']);
        $this->client->request('PUT', '/vector/settings/store', body: ['storeName' => 'Vector Demo Store', 'lowStockThreshold' => 10], headers: $this->adminHeaders())->assertOk();
    }
}
