<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\DemoActor;
use App\Vector\UserPolicy;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Http\VisibleResources;
use Kinetis\Vector\Jobs\VectorQueue;
use Kinetis\Vector\OpenApi\OpenApiCache;
use Kinetis\Vector\OpenApi\OpenApiDocument;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\VectorDefaults;

/**
 * `GET /vector/openapi.json` for an identity the policies restrict: the
 * users resource refused to it; the customers' `phone` and searched
 * `email`, and the products' summarized `stock`, hidden from it; and the
 * products' `publish-products` action not its to run. Each is absent from
 * its document, not marked, and present in the seeded admin's; and what
 * the document describes is still exactly what its own descriptor
 * publishes.
 *
 * The schemas OpenApiCache keeps for the worker are shared between
 * identities. That holds because a policy can only take a field, an
 * action or a resource away (every ResourcePolicyInterface hook answers
 * yes or no), never change one: the last two tests pin that.
 */
final class OpenApiVisibilityTest extends VectorTestCase
{
    private const string RESTRICTED = '990003';

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(AllowAllPolicy::class, new RestrictedViewerPolicy(self::RESTRICTED));
        $app->instance(UserPolicy::class, new RefusedViewerPolicy(self::RESTRICTED));
    }

    public function testWhatThePolicyHidesIsAbsentFromTheRestrictedDocument(): void
    {
        $restricted = ['Authorization' => 'Bearer ' . $this->tokenFor(self::RESTRICTED)];
        $admin = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json();
        $theirs = $this->client->get('/vector/openapi.json', headers: $restricted)->assertOk();
        $document = $theirs->json();
        self::assertIsArray($admin);
        self::assertIsArray($document);

        // the forbidden resource: no tag, no path, no schema
        self::assertContains('users', array_column($admin['tags'], 'name'));
        self::assertNotContains('users', array_column($document['tags'], 'name'));
        self::assertSame([], array_values(array_filter(array_map('strval', array_keys($document['paths'])), static fn (string $p): bool => str_starts_with($p, '/vector/r/users'))));
        self::assertSame([], array_values(array_filter(array_map('strval', array_keys($document['components']['schemas'])), static fn (string $s): bool => str_starts_with($s, 'users.'))));

        // the hidden fields: in no schema, no filter, no column, no search
        foreach (['phone', 'email'] as $field) {
            self::assertArrayHasKey($field, $admin['components']['schemas']['customers.Record']['properties']);

            foreach (['customers.Record', 'customers.Update', 'customers.Create'] as $schema) {
                self::assertArrayNotHasKey($field, (array) ($document['components']['schemas'][$schema]['properties'] ?? []), "{$schema}.{$field}");
            }

            foreach (['/vector/r/customers', '/vector/r/customers/export'] as $path) {
                if (!isset($document['paths'][$path]['get'])) {
                    continue;
                }

                foreach ($document['paths'][$path]['get']['parameters'] as $parameter) {
                    self::assertStringNotContainsString("[{$field}]", $parameter['name'], "{$path}: {$parameter['name']}");
                    self::assertNotContains($field, $parameter['schema']['items']['enum'] ?? [], "{$path}: {$parameter['name']}");
                    self::assertStringNotContainsString($field, $parameter['description'] ?? '', "{$path}: {$parameter['name']}");
                }
            }
        }

        // searched and summarized for the admin; neither description names it for this identity
        self::assertStringContainsString('email', self::parameter($admin, '/vector/r/customers', 'q')['description']);
        self::assertStringNotContainsString('email', self::parameter($document, '/vector/r/customers', 'q')['description']);
        self::assertStringContainsString('stock', self::parameter($admin, '/vector/r/products', 'summaries')['description']);
        self::assertStringNotContainsString('stock', self::parameter($document, '/vector/r/products', 'summaries')['description']);
        self::assertArrayNotHasKey('stock', (array) $document['components']['schemas']['products.Record']['properties']);

        // the action it may not run: no path
        self::assertArrayHasKey('/vector/r/products/actions/publish-products', $admin['paths']);
        self::assertArrayNotHasKey('/vector/r/products/actions/publish-products', $document['paths']);
        self::assertArrayHasKey('/vector/r/products/actions/archive-products', $document['paths']);

        // and everything else is its descriptor's
        $schema = $this->client->get('/vector/schema', headers: $restricted)->assertOk()->json();
        self::assertIsArray($schema);
        self::assertSame(array_keys($schema['resources']), array_column($document['tags'], 'name'));

        foreach ($schema['resources'] as $slug => $resource) {
            OpenApiDocumentTest::assertDescribes($document, $slug, $resource);
        }

        // two identities, two documents, two tags
        self::assertNotSame($admin, $document);
        $adminTag = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->getHeaderLine('ETag');
        $this->client->get('/vector/openapi.json', headers: [...$restricted, 'If-None-Match' => $adminTag])->assertOk();
        $this->client->get('/vector/openapi.json', headers: [...$restricted, 'If-None-Match' => $theirs->getHeaderLine('ETag')])->assertStatus(304);
    }

    /**
     * One worker, one OpenApiCache: the restricted identity warms it, and
     * the admin's document is then the one a cold cache builds for them.
     */
    public function testTheAdminAfterTheRestrictedIdentityGetsTheirOwnDocument(): void
    {
        $this->client->get('/vector/openapi.json', headers: ['Authorization' => 'Bearer ' . $this->tokenFor(self::RESTRICTED)])->assertOk();
        $served = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json();

        self::assertSame($this->builtCold(1), $served);
    }

    /** The other way round: the admin warms it, the restricted identity follows. */
    public function testTheRestrictedIdentityAfterTheAdminGetsTheirOwnDocument(): void
    {
        $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk();
        $served = $this->client->get('/vector/openapi.json', headers: ['Authorization' => 'Bearer ' . $this->tokenFor(self::RESTRICTED)])->assertOk()->json();

        self::assertSame($this->builtCold((int) self::RESTRICTED), $served);
    }

    /**
     * What every identity sees of a field or an action is the compiled
     * one, unchanged, or nothing: the policy removes, it never alters. The
     * one per-request change ResourceService makes, the VECTOR_CURRENCY
     * default, comes from the worker's configuration, not the identity.
     * A policy hook that changed a field's type, rules, options,
     * nullability or read-only flag for one identity fails here, and with
     * it the premise of OpenApiCache.
     */
    public function testAPolicyRemovesFieldsAndActionsAndNeverAltersThem(): void
    {
        foreach ([1, (int) self::RESTRICTED] as $userId) {
            $this->inScopeOf($userId, static function (RequestScope $scope) use ($userId): void {
                $service = $scope->get(ResourceService::class);
                $defaults = $scope->get(VectorDefaults::class);
                self::assertInstanceOf(ResourceService::class, $service);
                self::assertInstanceOf(VectorDefaults::class, $defaults);
                $hidden = 0;

                foreach ($service->registry()->resources() as $slug => $compiled) {
                    $compiled = $compiled->withDefaultCurrency($defaults->currency);
                    $seen = $service->resource($slug);

                    foreach ($seen->fields() as $name => $field) {
                        self::assertSame($compiled->fields()[$name], $field, "{$userId}: {$slug}.{$name}");
                    }

                    foreach ($compiled->toArray()['actions'] as $action) {
                        self::assertSame($compiled->action($action['name']), $seen->action($action['name']), "{$userId}: {$slug} action {$action['name']}");
                    }

                    $hidden += count($compiled->fields()) - count($seen->fields());
                }

                // the restricted identity really does see less
                self::assertSame($userId === 1, $hidden === 0, "{$userId}: fields hidden");
            });
        }
    }

    /**
     * $userId's document from an OpenApiCache of its own, as the first
     * request of a fresh worker builds it.
     *
     * @return array<string, mixed>
     */
    private function builtCold(int $userId): array
    {
        $document = null;

        $this->inScopeOf($userId, static function (RequestScope $scope) use (&$document): void {
            $visible = $scope->get(VisibleResources::class);
            $service = $scope->get(ResourceService::class);
            $queue = $scope->get(VectorQueue::class);
            $defaults = $scope->get(VectorDefaults::class);
            self::assertInstanceOf(VisibleResources::class, $visible);
            self::assertInstanceOf(ResourceService::class, $service);
            self::assertInstanceOf(VectorQueue::class, $queue);
            self::assertInstanceOf(VectorDefaults::class, $defaults);
            $built = new OpenApiDocument($visible, $service, new OpenApiCache(), $queue, $defaults)->build();
            $document = json_decode(json_encode($built, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true, 512, JSON_THROW_ON_ERROR);
        });

        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    /** @param \Closure(RequestScope): void $work run in a request scope signed in as $userId */
    private function inScopeOf(int $userId, \Closure $work): void
    {
        $scope = $this->app->createRequestScope();

        try {
            self::assertInstanceOf(RequestScope::class, $scope);
            $scope->instance(CurrentUserInterface::class, new DemoActor($userId, 'Test', true));
            $work($scope);
        } finally {
            $scope->dispose();
        }
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function parameter(array $document, string $path, string $name): array
    {
        $parameters = array_column($document['paths'][$path]['get']['parameters'], null, 'name');
        self::assertArrayHasKey($name, $parameters, "{$path}: {$name}");

        return $parameters[$name];
    }
}

/** Every resource without its own policy: $restricted sees no customer's phone or email nor a product's stock, and may not publish. */
final class RestrictedViewerPolicy extends ResourcePolicy
{
    public function __construct(private readonly string $restricted) {}

    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        return (string) $user->id() !== $this->restricted || !in_array($field, ['phone', 'email', 'stock'], true);
    }

    public function runAction(CurrentUserInterface $user, string $action): bool
    {
        return (string) $user->id() !== $this->restricted || $action !== 'publish-products';
    }
}

/** The users resource, which $restricted may not list. */
final class RefusedViewerPolicy extends ResourcePolicy
{
    public function __construct(private readonly string $restricted) {}

    public function viewAny(CurrentUserInterface $user): bool
    {
        return (string) $user->id() !== $this->restricted;
    }
}
