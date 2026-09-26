<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Category;
use App\Domain\Customer;
use App\Domain\CustomerProfile;
use App\Domain\Order;
use App\Domain\OrderLine;
use App\Domain\Product;
use App\Domain\Tag;
use App\Domain\User;
use Kinetis\Orm\Metadata\MetadataRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The demo domain must map under the ORM's rules without a database:
 * this is what `kinetis build` compiles and what Vector derives from.
 */
final class DemoDomainMappingTest extends TestCase
{
    private const array ENTITIES = [
        Category::class, Customer::class, CustomerProfile::class, Order::class,
        OrderLine::class, Product::class, Tag::class, User::class,
    ];

    public function testEveryDemoEntityMaps(): void
    {
        $registry = MetadataRegistry::fromClasses(self::ENTITIES);
        $entities = $registry->toArray()['entities'];

        self::assertCount(count(self::ENTITIES), $entities);
        self::assertSame(
            ['categories', 'customers', 'customer_profiles', 'orders', 'order_lines', 'products', 'tags', 'users'],
            array_column($entities, 'table'),
        );
    }

    public function testProductCoversEveryScalarTypeAndBothRelationshipKinds(): void
    {
        $product = $this->entity(Product::class);

        self::assertSame('id', $product['id']);
        self::assertTrue($product['generated']);
        self::assertSame('version', $product['version']);

        $types = array_column($product['properties'], 'type', 'name');
        self::assertSame('string', $types['sku']);
        self::assertSame('int', $types['priceCents']);
        self::assertSame('float', $types['weightKg']);
        self::assertSame('bool', $types['isFeatured']);
        self::assertSame('timestamp', $types['releasedAt']);
        self::assertSame('int', $types['category'], 'a #[BelongsTo] maps its target identifier type');

        $columns = array_column($product['properties'], 'column', 'name');
        self::assertSame('price_cents', $columns['priceCents']);
        self::assertSame('category_id', $columns['category']);

        $enums = array_column($product['properties'], 'enum', 'name');
        self::assertSame(\App\Domain\ProductStatus::class, $enums['status']);

        self::assertSame(
            [['name' => 'tags', 'target' => Tag::class, 'table' => 'product_tag', 'joinColumn' => 'product_id', 'inverseJoinColumn' => 'tag_id', 'mappedBy' => null]],
            $product['joins'],
        );
    }

    public function testOrderOwnsItsLinesAndCustomerOwnsItsProfile(): void
    {
        $order = $this->entity(Order::class);
        self::assertSame([['name' => 'lines', 'kind' => 'hasMany', 'target' => OrderLine::class, 'mappedBy' => 'order', 'nullable' => false, 'owned' => true]], $order['inverses']);

        $customer = $this->entity(Customer::class);
        $inverses = array_column($customer['inverses'], null, 'name');
        self::assertTrue($inverses['profile']['owned']);
        self::assertSame('hasOne', $inverses['profile']['kind']);
        self::assertFalse($inverses['orders']['owned']);
    }

    public function testRoundTripThroughTheCompiledArray(): void
    {
        $array = MetadataRegistry::fromClasses(self::ENTITIES)->toArray();

        self::assertSame($array, MetadataRegistry::fromArray($array)->toArray());
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>
     */
    private function entity(string $class): array
    {
        foreach (MetadataRegistry::fromClasses(self::ENTITIES)->toArray()['entities'] as $entity) {
            if ($entity['class'] === $class) {
                return $entity;
            }
        }

        self::fail("{$class} is not mapped.");
    }
}
