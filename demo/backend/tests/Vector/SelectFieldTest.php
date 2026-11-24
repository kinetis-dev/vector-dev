<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Product;
use App\Domain\ProductStatus;
use App\Vector\ProductResource;
use App\Vector\Support\TicketResource;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Database\Connections;
use Kinetis\Vector\Database\JsonFilter;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Select;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;

/**
 * The select field: one value in a scalar or enum column, or several in a
 * JSON column; strict about its options on write and on read; filtered
 * by "any" or "all" of a list on both dialects.
 */
final class SelectFieldTest extends VectorTestCase
{
    use InteractsWithVector;

    private ?EntityManager $supportManager = null;

    public function testStorageMustMatchTheMode(): void
    {
        $cases = [
            'a single select on an array property' => [new #[Resource(Product::class, slug: 'select-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Select::make('channels')->options(ProductResource::CHANNELS)];
                }
            }, 'is a single select on an array property; call multiple()'],
            'a multiple select on a string property' => [new #[Resource(Product::class, slug: 'select-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Select::make('sku')->options(['a' => 'A'])->multiple()];
                }
            }, 'is a multiple select, which stores a JSON list: declare the property array or ?array, not string'],
            'a multiple select on an enum property' => [new #[Resource(Product::class, slug: 'select-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Select::make('status')->multiple()];
                }
            }, 'is a multiple select, which stores a JSON list'],
            'a select on a float property' => [new #[Resource(Product::class, slug: 'select-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Select::make('weightKg')->options(['1' => 'One'])];
                }
            }, 'stores its value in a string, int or backed enum property, not float'],
            'an int property with text options' => [new #[Resource(Product::class, slug: 'select-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Select::make('stock')->options(['low' => 'Low'])];
                }
            }, 'stores an int, but not every option value is an integer'],
            'options outside the enum' => [new #[Resource(Product::class, slug: 'select-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Select::make('status')->options(['gone' => 'Gone'])];
                }
            }, 'offers "gone", which is not a case of'],
            'a select with no options' => [new #[Resource(Product::class, slug: 'select-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Select::make('sku')];
                }
            }, 'is a select with no options'],
            'a sorted multiple select' => [new #[Resource(Product::class, slug: 'select-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Select::make('channels')->options(ProductResource::CHANNELS)->multiple()->sortable()];
                }
            }, 'cannot be sorted, grouped, summarized or edited inline'],
            'an inline multiple select' => [new #[Resource(Product::class, slug: 'select-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Select::make('channels')->options(ProductResource::CHANNELS)->multiple()->inlineEditable()];
                }
            }, 'cannot be sorted, grouped, summarized or edited inline'],
            'a default outside the options' => [new #[Resource(Product::class, slug: 'select-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Select::make('channels')->options(ProductResource::CHANNELS)->multiple()->default(['web', 'phone'])];
                }
            }, 'defaults to "phone", which is not one of its options'],
        ];

        foreach ($cases as $case => [$resource, $message]) {
            try {
                $this->compileProducts($resource);
                self::fail("compiled: {$case}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
        }

        $valid = $this->compileProducts(new #[Resource(Product::class, slug: 'select-products')] class extends VectorResource {
            public function fields(): array
            {
                return [
                    Id::make(),
                    Select::make('status'),
                    Select::make('stock')->options([0 => 'None', 10 => 'Ten']),
                    Select::make('sku')->options([404 => 'Not found', 'ok' => 'Fine'])->searchable(),
                    Select::make('channels')->options(ProductResource::CHANNELS)->multiple()->filterable()->badge(['web' => 'primary']),
                ];
            }
        });
        $fields = array_column($valid['fields'], null, 'name');

        self::assertSame(['draft', 'active', 'archived'], array_column($fields['status']['options'], 'value'), 'an enum property with no options offers its cases');
        self::assertSame(ProductStatus::class, $fields['status']['enum']);
        self::assertSame([0, 10], array_column($fields['stock']['options'], 'value'), 'an int property keeps int values');
        self::assertSame(['404', 'ok'], array_column($fields['sku']['options'], 'value'), 'a string property stores every value as text');
        self::assertSame([true, false], [$fields['sku']['autocomplete'], $fields['sku']['searchable']], 'searchable() is the input\'s search box, not the list\'s search');
        self::assertSame([true, 'json', ['any', 'all', 'null', 'nnull'], true, 'primary'], [$fields['channels']['multiple'], $fields['channels']['valueType'], $fields['channels']['operators'], $fields['channels']['badge'], $fields['channels']['options'][0]['color']]);
        self::assertNotContains('channels', $valid['table_view']['groupable']);
    }

    public function testAnUnknownOptionIsRefusedOnWritePerValue(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();

        try {
            self::assertSame(['web', 'wholesale'], $products->update($id, ['channels' => ['wholesale', 'web', 'web']])->assertOk()->record()['channels']);
            self::assertSame(['web', 'wholesale'], $this->storedChannels($id), 'deduplicated, in the order the options are declared');

            $refused = $products->update($id, ['channels' => ['web', 'phone', 'fax']])->assertStatus(422)->json();
            self::assertSame([['channels', '1'], ['invalid_choice']], [$refused['errors'][0]['path'], array_values(array_unique(array_column($refused['errors'], 'code')))]);
            $products->update($id, ['channels' => 'web'])->assertInvalid('channels', 'type_mismatch');
            $products->update($id, ['status' => 'deleted'])->assertInvalid('status', 'invalid_choice');
            self::assertSame(['web', 'wholesale'], $this->storedChannels($id), 'nothing was written');

            self::assertNull($products->update($id, ['channels' => []])->assertOk()->record()['channels']);
            self::assertNull($this->storedChannels($id), 'an empty list clears a nullable column');
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['channels' => $stored]);
        }
    }

    public function testAStoredValueOutsideTheOptionsThrowsOnRead(): void
    {
        [$id, $stored] = $this->product();

        try {
            $this->shop()->table('products')->where('id', '=', $id)->update(['channels' => '["web", "phone"]']);
            $this->client->get("/vector/r/products/{$id}", headers: $this->realAdminHeaders())->assertStatus(500);
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['channels' => $stored]);
        }

        $this->client->get("/vector/r/products/{$id}", headers: $this->realAdminHeaders())->assertOk();
    }

    public function testAnyAndAllFilterTheJsonListOnMysql(): void
    {
        $rows = $this->shop()->table('products')->select('id', 'channels')->where('deleted_at', '=', null)->get();
        $lists = [];

        foreach ($rows as $row) {
            $lists[(int) $row['id']] = $row['channels'] === null ? [] : json_decode((string) $row['channels'], true, 4, JSON_THROW_ON_ERROR);
        }

        $this->assertFiltered('products', 'channels', $lists, ['retail', 'wholesale']);
    }

    public function testAnyAndAllFilterTheJsonbListOnPostgres(): void
    {
        $rows = $this->support()->table('tickets')->select('id', 'platforms')->where('deleted_at', '=', null)->get();
        $lists = [];

        foreach ($rows as $row) {
            $lists[(int) $row['id']] = $row['platforms'] === null ? [] : json_decode((string) $row['platforms'], true, 4, JSON_THROW_ON_ERROR);
        }

        $this->assertFiltered('tickets', 'platforms', $lists, ['ios', 'api']);
    }

    public function testTheJsonFilterSqlPerDialect(): void
    {
        self::assertSame(
            ['sql' => '(JSON_CONTAINS(`channels`, ?) OR JSON_CONTAINS(`channels`, ?))', 'params' => ['"web"', '1']],
            JsonFilter::listContains(Connections::MYSQL, 'channels', JsonFilter::ANY, ['web', 1]),
        );
        self::assertSame(
            ['sql' => 'JSON_CONTAINS(`channels`, ?)', 'params' => ['["web",1]']],
            JsonFilter::listContains(Connections::MYSQL, 'channels', JsonFilter::ALL, ['web', 1]),
        );
        self::assertSame(
            ['sql' => '("platforms" ??| ARRAY[?, ?]::text[] OR "platforms" @> ?::jsonb)', 'params' => ['ios', 'it\'s', '[7]']],
            JsonFilter::listContains(Connections::PGSQL, 'platforms', JsonFilter::ANY, ['ios', 'it\'s', 7]),
            'strings bound into a text array, numbers by containment',
        );
        self::assertSame(
            ['sql' => '"platforms" @> ?::jsonb', 'params' => ['["ios","api"]']],
            JsonFilter::listContains(Connections::PGSQL, 'platforms', JsonFilter::ALL, ['ios', 'api']),
        );
        self::assertSame('JSON_CONTAINS(`odd``name`, ?)', JsonFilter::listContains(Connections::MYSQL, 'odd`name', JsonFilter::ALL, ['x'])['sql'], 'the column is quoted');

        foreach ([['some', ['x']], ['any', []]] as [$mode, $values]) {
            try {
                JsonFilter::listContains(Connections::MYSQL, 'channels', $mode, $values);
                self::fail("accepted {$mode}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAFilterValueOutsideTheOptionsIsRefused(): void
    {
        $h = $this->adminHeaders();
        $this->client->get('/vector/r/products', query: ['filter' => ['channels' => ['any' => 'web,phone']]], headers: $h)->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['filter' => ['channels' => ['eq' => 'web']]], headers: $h)->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['sort' => 'channels'], headers: $h)->assertStatus(422);
        $this->client->get('/vector/r/products', query: ['group' => 'channels'], headers: $h)->assertStatus(422);
    }

    public function testOpenApiAndMcpDescribeAMultipleSelect(): void
    {
        $document = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($document);
        $schemas = $document['components']['schemas'];
        $record = $schemas['products'] ?? $schemas['Products'] ?? self::findSchemaWith($schemas, 'channels');
        $channels = $record['properties']['channels'];

        self::assertSame(['array', 'null'], $channels['type']);
        self::assertSame(array_keys(ProductResource::CHANNELS), $channels['items']['enum']);
        self::assertTrue($channels['uniqueItems']);

        $parameters = [];

        foreach ($document['paths'] as $operations) {
            foreach ($operations['get']['parameters'] ?? [] as $parameter) {
                $parameters[$parameter['name']] = $parameter;
            }
        }

        self::assertArrayHasKey('filter[channels][any]', $parameters);
        self::assertArrayHasKey('filter[channels][all]', $parameters);
        self::assertArrayNotHasKey('filter[channels][eq]', $parameters);
        self::assertStringContainsString('at least one', $parameters['filter[channels][any]']['schema']['description']);

        $describe = $this->mcpTool('vector_describe', ['resource' => 'products']);
        $field = array_column($describe['fields'], null, 'name')['channels'];
        self::assertSame(['select', true, ['any', 'all', 'null', 'nnull']], [$field['type'], $field['multiple'], $field['filter']]);
        self::assertStringContainsString('list of option values', $field['format']);
        self::assertStringContainsString('all: holds every one', $field['filterHelp']);
        self::assertSame(array_keys(ProductResource::CHANNELS), array_column($field['options'], 'value'));

        $tickets = array_column($this->mcpTool('vector_describe', ['resource' => 'tickets'])['fields'], null, 'name');
        self::assertSame(['json', 'a JSON object or array'], [$tickets['metadata']['valueType'], $tickets['metadata']['format']]);
    }

    public function testTheTicketPlatformsRoundTripOnPostgres(): void
    {
        $tickets = $this->vector($this->realAdminHeaders())->resource('tickets');
        $row = $this->support()->table('tickets')->select('id', 'platforms', 'metadata')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);
        $id = (int) $row['id'];

        try {
            $record = $tickets->update($id, ['platforms' => ['api', 'ios'], 'metadata' => ['carrier' => 'DHL', 'parcels' => [1, 2], 'insured' => false, 'empty' => []]])->assertOk()->record();
            self::assertSame(['ios', 'api'], $record['platforms'], 'in the order the options are declared');
            self::assertEquals(['carrier' => 'DHL', 'parcels' => [1, 2], 'insured' => false, 'empty' => []], $record['metadata']);
            $stored = $this->support()->table('tickets')->select('platforms', 'metadata')->where('id', '=', $id)->first();
            self::assertIsArray($stored);
            self::assertSame(['ios', 'api'], json_decode((string) $stored['platforms'], true));
            self::assertEquals(['carrier' => 'DHL', 'parcels' => [1, 2], 'insured' => false, 'empty' => []], json_decode((string) $stored['metadata'], true));
            $tickets->update($id, ['metadata' => '{"carrier": "DHL"}'])->assertInvalid('metadata', 'type_mismatch');
        } finally {
            $this->support()->table('tickets')->where('id', '=', $id)->update(['platforms' => $row['platforms'], 'metadata' => $row['metadata']]);
        }
    }

    /**
     * "any" and "all" of each value alone and of the pair match exactly
     * the rows whose list holds them, as PHP computes it from the rows.
     *
     * @param array<int, list<string>> $lists record id => its stored list
     * @param array{0: string, 1: string} $pair
     */
    private function assertFiltered(string $slug, string $field, array $lists, array $pair): void
    {
        $h = $this->adminHeaders();
        ksort($lists);
        $any = array_keys(array_filter($lists, static fn (array $l): bool => array_intersect($pair, $l) !== []));
        $all = array_keys(array_filter($lists, static fn (array $l): bool => array_diff($pair, $l) === []));
        $none = array_keys(array_filter($lists, static fn (array $l): bool => $l === []));

        self::assertNotSame([], $all, 'the seed holds a record with both values');
        self::assertGreaterThan(count($all), count($any), 'the seed tells "any" from "all"');

        foreach ([['any', $any], ['all', $all], ['null', $none]] as [$op, $expected]) {
            $value = $op === 'null' ? '1' : implode(',', $pair);
            $ids = $this->ids($slug, ['filter' => [$field => [$op => $value]]], $h);
            sort($expected);
            self::assertSame($expected, $ids, "{$slug} {$field} {$op}");
        }

        self::assertSame($any, $this->ids($slug, ['filter' => [$field => implode(',', $pair)]], $h), 'a bare value is "any"');

        foreach ($pair as $one) {
            $expected = array_keys(array_filter($lists, static fn (array $l): bool => in_array($one, $l, true)));
            self::assertSame($expected, $this->ids($slug, ['filter' => [$field => ['all' => $one]]], $h), "{$slug} {$field} all {$one}");
            self::assertSame($expected, $this->ids($slug, ['filter' => [$field => ['any' => $one]]], $h), "{$slug} {$field} any {$one}");
        }

        // inside an or group too
        $grouped = $this->ids($slug, ['filter' => ['or' => [[$field => ['all' => implode(',', $pair)]], [$field => ['null' => '1']]]]], $h);
        $expected = [...$all, ...$none];
        sort($expected);
        self::assertSame($expected, $grouped, 'whereRaw inside an or group');
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return list<int>
     */
    private function ids(string $slug, array $query, array $headers): array
    {
        $ids = [];
        $cursor = null;

        do {
            $page = $this->client->get("/vector/r/{$slug}", query: [...$query, 'perPage' => 100, 'page' => $cursor ?? 1, 'columns' => 'id'], headers: $headers)->assertOk()->json();
            self::assertIsArray($page);
            $ids = [...$ids, ...array_map(static fn (array $r): int => (int) $r['id'], $page['data'])];
            $cursor = count($page['data']) === 100 ? ($cursor ?? 1) + 1 : null;
        } while ($cursor !== null);

        sort($ids);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function compileProducts(VectorResource $resource): array
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));

        return (new ResourceCompiler($metadata, [$resource::class]))->compile()['select-products'];
    }

    /**
     * @param array<string, mixed> $schemas
     * @return array<string, mixed>
     */
    private static function findSchemaWith(array $schemas, string $property): array
    {
        foreach ($schemas as $schema) {
            if (is_array($schema) && isset($schema['properties'][$property]['items'])) {
                return $schema;
            }
        }

        self::fail("No schema carries {$property}.");
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function mcpTool(string $tool, array $arguments): array
    {
        $headers = [...$this->adminHeaders(), 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'];
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], JSON_THROW_ON_ERROR);
        $response = $this->client->raw('POST', '/mcp', $body, $headers)->assertOk()->json();
        self::assertIsArray($response);
        $result = $response['result'] ?? self::fail(json_encode($response, JSON_THROW_ON_ERROR));
        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');
        $decoded = json_decode($result['content'][0]['text'], true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array{0: int, 1: string|null} */
    private function product(): array
    {
        $row = $this->shop()->table('products')->select('id', 'channels')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);

        return [(int) $row['id'], $row['channels'] === null ? null : (string) $row['channels']];
    }

    /** @return list<string>|null */
    private function storedChannels(int $id): ?array
    {
        $value = $this->shop()->table('products')->select('channels')->where('id', '=', $id)->value('channels');

        return $value === null ? null : json_decode((string) $value, true, 4, JSON_THROW_ON_ERROR);
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }

    private function support(): Query
    {
        if ($this->supportManager === null) {
            $factories = $this->app->get(OrmFactoryRegistry::class);
            self::assertInstanceOf(OrmFactoryRegistry::class, $factories);
            $this->supportManager = $factories->factory('support')->open();
        }

        return $this->supportManager->builder();
    }

    protected function tearDown(): void
    {
        $this->supportManager?->close();
        $this->supportManager = null;
        parent::tearDown();
    }
}
