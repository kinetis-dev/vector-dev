<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Vector\UserPolicy;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;

/**
 * `GET /vector/openapi.json` for an identity the policies restrict: the
 * users resource refused to it, the customers' `phone` and `email`
 * hidden from it and the products' `publish-products` action not its to
 * run. Each is absent from its document, not marked, and present in the
 * seeded admin's; and what the document describes is still exactly what
 * its own descriptor publishes.
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
}

/** Every resource without its own policy: $restricted sees no customer's phone or email, and may not publish. */
final class RestrictedViewerPolicy extends ResourcePolicy
{
    public function __construct(private readonly string $restricted) {}

    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        return (string) $user->id() !== $this->restricted || !in_array($field, ['phone', 'email'], true);
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
