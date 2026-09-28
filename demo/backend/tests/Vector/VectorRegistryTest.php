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
use PHPUnit\Framework\TestCase;

/** The registry compiles the demo application from source alone, with no database. */
final class VectorRegistryTest extends TestCase
{
    public function testTheDemoApplicationCompilesAndRoundTrips(): void
    {
        $data = VectorRegistry::compile(new DiscoveryContext(dirname(__DIR__, 2)));

        self::assertSame(4, $data['version']);
        self::assertSame(['main'], array_keys($data['dashboards']));
        self::assertSame(['branding', 'jobs', 'languages', 'store'], array_keys($data['settings']));
        self::assertSame(
            ['agents', 'categories', 'customer-profiles', 'customers', 'labels', 'order-lines', 'orders', 'products', 'support-teams', 'tags', 'ticket-replies', 'tickets', 'users'],
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
        self::assertSame(['sku', 'name'], $products->toArray()['table']['search']);
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
