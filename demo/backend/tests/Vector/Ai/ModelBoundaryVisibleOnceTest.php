<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\VectorAi\Governance\ModelBoundary;

/**
 * ModelBoundary reads a resource as the user sees it once, not once a
 * row: a page of 25 products asks the `viewField` policy once per field.
 * Each boundary asks for itself; a new one, a new request, asks again.
 */
final class ModelBoundaryVisibleOnceTest extends AiTestCase
{
    private CountingFieldPolicy $policy;

    private ?RequestScope $scope = null;

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new CountingFieldPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->scope?->dispose();
        parent::tearDown();
    }

    public function testAPageAsksEachFieldOnce(): void
    {
        $this->scope = $this->app->createRequestScope();
        $this->scope->instance(CurrentUserInterface::class, new DemoActor(1, 'Admin', true));
        $boundary = $this->scope->get(ModelBoundary::class);
        $resources = $this->scope->get(ResourceService::class);
        self::assertInstanceOf(ModelBoundary::class, $boundary);
        self::assertInstanceOf(ResourceService::class, $resources);

        $products = $resources->registry()->resource('products');
        $fields = count($products->fields());
        $rows = array_map(static fn (int $i): array => ['id' => $i, 'name' => "Lamp {$i}", 'stock' => $i], range(1, 25));

        $this->policy->asked = 0;
        $sent = $boundary->page($products, ['data' => $rows]);
        self::assertCount(25, $sent['data']);
        self::assertSame($fields, $this->policy->asked, 'one viewField a field, not one a row');

        // asked again of the same boundary: nothing more
        $boundary->page($products, ['data' => $rows]);
        self::assertSame($fields, $this->policy->asked);
    }
}

/** Allows everything, counting the viewField decisions it is asked. */
final class CountingFieldPolicy extends ResourcePolicy
{
    public int $asked = 0;

    #[\Override]
    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        $this->asked++;

        return true;
    }
}
