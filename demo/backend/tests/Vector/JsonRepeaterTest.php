<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Product;
use App\Domain\ProductStatus;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Date;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Image;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Repeater;
use Kinetis\Vector\Fields\Select;
use Kinetis\Vector\Fields\Tag;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Mcp\ResourceDescription;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;
use Kinetis\Vector\Write\InputViolation;
use Kinetis\Vector\Write\ValueConverter;

/**
 * A JSON-mode repeater: rows of declared fields stored as a list of
 * objects in one JSON column, each row validated and converted by its
 * fields, a key no field declares refused, the list kept in the order it
 * was sent in.
 */
final class JsonRepeaterTest extends VectorTestCase
{
    use InteractsWithVector;
    use ValidatesInput;

    public function testTheSchemaPublishesTheRowFields(): void
    {
        $specs = array_column($this->vector($this->adminHeaders())->schema('products')->assertOk()->json()['fields'], null, 'name')['specs'];

        self::assertSame('repeater', $specs['type']);
        self::assertSame(['json', ['label', 'value'], 0, 20, true, null], [
            $specs['repeater']['mode'], $specs['repeater']['fields'], $specs['repeater']['min'], $specs['repeater']['max'], $specs['repeater']['sortable'], $specs['repeater']['orderBy'],
        ]);
        $label = $specs['repeater']['rowFields'][0];
        self::assertSame(['label', 'string', false, 'string'], [$label['name'], $label['type'], $label['nullable'], $label['valueType']]);
        self::assertArrayNotHasKey('relation', $specs);
        self::assertSame([], $specs['operators']);
    }

    public function testRowsAreWrittenInTheOrderTheyAreSent(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();
        $rows = [['label' => 'Warranty', 'value' => '24 months'], ['label' => 'Made in', 'value' => 'Portugal'], ['label' => 'Battery', 'value' => '12 h']];

        try {
            self::assertSame($rows, $products->update($id, ['specs' => $rows])->assertOk()->record()['specs']);
            self::assertSame($rows, $this->storedSpecs($id));

            // dragged: the last row first
            $reordered = [$rows[2], $rows[0], $rows[1]];
            self::assertSame($reordered, $products->update($id, ['specs' => $reordered])->assertOk()->record()['specs']);
            self::assertSame($reordered, $products->show($id)->json()['data']['specs']);

            // a row lists its keys in the order the fields are declared, whatever order they came in
            self::assertSame([['label' => 'A', 'value' => 'B']], $products->update($id, ['specs' => [['value' => 'B', 'label' => 'A']]])->assertOk()->record()['specs']);
            self::assertSame([], $products->update($id, ['specs' => []])->assertOk()->record()['specs']);
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['specs' => $stored]);
        }
    }

    public function testEachRowIsValidatedAtItsOwnPath(): void
    {
        $products = $this->vector($this->realAdminHeaders())->resource('products');
        [$id, $stored] = $this->product();

        try {
            $refused = $products->update($id, ['specs' => [
                ['label' => 'Fine', 'value' => 'Fine'],
                ['label' => 'Colour', 'value' => 'Red', 'colour' => 'red'],
                ['label' => '', 'value' => str_repeat('v', 201)],
                ['label' => 'Missing'],
                'not a row',
            ]])->assertStatus(422)->json();

            self::assertSame([
                [['specs', 1, 'colour'], 'unexpected_field'],
                [['specs', 2, 'label'], 'not_blank'],
                [['specs', 2, 'value'], 'max_length'],
                [['specs', 3, 'value'], 'required'],
                [['specs', 4], 'type_mismatch'],
            ], array_map(static fn (array $e): array => [$e['path'], $e['code']], $refused['errors']));

            $products->update($id, ['specs' => array_fill(0, 21, ['label' => 'x', 'value' => 'y'])])->assertInvalid('specs', 'count_out_of_range');
            $products->update($id, ['specs' => ['label' => 'x', 'value' => 'y']])->assertInvalid('specs', 'type_mismatch');
            self::assertSame($stored === null ? null : json_decode($stored, true), $this->storedSpecs($id), 'nothing was written');
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['specs' => $stored]);
        }
    }

    /**
     * required() takes any list holding a row; an empty one is not_blank.
     * With min(), null on update is too few rows, not a way around it.
     */
    public function testRequiredAndMinHoldForEveryEmptyValue(): void
    {
        $compiled = $this->compiled(new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('specs')->fields([Text::make('label')])->required(), Repeater::make('keywords')->fields([Text::make('label')])->min(1)];
            }
        }, 'json-repeater-products');

        self::assertSame(['specs' => [['label' => 'x']]], $this->recordInput($compiled, ['specs' => [['label' => 'x']]], true));
        self::assertSame([[['specs'], 'not_blank']], $this->recordInput($compiled, ['specs' => []], true));
        self::assertSame([[['keywords'], 'count_out_of_range']], $this->recordInput($compiled, ['keywords' => null], true));
        self::assertSame([[['keywords'], 'count_out_of_range']], $this->recordInput($compiled, ['keywords' => []], true));
        self::assertSame(['keywords' => [['label' => 'x']]], $this->recordInput($compiled, ['keywords' => [['label' => 'x']]], true));
    }

    public function testRowFieldsAreConvertedToPlainJsonValues(): void
    {
        $compiled = $this->compileProducts(new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('specs')->fields([
                    Select::make('state')->of(ProductStatus::class),
                    Date::make('since'),
                    Number::make('count')->required(),
                    Tag::make('words'),
                ])];
            }
        });
        $field = array_column($compiled['fields'], null, 'name')['specs'];
        $converter = new ValueConverter(
            VectorRegistry::fromArray(['version' => VectorRegistry::VERSION, 'resources' => [], 'settings' => [], 'dashboards' => []]),
            EntityManagerRegistry::create(OrmFactoryRegistry::create([], MetadataRegistry::fromClasses([]))),
            new RequestScope(new AppScope()),
        );

        self::assertSame(
            [['state' => 'active', 'since' => '2026-01-31', 'count' => 3, 'words' => ['a', 'B']]],
            $converter->convert($field, [['state' => 'active', 'since' => '2026-01-31', 'count' => 3, 'words' => [' a', 'B', 'b']]]),
            'an enum case as its value, a day as its text, tags normalized',
        );

        try {
            $converter->convert($field, [['state' => 'gone', 'since' => '31/01/2026', 'words' => ['ok', 7]]]);
            self::fail('converted invalid rows');
        } catch (InputViolation $e) {
            self::assertSame(
                [[[0, 'state'], 'invalid_choice'], [[0, 'since'], 'type_mismatch'], [[0, 'count'], 'required'], [[0, 'words', 1], 'type_mismatch']],
                array_map(static fn (Violation $v): array => [$v->path, $v->code], $e->all()),
            );
        }
    }

    /** A field a row leaves out takes its default, as a new record's would, or null without one. */
    public function testARowFieldLeftOutTakesItsDefault(): void
    {
        $compiled = $this->compileProducts(new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Repeater::make('specs')->fields([Text::make('unit')->default('pcs'), Text::make('note')])];
            }
        });
        $field = array_column($compiled['fields'], null, 'name')['specs'];
        $converter = new ValueConverter(
            VectorRegistry::fromArray(['version' => VectorRegistry::VERSION, 'resources' => [], 'settings' => [], 'dashboards' => []]),
            EntityManagerRegistry::create(OrmFactoryRegistry::create([], MetadataRegistry::fromClasses([]))),
            new RequestScope(new AppScope()),
        );

        self::assertSame([['unit' => 'pcs', 'note' => null]], $converter->convert($field, [[]]));
        self::assertSame([['unit' => 'kg', 'note' => 'x']], $converter->convert($field, [['unit' => 'kg', 'note' => 'x']]));
    }

    public function testTheCompilerRefusesWhatAJsonRepeaterCannotHold(): void
    {
        $cases = [
            'a string property' => [new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Repeater::make('sku')->fields([Text::make('label')])];
                }
            }, 'is a JSON repeater, which stores its rows in a JSON column: declare the property array or ?array, not string'],
            'no row fields' => [new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Repeater::make('specs')];
                }
            }, 'is a repeater with no row fields; call fields(), or relationship() to edit child records'],
            'field names without a relationship' => [new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Repeater::make('specs')->fields(['label'])];
                }
            }, 'names "label"; a JSON repeater\'s fields() takes field definitions'],
            'a nested repeater' => [new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Repeater::make('specs')->fields([Repeater::make('inner')->fields([Text::make('x')])])];
                }
            }, 'holds the repeater "inner"; rows never hold another repeater'],
            'a relation in a row' => [new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Repeater::make('specs')->fields([BelongsTo::to('category', self::class)])];
                }
            }, 'holds "category", a belongsTo field, which a JSON row cannot hold'],
            'a file in a row' => [new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Repeater::make('specs')->fields([Image::make('photo')])];
                }
            }, 'holds "photo", a image field, which a JSON row cannot hold'],
            'a row field twice' => [new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Repeater::make('specs')->fields([Text::make('label'), Text::make('label')])];
                }
            }, 'declares the row field "label" twice'],
            'a row condition' => [new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Repeater::make('specs')->fields([Text::make('label'), Text::make('value')->visibleWhen('label', 'nnull')])];
                }
            }, 'gives the row field "value" a condition'],
            'an order property' => [new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Repeater::make('specs')->fields([Text::make('label')])->orderBy('position')];
                }
            }, 'is a JSON repeater, whose rows keep the order they are sent in'],
            'sortable' => [new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Repeater::make('specs')->fields([Text::make('label')])->sortable()];
                }
            }, 'is a repeater, which cannot be sorted, grouped, filtered, searched, summarized or edited inline'],
            'filterable' => [new #[Resource(Product::class, slug: 'json-repeater-products')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Repeater::make('specs')->fields([Text::make('label')])->filterable()];
                }
            }, 'is a collection and cannot be filterable'],
        ];

        foreach ($cases as $case => [$resource, $message]) {
            try {
                $this->compileProducts($resource);
                self::fail("compiled: {$case}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
        }
    }

    public function testOpenApiAndMcpDescribeTheRows(): void
    {
        $document = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($document);
        $read = null;
        $write = null;

        foreach ($document['components']['schemas'] as $schema) {
            $specs = is_array($schema) ? $schema['properties']['specs'] ?? null : null;

            if (is_array($specs)) {
                if (isset($specs['items']['required'])) {
                    $write = $specs;
                } else {
                    $read ??= $specs;
                }
            }
        }

        self::assertIsArray($read);
        self::assertIsArray($write, 'a write body requires each row\'s required fields');
        self::assertSame(['array', 'null'], $read['type']);
        self::assertSame([0, 20, false], [$write['minItems'], $write['maxItems'], $write['items']['additionalProperties']]);
        self::assertSame(['label', 'value'], $write['items']['required']);
        self::assertSame(['type' => 'string', 'maxLength' => 60], array_intersect_key($write['items']['properties']['label'], ['type' => 0, 'maxLength' => 0]));

        $orderLines = null;

        foreach ($document['components']['schemas'] as $schema) {
            $lines = is_array($schema) ? $schema['properties']['lines'] ?? null : null;

            if (is_array($lines) && ($lines['type'] ?? null) === 'array') {
                $orderLines = $lines;
            }
        }

        self::assertIsArray($orderLines, 'a relationship repeater is written as rows');
        self::assertSame(['id', 'product', 'quantity', 'unitPriceCents'], array_keys($orderLines['items']['properties']));
        self::assertSame([1, 50], [$orderLines['minItems'], $orderLines['maxItems']]);

        $products = array_column($this->mcpTool('vector_describe', ['resource' => 'products'])['fields'], null, 'name');
        self::assertSame('a list of 0 to 20 rows, each an object of the fields in `rows` and no others; stored in the order sent', $products['specs']['format']);
        self::assertSame([['name' => 'label', 'type' => 'string', 'required' => true, 'rules' => ['NotBlank', 'MaxLength(60)']], ['name' => 'value', 'type' => 'string', 'required' => true, 'rules' => ['NotBlank', 'MaxLength(200)']]], $products['specs']['rows']);

        $orders = array_column($this->mcpTool('vector_describe', ['resource' => 'orders'])['fields'], null, 'name');
        self::assertSame(['create', 'update'], $orders['lines']['writable']);
        self::assertStringContainsString('a list of 1 to 50 rows', $orders['lines']['format']);
        self::assertStringContainsString('a row left out is deleted, all saved with the record; the list order is the rows\' order', $orders['lines']['format']);
        self::assertSame([['name' => 'product'], ['name' => 'quantity'], ['name' => 'unitPriceCents']], $orders['lines']['rows']);

        // a relationship repeater that is not sortable says nothing of the order
        $registry = $this->app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray()['resources']['orders'];

        foreach ($data['fields'] as $i => $field) {
            if ($field['name'] === 'lines') {
                $data['fields'][$i]['repeater']['sortable'] = false;
            }
        }

        $unsorted = array_column(ResourceDescription::of(new ResourceDescriptor($data), [])['fields'], null, 'name');
        self::assertStringEndsWith('a row left out is deleted, all saved with the record', $unsorted['lines']['format']);
    }

    /** @return array<string, mixed> */
    private function compileProducts(VectorResource $resource): array
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));

        return (new ResourceCompiler($metadata, [$resource::class]))->compile()['json-repeater-products'];
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
        $row = $this->shop()->table('products')->select('id', 'specs')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);

        return [(int) $row['id'], $row['specs'] === null ? null : (string) $row['specs']];
    }

    /** @return list<array<string, mixed>>|null */
    private function storedSpecs(int $id): ?array
    {
        $value = $this->shop()->table('products')->select('specs')->where('id', '=', $id)->value('specs');

        return $value === null ? null : json_decode((string) $value, true, 8, JSON_THROW_ON_ERROR);
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
