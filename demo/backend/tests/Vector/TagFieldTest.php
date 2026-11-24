<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Product;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Tag;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;

/**
 * Free-text tags in a JSON list: normalized on write (trimmed, no empty
 * ones, deduplicated ignoring case, in their order), refused per tag,
 * and filtered by "any" or "all" of a list on both dialects.
 */
final class TagFieldTest extends VectorTestCase
{
    use InteractsWithVector;

    private ?EntityManager $supportManager = null;

    public function testStorageAndCapabilitiesAreCheckedAtBuild(): void
    {
        $cases = [
            'a string property' => [new #[Resource(Product::class, slug: 'tag-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Tag::make('sku')];
                }
            }, 'is a tag field, which stores a JSON list of strings: declare the property array or ?array, not string'],
            'sortable' => [new #[Resource(Product::class, slug: 'tag-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Tag::make('keywords')->sortable()];
                }
            }, 'cannot be sorted, grouped, searched, summarized or edited inline'],
            'inline' => [new #[Resource(Product::class, slug: 'tag-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Tag::make('keywords')->inlineEditable()];
                }
            }, 'cannot be sorted, grouped, searched, summarized or edited inline'],
            'searchable' => [new #[Resource(Product::class, slug: 'tag-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Tag::make('keywords')->searchable()];
                }
            }, 'is not a string column and cannot be searchable'],
            'a default that is no list' => [new #[Resource(Product::class, slug: 'tag-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Tag::make('keywords')->default('eco')];
                }
            }, 'whose default is a list of strings'],
        ];

        foreach ($cases as $case => [$resource, $message]) {
            try {
                $this->compileProducts($resource);
                self::fail("compiled: {$case}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
        }

        $valid = $this->compileProducts(new #[Resource(Product::class, slug: 'tag-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Tag::make('keywords')->suggestions(static fn (): array => [' gift', 'Eco', 'eco', ''])->maxTags(5)->maxLength(12)->filterable()];
            }
        });
        $keywords = array_column($valid['fields'], null, 'name')['keywords'];

        self::assertSame(['json', ['any', 'all', 'null', 'nnull']], [$keywords['valueType'], $keywords['operators']]);
        self::assertSame(['suggestions' => ['gift', 'Eco'], 'maxLength' => 12, 'maxTags' => 5], $keywords['tags'], 'suggestions are normalized as tags are');
        self::assertNotContains('keywords', $valid['table_view']['groupable']);
    }

    public function testATagListIsNormalizedOnWrite(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();

        try {
            $record = $products->update($id, ['keywords' => ['  Eco ', 'eco', '', 'gift', 'ECO', "\u{00A0}Été\u{00A0}", 'été', 'bundle']])->assertOk()->record();
            self::assertSame(['Eco', 'gift', 'Été', 'bundle'], $record['keywords'], 'trimmed, without empty ones, the first spelling of each kept, in order');
            self::assertSame(['Eco', 'gift', 'Été', 'bundle'], $this->storedKeywords($id));

            self::assertNull($products->update($id, ['keywords' => ['  ', '']])->assertOk()->record()['keywords'], 'nothing left is no value');
            self::assertNull($this->storedKeywords($id));
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['keywords' => $stored]);
        }
    }

    public function testEachBadTagIsRefusedAtItsIndex(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();

        try {
            $refused = $products->update($id, ['keywords' => ['ok', 42, str_repeat('x', Tag::MAX_LENGTH + 1), ['nested']]])->assertStatus(422)->json();
            self::assertSame(
                [[['keywords', 1], 'type_mismatch'], [['keywords', 2], 'too_long'], [['keywords', 3], 'type_mismatch']],
                array_map(static fn (array $e): array => [$e['path'], $e['code']], $refused['errors']),
            );
            $products->update($id, ['keywords' => array_map(static fn (int $i): string => "k{$i}", range(1, Tag::MAX_TAGS + 1))])->assertInvalid('keywords', 'too_many_entries');
            $products->update($id, ['keywords' => 'eco'])->assertInvalid('keywords', 'type_mismatch');
            self::assertSame($stored === null ? null : json_decode($stored, true), $this->storedKeywords($id), 'nothing was written');

            // a tag at the limit is one tag, however it was padded
            self::assertSame([str_repeat('y', Tag::MAX_LENGTH)], $products->update($id, ['keywords' => ['  ' . str_repeat('y', Tag::MAX_LENGTH) . '  ']])->assertOk()->record()['keywords']);
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['keywords' => $stored]);
        }
    }

    public function testAnyAndAllFilterTheTagsOnMysql(): void
    {
        $rows = $this->shop()->table('products')->select('id', 'keywords')->where('deleted_at', '=', null)->get();
        $lists = [];

        foreach ($rows as $row) {
            $lists[(int) $row['id']] = $row['keywords'] === null ? [] : json_decode((string) $row['keywords'], true, 4, JSON_THROW_ON_ERROR);
        }

        $this->assertFiltered('products', 'keywords', $lists, ['eco', 'bestseller']);
    }

    public function testAnyAndAllFilterTheTagsOnPostgres(): void
    {
        $rows = $this->support()->table('tickets')->select('id', 'keywords')->where('deleted_at', '=', null)->get();
        $lists = [];

        foreach ($rows as $row) {
            $lists[(int) $row['id']] = $row['keywords'] === null ? [] : json_decode((string) $row['keywords'], true, 4, JSON_THROW_ON_ERROR);
        }

        $this->assertFiltered('tickets', 'keywords', $lists, ['login', 'crash']);
    }

    public function testATagIsMatchedExactlyAndNeitherSortedNorGrouped(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();
        $tag = 'Tag-' . bin2hex(random_bytes(4));

        try {
            $products->update($id, ['keywords' => [$tag, 'it\'s, "quoted"']])->assertOk();
            $h = $this->adminHeaders();
            self::assertSame([$id], $this->ids('products', ['filter' => ['keywords' => $tag]], $h));
            self::assertSame([], $this->ids('products', ['filter' => ['keywords' => strtolower($tag)]], $h), 'a filter compares the stored spelling');
            self::assertSame([$id], $this->ids('products', ['filter' => ['keywords' => ['all' => [$tag, 'it\'s, "quoted"']]]], $h), 'a list of values may hold commas when sent as an array');
            $this->client->get('/vector/r/products', query: ['filter' => ['keywords' => ['eq' => $tag]]], headers: $h)->assertStatus(422);
            $this->client->get('/vector/r/products', query: ['sort' => 'keywords'], headers: $h)->assertStatus(422);
            $this->client->get('/vector/r/products', query: ['group' => 'keywords'], headers: $h)->assertStatus(422);
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['keywords' => $stored]);
        }
    }

    public function testOpenApiAndMcpDescribeTags(): void
    {
        $document = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($document);
        $keywords = self::findSchemaWith($document['components']['schemas'], 'keywords')['properties']['keywords'];

        self::assertSame(['array', 'null'], $keywords['type']);
        self::assertSame(['type' => 'string', 'minLength' => 1, 'maxLength' => Tag::MAX_LENGTH], $keywords['items']);
        self::assertSame(Tag::MAX_TAGS, $keywords['maxItems']);

        $parameters = [];

        foreach ($document['paths'] as $path => $operations) {
            if ($path === '/vector/r/products') {
                foreach ($operations['get']['parameters'] ?? [] as $parameter) {
                    $parameters[$parameter['name']] = $parameter;
                }
            }
        }

        self::assertArrayHasKey('filter[keywords][any]', $parameters);
        self::assertArrayHasKey('filter[keywords][all]', $parameters);
        self::assertArrayNotHasKey('filter[keywords][eq]', $parameters);
        self::assertStringContainsString('tags, each matched exactly as stored', $parameters['filter[keywords][any]']['schema']['description']);

        $field = array_column($this->mcpTool('vector_describe', ['resource' => 'products'])['fields'], null, 'name')['keywords'];
        self::assertSame(['tag', 'json', ['any', 'all', 'null', 'nnull']], [$field['type'], $field['valueType'], $field['filter']]);
        self::assertStringContainsString('a list of free-text tags, at most 20 of at most 50 characters each', $field['format']);
        self::assertStringContainsString('ignoring case', $field['format']);
        self::assertStringContainsString('any (a bare value): holds at least one', $field['filterHelp']);

        $tickets = array_column($this->mcpTool('vector_describe', ['resource' => 'tickets'])['fields'], null, 'name');
        self::assertSame('tag', $tickets['keywords']['type']);
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

        self::assertNotSame([], $all, 'the seed holds a record with both tags');
        self::assertGreaterThan(count($all), count($any), 'the seed tells "any" from "all"');

        foreach ([['any', $any], ['all', $all], ['null', $none]] as [$op, $expected]) {
            $value = $op === 'null' ? '1' : implode(',', $pair);
            self::assertSame($expected, $this->ids($slug, ['filter' => [$field => [$op => $value]]], $h), "{$slug} {$field} {$op}");
        }

        self::assertSame($any, $this->ids($slug, ['filter' => [$field => implode(',', $pair)]], $h), 'a bare value is "any"');

        foreach ($pair as $one) {
            $expected = array_keys(array_filter($lists, static fn (array $l): bool => in_array($one, $l, true)));
            self::assertSame($expected, $this->ids($slug, ['filter' => [$field => ['all' => $one]]], $h), "{$slug} {$field} all {$one}");
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return list<int>
     */
    private function ids(string $slug, array $query, array $headers): array
    {
        $ids = [];
        $page = 1;

        do {
            $body = $this->client->get("/vector/r/{$slug}", query: [...$query, 'perPage' => 100, 'page' => $page, 'columns' => 'id'], headers: $headers)->assertOk()->json();
            self::assertIsArray($body);
            $ids = [...$ids, ...array_map(static fn (array $r): int => (int) $r['id'], $body['data'])];
            $page++;
        } while (count($body['data']) === 100);

        sort($ids);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function compileProducts(VectorResource $resource): array
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));

        return (new ResourceCompiler($metadata, [$resource::class]))->compile()['tag-products'];
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
        $row = $this->shop()->table('products')->select('id', 'keywords')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);

        return [(int) $row['id'], $row['keywords'] === null ? null : (string) $row['keywords']];
    }

    /** @return list<string>|null */
    private function storedKeywords(int $id): ?array
    {
        $value = $this->shop()->table('products')->select('keywords')->where('id', '=', $id)->value('keywords');

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
