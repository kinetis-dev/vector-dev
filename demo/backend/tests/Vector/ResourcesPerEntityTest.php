<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Customer;
use App\Domain\Order;
use App\Domain\OrderLine;
use App\Domain\Product;
use App\Domain\Tag;
use App\Domain\User;
use App\Vector\CustomerResource;
use App\Vector\ProfileResource;
use App\Vector\UserResource;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\VectorResource;
use PHPUnit\Framework\TestCase;

/**
 * An entity may have several resources: exactly one of them is the
 * relation target, and a derived relation to the entity links there.
 */
final class ResourcesPerEntityTest extends TestCase
{
    public function testTwoResourcesOverOneEntityCompileWithOneRelationTarget(): void
    {
        $data = VectorRegistry::compile(new DiscoveryContext(dirname(__DIR__, 2)));

        self::assertSame([User::class, User::class], [$data['resources']['users']['entity'], $data['resources']['profiles']['entity']]);
        self::assertSame([UserResource::class, ProfileResource::class], [$data['resources']['users']['class'], $data['resources']['profiles']['class']]);
        self::assertSame(['id', 'name', 'email', 'createdAt'], array_column($data['resources']['profiles']['fields'], 'name'));

        $registry = VectorRegistry::fromArray($data);
        self::assertSame($data, $registry->toArray());
        self::assertSame('users', $registry->resource('users')->table());
        self::assertSame('users', $registry->resource('profiles')->table());
    }

    public function testDerivedRelationsLinkToTheRelationTarget(): void
    {
        // Each target entity has two resources; the marked one is never the demo's own.
        $vipCustomers = new #[Resource(Customer::class, slug: 'vip-customers', relationTarget: true)] class extends VectorResource {};
        $markedLines = new #[Resource(OrderLine::class, slug: 'marked-order-lines', relationTarget: true)] class extends VectorResource {};
        $tags = new #[Resource(Tag::class, slug: 'derived-tags')] class extends VectorResource {};
        $markedTags = new #[Resource(Tag::class, slug: 'marked-tags', relationTarget: true)] class extends VectorResource {};
        $orders = new #[Resource(Order::class, slug: 'derived-orders', relationTarget: true)] class extends VectorResource {};
        $products = new #[Resource(Product::class, slug: 'derived-products')] class extends VectorResource {};
        // a relation that names its resource keeps it, relation target or not
        $explicitOrders = new #[Resource(Order::class, slug: 'explicit-orders')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BelongsTo::to('customer', CustomerResource::class)];
            }
        };

        $compiled = (new ResourceCompiler(self::metadata(), [
            CustomerResource::class, $vipCustomers::class,
            DerivedOrderLines::class, $markedLines::class,
            $tags::class, $markedTags::class,
            $orders::class, $explicitOrders::class, $products::class,
        ]))->compile();

        self::assertSame(['belongsTo', 'vip-customers'], self::relation($compiled['derived-orders'], 'customer'), 'an owning relation');
        self::assertSame(['hasMany', 'marked-order-lines'], self::relation($compiled['derived-orders'], 'lines'), 'an inverse relation');
        self::assertSame(['manyToMany', 'marked-tags'], self::relation($compiled['derived-products'], 'tags'), 'a join relation');
        self::assertSame(['hasMany', 'derived-orders'], self::relation($compiled['customers'], 'orders'), 'an inverse relation from the unmarked side');
        self::assertSame(['manyToMany', 'derived-products'], self::relation($compiled['marked-tags'], 'products'), 'an inverse join to a single resource');
        self::assertSame(['belongsTo', 'customers'], self::relation($compiled['explicit-orders'], 'customer'), 'a declared relation');
    }

    public function testAnEntityWithSeveralResourcesAndNoRelationTargetDoesNotCompile(): void
    {
        $other = new #[Resource(User::class, slug: 'other-users')] class extends VectorResource {};

        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage(User::class . ' has 2 resources (' . ProfileResource::class . ', ' . $other::class . '), and none is the relation target');
        new ResourceCompiler(self::metadata(), [ProfileResource::class, $other::class]);
    }

    public function testAnEntityWithTwoRelationTargetsDoesNotCompile(): void
    {
        $other = new #[Resource(User::class, slug: 'other-users', relationTarget: true)] class extends VectorResource {};

        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage(User::class . ' has 3 resources (' . UserResource::class . ', ' . ProfileResource::class . ', ' . $other::class . '), and more than one is the relation target (' . UserResource::class . ', ' . $other::class . ')');
        new ResourceCompiler(self::metadata(), [UserResource::class, ProfileResource::class, $other::class]);
    }

    public function testTwoResourcesStillNeedTwoSlugs(): void
    {
        $other = new #[Resource(User::class)] class extends VectorResource {};

        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('slug "users" is already used by ' . UserResource::class);
        new ResourceCompiler(self::metadata(), [UserResource::class, $other::class]);
    }

    /**
     * Every resource the demo had before an entity could have several
     * compiles byte for byte as it did then: the fixture was captured
     * from the compiler at 826da67. Users, now one of two resources over
     * its entity, is among them. Products and tickets have since declared
     * a searchable relation and full-text search, and the fixture holds
     * them with those declarations.
     */
    public function testEveryExistingDescriptorIsUnchanged(): void
    {
        $before = file_get_contents(__DIR__ . '/Fixtures/resources-826da67.json');
        self::assertIsString($before);
        $decoded = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $resources = VectorRegistry::compile(new DiscoveryContext(dirname(__DIR__, 2)))['resources'];
        $existing = array_intersect_key($resources, $decoded);

        self::assertSame(array_keys($decoded), array_keys($existing), 'every resource of then is still compiled');
        self::assertSame($before, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }

    private static function metadata(): MetadataRegistry
    {
        return MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
    }

    /**
     * @param array<string, mixed> $resource
     * @return array{0: mixed, 1: mixed}
     */
    private static function relation(array $resource, string $name): array
    {
        $field = array_column($resource['fields'], null, 'name')[$name] ?? self::fail("{$resource['slug']} has no field {$name}");

        return [$field['type'], $field['relation']['resource'] ?? null];
    }
}
