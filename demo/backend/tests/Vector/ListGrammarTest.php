<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/** Filters, search, grouping, summaries, cursor pagination and export against the seeded products. */
final class ListGrammarTest extends VectorTestCase
{
    public function testFiltersCompileToTypedPredicates(): void
    {
        $active = $this->client->get('/vector/r/products', query: ['filter' => ['status' => 'active'], 'perPage' => 200], headers: $this->adminHeaders())
            ->assertOk()->json();
        self::assertIsArray($active);
        self::assertGreaterThan(0, $active['total']);
        self::assertSame(['active'], array_values(array_unique(array_column($active['data'], 'status'))));

        $notActive = $this->client->get('/vector/r/products', query: ['filter' => ['status' => ['nin' => 'active,archived']]], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($notActive);
        self::assertSame(['draft'], array_values(array_unique(array_column($notActive['data'], 'status'))));

        $pricey = $this->client->get('/vector/r/products', query: ['filter' => ['priceCents' => ['gte' => '50000']], 'sort' => 'priceCents', 'perPage' => 5], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($pricey);
        self::assertGreaterThanOrEqual(50000, $pricey['data'][0]['priceCents'] ?? 50000);

        $between = $this->client->get('/vector/r/products', query: ['filter' => ['stock' => ['between' => '10,20']], 'perPage' => 200], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($between);

        foreach ($between['data'] as $row) {
            self::assertGreaterThanOrEqual(10, $row['stock']);
            self::assertLessThanOrEqual(20, $row['stock']);
        }

        $unreleased = $this->client->get('/vector/r/products', query: ['filter' => ['releasedAt' => ['null' => '']], 'perPage' => 200], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($unreleased);
        self::assertSame([null], array_values(array_unique(array_column($unreleased['data'], 'releasedAt'))));

        $day = $this->client->get('/vector/r/products', query: ['filter' => ['releasedAt' => ['lte' => '2026-01-01']], 'perPage' => 1], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($day);

        $byCategory = $this->client->get('/vector/r/products', query: ['filter' => ['category' => ['in' => '13,14']], 'perPage' => 200], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($byCategory);
        self::assertEmpty(array_diff(array_unique(array_map(static fn (array $r): int => $r['category']['id'], $byCategory['data'])), [13, 14]));

        $featured = $this->client->get('/vector/r/products', query: ['filter' => ['isFeatured' => 'true'], 'perPage' => 200], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($featured);
        self::assertSame([true], array_values(array_unique(array_column($featured['data'], 'isFeatured'))));
    }

    public function testFilterRefusalsAre422(): void
    {
        $this->client->get('/vector/r/products', query: ['filter' => ['description' => 'x']], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['filter' => ['status' => ['like' => 'x']]], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['filter' => ['priceCents' => ['gt' => 'abc']]], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['filter' => ['status' => 'nope']], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['filter' => ['stock' => ['between' => '1']]], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['filter' => ['tags' => '1']], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/categories', query: ['filter' => ['trashed' => 'only']], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['group' => 'description'], headers: $this->adminHeaders())->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['cursor' => '', 'sort' => 'name'], headers: $this->adminHeaders())->assertStatus(422);
    }

    public function testSearchGroupingSummariesAndCursor(): void
    {
        $search = $this->client->get('/vector/r/products', query: ['q' => 'SKU-0001', 'perPage' => 50], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($search);
        self::assertSame(10, $search['total'], 'SKU-00010 to SKU-00019');

        $grouped = $this->client->get('/vector/r/products', query: ['group' => 'status', 'sort' => '-priceCents', 'perPage' => 200, 'summaries' => '1'], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($grouped);
        $statuses = array_column($grouped['data'], 'status');
        $sorted = $statuses;
        sort($sorted);
        self::assertSame($sorted, $statuses, 'grouped rows arrive in group order');
        self::assertArrayHasKey('summaries', $grouped);
        self::assertIsFloat($grouped['summaries']['priceCents']);
        self::assertIsInt($grouped['summaries']['stock']);
        self::assertSame(array_sum(array_column($grouped['data'], 'stock')), $grouped['summaries']['stock'], 'the summary covers the same rows');

        $statements = $this->countStatements(function (): void {
            $this->client->get('/vector/r/products', query: ['perPage' => 50, 'summaries' => '1', 'q' => 'a', 'filter' => ['status' => 'active']], headers: $this->adminHeaders())->assertOk();
        });
        self::assertLessThanOrEqual(5, $statements, 'COUNT, page, labels, counts, summaries');

        $first = $this->client->get('/vector/r/products', query: ['cursor' => '', 'perPage' => 3], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($first);
        self::assertCount(3, $first['data']);
        self::assertTrue($first['hasMore']);
        self::assertArrayNotHasKey('total', $first);
        $second = $this->client->get('/vector/r/products', query: ['cursor' => $first['nextCursor'], 'perPage' => 3], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($second);
        self::assertGreaterThan($first['data'][2]['id'], $second['data'][0]['id']);
    }

    public function testGlobalSearchSpansResources(): void
    {
        $results = $this->client->get('/vector/search', query: ['q' => 'Ada'], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($results);
        self::assertNotEmpty($results['results']);
        self::assertContains('customers', array_column($results['results'], 'resource'));
        self::assertSame(['resource', 'resourceLabel', 'id', 'title', 'subtitle', 'image'], array_keys($results['results'][0]));
        $this->client->get('/vector/search', query: ['q' => ''], headers: $this->adminHeaders())->assertOk()->assertJsonPath('results', []);
    }

    public function testColumnsAlwaysKeepTheRowsIdentity(): void
    {
        // the list shows SKU and name only, but still links each row to its record
        $page = $this->client->get('/vector/r/products', query: ['columns' => 'sku,name', 'q' => 'SKU-00042'], headers: $this->adminHeaders())
            ->assertOk()->json();
        self::assertIsArray($page);
        self::assertSame(1, $page['total']);
        self::assertSame(['id', 'sku', 'name', '_trashed', '_version'], array_keys($page['data'][0]));
        self::assertIsInt($page['data'][0]['id']);

        // asked for, the key is not repeated and stays where it was asked
        $withId = $this->client->get('/vector/r/products', query: ['columns' => 'sku,id', 'q' => 'SKU-00042'], headers: $this->adminHeaders())
            ->assertOk()->json();
        self::assertIsArray($withId);
        self::assertSame(['sku', 'id', '_trashed', '_version'], array_keys($withId['data'][0]));
    }

    public function testExportIsCsvUnderTheSameGrammar(): void
    {
        $response = $this->client->get('/vector/r/products/export', query: ['filter' => ['status' => 'archived'], 'columns' => 'sku,name,category,priceCents'], headers: $this->adminHeaders())
            ->assertOk();
        self::assertStringStartsWith('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment; filename="products-', $response->getHeaderLine('Content-Disposition'));
        $lines = explode("\r\n", trim($response->body()));
        self::assertSame("\xEF\xBB\xBF" . 'SKU,Name,Category,Price', $lines[0]);
        self::assertGreaterThan(1, count($lines));
        self::assertMatchesRegularExpression('/^SKU-\d{5},/', $lines[1]);

        $this->client->get('/vector/r/products/export', query: ['format' => 'ods'], headers: $this->adminHeaders())->assertValidationError('format');
    }
}
