<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Product;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Badge;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;

/**
 * Menu badges: `GET /vector/badges` counts each resource's badge filter
 * as the user's own list would, for the resources that user may list.
 */
final class MenuBadgesTest extends VectorTestCase
{
    use InteractsWithVector;

    private BadgePolicy $policy;

    private ?EntityManager $supportManager = null;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new BadgePolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
    }

    public function testEachBadgeCountsItsFilter(): void
    {
        $badges = $this->vector($this->adminHeaders())->badges()->assertOk()->json()['data'];

        $lowStock = $this->shop()->table('products')->where('deleted_at', '=', null)->where('status', '=', 'active')->where('stock', '<=', 10)->count();
        self::assertSame(['count' => $lowStock, 'color' => 'warning', 'label' => 'active products low on stock'], $badges['products']);

        $open = $this->support()->table('tickets')->where('deleted_at', '=', null)->where('status', '=', 'open')->count();
        self::assertSame($open, $badges['tickets']['count']);
        self::assertArrayNotHasKey('tags', $badges, 'a resource without a badge has none');
    }

    public function testABadgeOnAFieldTheUserCannotSeeIsLeftOut(): void
    {
        $this->policy->hidden = 'stock';

        $badges = $this->vector($this->adminHeaders())->badges()->assertOk()->json()['data'];
        self::assertArrayNotHasKey('products', $badges);
        self::assertArrayHasKey('tickets', $badges);

        $this->vector()->badges()->assertUnauthenticated();
    }

    public function testABadgeFiltersWhatTheListCanFilter(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $unfilterable = new #[Resource(Product::class, slug: 'badge-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Text::make('name')->searchable()];
            }

            public function badge(): Badge
            {
                return Badge::count(['name' => 'x']);
            }
        };
        $operator = new #[Resource(Product::class, slug: 'badge-products')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), Number::make('stock')->filterable()];
            }

            public function badge(): Badge
            {
                return Badge::count(['stock' => ['like' => '1']]);
            }
        };

        foreach ([[$unfilterable, 'is not a filterable field'], [$operator, 'which that field does not take']] as [$resource, $message]) {
            try {
                (new ResourceCompiler($metadata, [$resource::class]))->compile();
                self::fail("compiled: {$message}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
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

/** Allows everything, but hides one field when the test names it. */
final class BadgePolicy extends ResourcePolicy
{
    public ?string $hidden = null;

    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        return $field !== $this->hidden;
    }
}
