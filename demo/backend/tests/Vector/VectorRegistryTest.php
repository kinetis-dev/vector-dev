<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Order;
use App\Domain\OrderLine;
use App\Domain\Support\Agent;
use App\Vector\CustomerResource;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\VectorResource;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/** The registry compiles the demo application from source alone, with no database. */
final class VectorRegistryTest extends TestCase
{
    /** @return array<string, mixed> the compiled registry, for the tests that depend on it */
    public function testTheDemoApplicationCompilesAndRoundTrips(): array
    {
        $data = VectorRegistry::compile(new DiscoveryContext(dirname(__DIR__, 2)));

        self::assertSame(4, $data['version']);
        self::assertSame(['main'], array_keys($data['dashboards']));
        self::assertSame(['branding', 'jobs', 'languages', 'store'], array_keys($data['settings']));
        self::assertSame(
            ['agents', 'categories', 'customer-profiles', 'customers', 'labels', 'order-lines', 'orders', 'products', 'profiles', 'support-teams', 'tags', 'ticket-replies', 'tickets', 'users'],
            array_keys($data['resources']),
        );

        $registry = VectorRegistry::fromArray($data);
        self::assertSame($data, $registry->toArray());

        $products = $registry->resource('products');
        self::assertSame('products', $products->table());
        self::assertSame('name', $products->titleColumn());
        self::assertSame('price_cents', $products->columns()['priceCents']);
        self::assertSame(['id', 'sku', 'name', 'status', 'category', 'priceCents', 'stock', 'isFeatured', 'tags', 'releasedAt', 'createdAt', 'currency'], $products->indexFields(), 'the currency a money column needs is serialized without being displayed');
        self::assertSame(['field' => 'deletedAt', 'column' => 'deleted_at'], $products->softDelete());
        self::assertSame(['priceCents' => ['function' => 'avg', 'column' => 'price_cents'], 'stock' => ['function' => 'sum', 'column' => 'stock']], $products->summaryPlan());
        self::assertSame(['eq', 'ne', 'in', 'nin'], $this->field($products, 'status')['operators']);
        self::assertSame(['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'between', 'null', 'nnull'], $this->field($products, 'releasedAt')['operators']);
        self::assertSame([], $this->field($products, 'tags')['operators'], 'a collection is never filterable');
        self::assertSame(['publish-products', 'archive-products', 'adjust-stock', 'reprice-products', 'catalog-summary'], array_column($products->actions(), 'name'));
        self::assertSame('int', $this->action($products, 'adjust-stock')['fields'][0]['valueType']);
        self::assertFalse($this->action($products, 'adjust-stock')['fields'][0]['nullable'], 'a required action field is not nullable');
        self::assertTrue($this->action($products, 'catalog-summary')['standalone']);
        self::assertSame(['sku', 'name', 'category'], $products->toArray()['table']['search'], 'the full-text field and the searchable relation are searched too');
        self::assertSame(['sku' => 'sku'], $products->searchColumns(), 'LIKE leaves the full-text column to its index');
        self::assertSame(['category' => ['column' => 'category_id', 'resource' => 'categories']], $products->searchRelations());
        self::assertSame(['columns' => ['name' => 'name'], 'config' => 'simple'], $products->fullText());
        self::assertSame([], array_intersect(['searchRelations', 'fullText'], array_keys($products->toArray())), 'the search plan stays internal');

        return $data;
    }

    /**
     * The compiled shape ListRequestTest builds its descriptor in, on a
     * resource with an instant field, a summary and a soft delete.
     *
     * @param array<string, mixed> $data
     */
    #[Depends('testTheDemoApplicationCompilesAndRoundTrips')]
    public function testTheCompiledShapeTheListGrammarReads(array $data): void
    {
        $products = $data['resources']['products'];

        foreach (['slug', 'primaryKey', 'fields', 'columns', 'searchColumns', 'summaryPlan', 'softDelete', 'table_view', 'show_view'] as $key) {
            self::assertArrayHasKey($key, $products);
        }

        self::assertSame(['field' => 'id', 'column' => 'id', 'type' => 'int'], $products['primaryKey']);
        foreach (['columns', 'extra', 'groupable', 'defaultSort', 'perPage', 'maxPerPage'] as $key) {
            self::assertArrayHasKey($key, $products['table_view']);
        }

        foreach (['fields', 'extra'] as $key) {
            self::assertArrayHasKey($key, $products['show_view']);
        }

        self::assertSame([['field' => 'createdAt', 'order' => 'desc']], $products['table_view']['defaultSort']);
        self::assertIsInt($products['table_view']['perPage']);
        self::assertIsInt($products['table_view']['maxPerPage']);
        self::assertSame(['sku' => 'sku'], $products['searchColumns']);
        self::assertSame(['category' => ['column' => 'category_id', 'resource' => 'categories']], $products['searchRelations']);
        self::assertSame(['columns' => ['name' => 'name'], 'config' => 'simple'], $products['fullText']);
        self::assertSame(['function' => 'sum', 'column' => 'stock'], $products['summaryPlan']['stock']);
        self::assertSame(['field' => 'deletedAt', 'column' => 'deleted_at'], $products['softDelete']);
        self::assertSame('released_at', $products['columns']['releasedAt']);

        $fields = array_column($products['fields'], null, 'name');

        foreach ($fields as $name => $field) {
            foreach (['name', 'type', 'sortable', 'operators'] as $key) {
                self::assertArrayHasKey($key, $field, $name);
            }

            if (isset($field['column'])) {
                self::assertArrayHasKey('valueType', $field, $name);
            }
        }

        self::assertSame(['id', 'int', 'id', true, []], [$fields['id']['type'], $fields['id']['valueType'], $fields['id']['column'], $fields['id']['sortable'], $fields['id']['operators']]);
        self::assertSame(['datetime', 'timestamp', 'released_at', true], [$fields['releasedAt']['type'], $fields['releasedAt']['valueType'], $fields['releasedAt']['column'], $fields['releasedAt']['sortable']]);
        self::assertSame(['number', 'int', 'stock'], [$fields['stock']['type'], $fields['stock']['valueType'], $fields['stock']['column']]);
        self::assertSame(['string', 'string', 'name'], [$fields['name']['type'], $fields['name']['valueType'], $fields['name']['column']]);
    }

    public function testDerivationCoversEveryMappedPropertyAndRelationship(): void
    {
        // an empty resource over the order entity: its fields derive from the ORM metadata alone
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $derived = new #[Resource(Order::class, slug: 'derived-orders')] class extends VectorResource {
            public function defaultSort(): array
            {
                return ['-placedAt'];
            }
        };
        $compiled = (new ResourceCompiler($metadata, [$derived::class, CustomerResource::class, DerivedOrderLines::class]))->compile();
        $orders = new ResourceDescriptor($compiled['derived-orders']);
        $types = array_map(static fn (array $field): string => $field['type'], $orders->fields());

        self::assertSame([
            'id' => 'id',
            'number' => 'string',
            'customer' => 'belongsTo',
            'status' => 'enum',
            'totalCents' => 'number',
            'currency' => 'string',
            'placedAt' => 'datetime',
            'shippedAt' => 'datetime',
            'notes' => 'string',
            'createdAt' => 'datetime',
            'updatedAt' => 'datetime',
            'lines' => 'hasMany',
        ], $types, 'the version column is hidden, timestamps derive, relations derive when their target has a resource');

        $status = $orders->field('status');
        self::assertSame(['pending', 'paid', 'shipped', 'cancelled'], array_column($status['options'] ?? [], 'value'));
        self::assertSame([['field' => 'placedAt', 'order' => 'desc']], $orders->defaultSort());
        self::assertTrue($orders->field('createdAt')['readonly'] ?? false);
        self::assertSame('order_id', $orders->field('lines')['relation']['foreignKey'] ?? null);
    }

    public function testADateOnlyPropertyDerivesADateFieldAndRefusesADateTimeField(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));

        $derived = new #[Resource(Agent::class, slug: 'derived-agents')] class extends VectorResource {};
        $compiled = (new ResourceCompiler($metadata, [$derived::class]))->compile()['derived-agents'];
        $joined = array_values(array_filter($compiled['fields'], static fn (array $f): bool => $f['name'] === 'joinedOn'))[0];
        self::assertSame(['date', 'date'], [$joined['type'], $joined['valueType']]);

        $wrong = new #[Resource(Agent::class, slug: 'wrong-agents')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), DateTime::make('joinedOn')];
            }
        };

        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('maps a date-only Kinetis\\Orm\\Date property; declare it with Date::make()');
        (new ResourceCompiler($metadata, [$wrong::class]))->compile();
    }

    public function testAnExplicitSlugIsNotNormalized(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $resource = new #[Resource(OrderLine::class, slug: 'order_lines')] class extends VectorResource {};

        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('slug "order_lines" must be lower-case letters, digits and dashes');
        new ResourceCompiler($metadata, [$resource::class]);
    }

    public function testThePublishedFormHidesEveryColumnName(): void
    {
        $registry = VectorRegistry::fromArray(VectorRegistry::compile(new DiscoveryContext(dirname(__DIR__, 2))));
        $published = $registry->resource('products')->toArray();

        self::assertArrayNotHasKey('class', $published);
        self::assertArrayNotHasKey('entity', $published);
        self::assertArrayNotHasKey('columns', $published);

        foreach ($published['fields'] as $field) {
            self::assertArrayNotHasKey('column', $field);
            self::assertArrayNotHasKey('joinTable', $field['relation'] ?? []);
        }
    }

    /** @return array<string, mixed> */
    private function field(\Kinetis\Vector\Registry\ResourceDescriptor $resource, string $name): array
    {
        return $resource->field($name) ?? self::fail("{$resource->slug()} has no field {$name}");
    }

    /** @return array<string, mixed> */
    private function action(\Kinetis\Vector\Registry\ResourceDescriptor $resource, string $name): array
    {
        return $resource->action($name) ?? self::fail("{$resource->slug()} has no action {$name}");
    }
}

/** Order lines with every field derived, for the derived orders to point at. */
#[Resource(OrderLine::class, slug: 'derived-order-lines')]
final class DerivedOrderLines extends VectorResource {}
