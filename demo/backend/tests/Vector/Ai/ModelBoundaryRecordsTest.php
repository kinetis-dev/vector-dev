<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\VectorAi\Governance\ModelBoundary;

/**
 * ModelBoundary::record(), page() and toolResult(): what a model is sent
 * of the admin's data (never a password, file or image field or one
 * that opts out, nothing of a resource that opts out, long strings cut,
 * at most 25 rows) and under the allow-list only what opts in; and
 * keptFields(), the fields sends() refuses, which the shell's
 * excludedFields lists.
 */
final class ModelBoundaryRecordsTest extends AiTestCase
{
    private ?RequestScope $scope = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->scope?->dispose();
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
        parent::tearDown();
    }

    public function testARecordKeepsNoPasswordNoOptedOutFieldAndNothingUnknown(): void
    {
        [$boundary, $resources] = $this->boundary();

        $sent = $boundary->record($resources->resource('users'), [
            'id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com', 'passwordHash' => '$2y$secret', 'isAdmin' => true,
            'createdAt' => '2026-01-01T00:00:00Z', 'notAField' => 'leak', '_version' => 3, '_trashed' => false,
        ]);

        self::assertSame(['id' => 7, 'name' => 'Ada', 'isAdmin' => true, 'createdAt' => '2026-01-01T00:00:00Z', '_version' => 3, '_trashed' => false], $sent);
    }

    public function testALongStringIsCutWithTheMarker(): void
    {
        [$boundary, $resources] = $this->boundary();

        $sent = $boundary->record($resources->resource('users'), ['id' => 1, 'name' => str_repeat('é', 2500)]);

        self::assertIsString($sent['name']);
        self::assertSame(str_repeat('é', ModelBoundary::MAX_STRING) . ModelBoundary::TRUNCATED, $sent['name']);
        self::assertSame(ModelBoundary::MAX_STRING + mb_strlen(ModelBoundary::TRUNCATED), mb_strlen($sent['name']));
    }

    public function testNothingOfAResourceThatOptsOutIsSent(): void
    {
        [$boundary, $resources] = $this->boundary();
        $profiles = $resources->resource('profiles');

        self::assertSame([], $boundary->record($profiles, ['id' => 1, 'name' => 'Ada']));
        self::assertSame(['error' => ModelBoundary::UNAVAILABLE], $boundary->toolResult('vector_query', ['resource' => 'profiles'], ['data' => [['id' => 1, 'name' => 'Ada']]]));
        self::assertSame(['error' => ModelBoundary::UNAVAILABLE], $boundary->toolResult('vector_get', ['resource' => 'profiles', 'id' => '1'], ['data' => ['id' => 1]]));
        self::assertSame(['error' => ModelBoundary::UNAVAILABLE], $boundary->toolResult('vector_describe', ['resource' => 'profiles'], ['fields' => []]));
        self::assertSame(['error' => ModelBoundary::UNAVAILABLE], $boundary->toolResult('vector_query', ['resource' => 'no-such-thing'], ['data' => []]));
    }

    public function testAPageSendsAtMostTwentyFiveRowsAndNoSummaries(): void
    {
        [$boundary, $resources] = $this->boundary();
        $rows = array_map(static fn (int $i): array => ['id' => $i, 'name' => "User {$i}", 'passwordHash' => 'x'], range(1, 30));

        $sent = $boundary->page($resources->resource('users'), ['data' => $rows, 'currentPage' => 1, 'perPage' => 30, 'total' => 30, 'lastPage' => 1, 'summaries' => ['email' => 'x']]);

        self::assertCount(ModelBoundary::MAX_ROWS, $sent['data']);
        self::assertSame(['id' => 1, 'name' => 'User 1'], $sent['data'][0]);
        self::assertSame(5, $sent['omittedRows']);
        self::assertSame(30, $sent['total']);
        self::assertArrayNotHasKey('summaries', $sent);
    }

    public function testUnderTheAllowListOnlyWhatOptsInIsSent(): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: ['dataDefault' => 'allowlist'], headers: $this->adminHeaders())->assertOk();
        [$boundary, $resources] = $this->boundary();

        $sent = $boundary->record($resources->resource('products'), [
            'id' => 3, 'sku' => 'SKU-1', 'name' => 'Lamp', 'status' => 'active', 'stock' => 4, 'priceCents' => 1999,
            'category' => ['id' => 2, 'label' => 'Lighting'], 'imageKey' => 'products/lamp.png',
        ]);

        // categories do not opt in: the relation keeps its id, not its label
        self::assertSame(['id' => 3, 'name' => 'Lamp', 'status' => 'active', 'stock' => 4, 'category' => ['id' => 2]], $sent);
        self::assertSame([], $boundary->record($resources->resource('users'), ['id' => 1, 'name' => 'Ada']), 'users do not opt in');
    }

    public function testKeptFieldsAreThoseSendsRefusesByDefault(): void
    {
        [$boundary, $resources] = $this->boundary();

        // a password and an opted-out field; never the primary key
        self::assertSame(['email', 'passwordHash'], $boundary->keptFields($resources->registry()->resource('users')));
        // an image field, though nothing opts out
        self::assertSame(['imageKey'], $boundary->keptFields($resources->registry()->resource('products')));
        // a resource that opts out whole: every field
        $profiles = $resources->registry()->resource('profiles');
        self::assertSame(array_keys($resources->visible($profiles)->fields()), $boundary->keptFields($profiles));
        self::assertSameAsSends($boundary, $resources);
    }

    public function testAFieldThatOptsOutIsKept(): void
    {
        [$boundary, $resources] = $this->boundary($this->registryWith(['users.name' => ['ai.send' => false]]));

        self::assertSame(['name', 'email', 'passwordHash'], $boundary->keptFields($resources->registry()->resource('users')));
        self::assertSameAsSends($boundary, $resources);
    }

    public function testUnderTheAllowListEveryFieldThatDoesNotOptInIsKept(): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: ['dataDefault' => 'allowlist'], headers: $this->adminHeaders())->assertOk();
        [$boundary, $resources] = $this->boundary($this->registryWith(['products.imageKey' => ['ai.send' => true]]));

        // products opt in with name, status, category and stock; an image field is kept though it opts in
        self::assertSame(
            ['sku', 'slug', 'priceCents', 'currency', 'weightKg', 'isFeatured', 'description', 'attributes', 'imageKey', 'tags', 'releasedAt', 'createdAt', 'updatedAt'],
            $boundary->keptFields($resources->registry()->resource('products')),
        );
        self::assertSameAsSends($boundary, $resources);
    }

    public function testAPasswordAFileAndAnImageFieldAreKeptThoughTheyOptIn(): void
    {
        $registry = $this->registryWith(['users.passwordHash' => ['ai.send' => true], 'products.imageKey' => ['ai.send' => true], 'products.sku' => ['ai.send' => true]]);
        $data = $registry->toArray();

        foreach ($data['resources']['products']['fields'] as $i => $field) {
            if (is_array($field) && ($field['name'] ?? null) === 'sku') {
                $data['resources']['products']['fields'][$i]['type'] = 'file';
            }
        }

        [$boundary, $resources] = $this->boundary(VectorRegistry::fromArray($data));

        self::assertSame(['email', 'passwordHash'], $boundary->keptFields($resources->registry()->resource('users')));
        self::assertSame(['sku', 'imageKey'], $boundary->keptFields($resources->registry()->resource('products')));
        self::assertSameAsSends($boundary, $resources);
    }

    /** keptFields() of every resource is the fields of it, as this user sees it, that sends() refuses, in the same order. */
    private static function assertSameAsSends(ModelBoundary $boundary, ResourceService $resources): void
    {
        foreach ($resources->registry()->resources() as $slug => $resource) {
            $visible = $resources->visible($resource);
            $refused = array_values(array_filter(array_keys($visible->fields()), static fn (string $name): bool => !$boundary->sends($visible, $name)));
            self::assertSame($refused, $boundary->keptFields($resource), "{$slug}: keptFields() and sends() disagree");
        }
    }

    public function testARelationsLabelIsSentOnlyWhenItsTargetsTitleFieldIs(): void
    {
        $record = ['id' => 9, 'number' => 'ORD-9', 'customer' => ['id' => 4, 'label' => 'Acme GmbH']];

        [$sending] = $this->boundary();
        self::assertSame(['id' => 4, 'label' => 'Acme GmbH'], $sending->record($sending->sendable('orders') ?? self::fail('orders'), $record)['customer']);
        $this->scope?->dispose();

        // the customers' title field opts out: the label is that field's value
        [$boundary, $resources] = $this->boundary($this->registryWith(['customers.name' => ['ai.send' => false]]));
        self::assertSame(['id' => 4], $boundary->record($resources->resource('orders'), $record)['customer']);
    }

    public function testAOneToOneValueKeepsItsLabelUnderItsTargetsRules(): void
    {
        // a customer's profile, as ResourceService serializes a hasOne: the related record's id and title
        $customer = ['id' => 4, 'name' => 'Acme GmbH', 'profile' => ['id' => 2, 'label' => 'Berlin']];

        [$sending] = $this->boundary();
        self::assertSame(['id' => 2, 'label' => 'Berlin'], $sending->record($sending->sendable('customers') ?? self::fail('customers'), $customer)['profile']);
        $this->scope?->dispose();

        // the profiles' title field opts out: the label is that field's value
        [$boundary, $resources] = $this->boundary($this->registryWith(['customer-profiles.city' => ['ai.send' => false]]));
        self::assertSame(['id' => 2], $boundary->record($resources->resource('customers'), $customer)['profile']);
        $this->scope?->dispose();

        [$boundary, $resources] = $this->boundary($this->registryWith(['customer-profiles' => ['ai.send' => false]]));
        self::assertSame(['id' => 2], $boundary->record($resources->resource('customers'), $customer)['profile'], 'a target kept from the model: its id alone');
    }

    public function testUnderTheAllowListALabelNeedsTheTargetsTitleFieldToOptIn(): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: ['dataDefault' => 'allowlist'], headers: $this->adminHeaders())->assertOk();
        $product = ['id' => 3, 'name' => 'Lamp', 'category' => ['id' => 2, 'label' => 'Lighting']];

        // categories opt in, their name does not
        [$boundary, $resources] = $this->boundary($this->registryWith(['categories' => ['ai.send' => true]]));
        self::assertSame(['id' => 2], $boundary->record($resources->resource('products'), $product)['category']);
        $this->scope?->dispose();

        [$boundary, $resources] = $this->boundary($this->registryWith(['categories' => ['ai.send' => true], 'categories.name' => ['ai.send' => true]]));
        self::assertSame(['id' => 2, 'label' => 'Lighting'], $boundary->record($resources->resource('products'), $product)['category']);
    }

    public function testACarriedColumnIsSentOnlyWhenItsTargetFieldIs(): void
    {
        // an order line's order carries the order's currency, for its money fields
        $line = ['id' => 5, 'order' => ['id' => 9, 'label' => 'ORD-9', 'currency' => 'EUR']];

        [$sending] = $this->boundary();
        self::assertSame(['id' => 9, 'label' => 'ORD-9', 'currency' => 'EUR'], $sending->record($sending->sendable('order-lines') ?? self::fail('order-lines'), $line)['order']);
        $this->scope?->dispose();

        [$boundary, $resources] = $this->boundary($this->registryWith(['orders.currency' => ['ai.send' => false]]));
        self::assertSame(['id' => 9, 'label' => 'ORD-9'], $boundary->record($resources->resource('order-lines'), $line)['order']);
    }

    public function testADescriptionIsCutAsTheSchemaIs(): void
    {
        [$boundary] = $this->boundary();
        $described = $boundary->toolResult('vector_describe', ['resource' => 'users'], [
            'resource' => 'users', 'titleField' => 'email', 'search' => ['name', 'email'], 'defaultSort' => [['field' => 'email', 'order' => 'asc']],
            'fields' => [['name' => 'id', 'sortable' => true], ['name' => 'name', 'rules' => ['NotBlank'], 'searchable' => true], ['name' => 'email', 'searchable' => true], ['name' => 'passwordHash'], ['name' => 'isAdmin']],
            'actions' => [['name' => 'reset-password', 'fields' => [['name' => 'passwordHash']]]],
        ]);

        self::assertIsArray($described);
        self::assertSame([['name' => 'id', 'sortable' => true], ['name' => 'name'], ['name' => 'isAdmin']], $described['fields'], 'no excluded field, nothing only a write needs, and no search GovernedTool refuses');
        self::assertNull($described['titleField']);
        self::assertSame([], $described['search'], 'users search their email too: q is refused, so no search is described');
        self::assertSame([['field' => 'id', 'order' => 'asc']], $described['defaultSort'], 'the order a query naming none takes');
        self::assertArrayNotHasKey('actions', $described);

        // products search only what may be sent: their search is described
        $products = $boundary->toolResult('vector_describe', ['resource' => 'products'], ['search' => ['sku', 'name'], 'fields' => [['name' => 'name', 'searchable' => true]]]);
        self::assertIsArray($products);
        self::assertSame(['sku', 'name'], $products['search']);
        self::assertSame([['name' => 'name', 'searchable' => true]], $products['fields']);
        self::assertSame([['field' => 'createdAt', 'order' => 'desc']], $products['defaultSort']);
    }

    public function testOnlyTheListedToolsPassAndAWidgetLinkingToAKeptResourceIsRefused(): void
    {
        [$boundary] = $this->boundary();

        self::assertSame(['resources' => [['slug' => 'profiles']]], $boundary->toolResult('vector_resources', [], ['resources' => [['slug' => 'profiles']]]));
        self::assertSame(['error' => ModelBoundary::UNAVAILABLE], $boundary->toolResult('vector_unknown', ['resource' => 'users'], ['data' => ['id' => 1]]));
        // an approved write's result, as the continuation tells the model: governed like a read, never a download's bytes
        self::assertSame(['error' => ModelBoundary::UNAVAILABLE], $boundary->toolResult('vector_update', ['resource' => 'profiles', 'id' => '1'], ['data' => ['id' => 1]]));
        self::assertSame(['deleted' => '7'], $boundary->toolResult('vector_delete', ['resource' => 'products', 'id' => '7'], ['deleted' => '7']));
        self::assertSame(['type' => 'download'], $boundary->toolResult('vector_run_action', ['resource' => 'products', 'action' => 'x'], ['type' => 'download', 'filename' => 'a.csv', 'contents' => 'c2VjcmV0']));

        $table = static fn (string $link): array => ['data' => ['type' => 'table', 'columns' => [], 'rows' => [['id' => 1, '_link' => $link]], 'link' => null]];
        self::assertSame($table('/r/products/1'), $boundary->toolResult('vector_widget', ['widget' => 'low-stock'], $table('/r/products/1')));
        self::assertSame(['error' => ModelBoundary::UNAVAILABLE], $boundary->toolResult('vector_widget', ['widget' => 'x'], $table('/r/profiles/1')));
        self::assertSame(['error' => ModelBoundary::UNAVAILABLE], $boundary->toolResult('vector_widget', ['widget' => 'x', 'resource' => 'profiles'], ['data' => ['type' => 'stat', 'value' => 1]]));
    }

    public function testATableLeavesOutTheColumnsItsLinkedResourceKeepsBack(): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: ['dataDefault' => 'allowlist'], headers: $this->adminHeaders())->assertOk();
        [$boundary] = $this->boundary();
        // the low-stock table as the demo computes it: products opt in, their SKU does not
        $lowStock = ['data' => [
            'type' => 'table',
            'columns' => [['key' => 'sku', 'label' => 'SKU'], ['key' => 'name', 'label' => 'Product'], ['key' => 'stock', 'label' => 'Stock', 'align' => 'end']],
            'rows' => [['id' => 3, 'sku' => 'SKU-3', 'name' => 'Lamp', 'stock' => 2, '_link' => '/r/products/3']],
            'link' => '/r/products?filter[stock][lte]=10&filter[status]=active',
        ]];

        self::assertSame(['data' => [
            'type' => 'table',
            'columns' => [['key' => 'name', 'label' => 'Product'], ['key' => 'stock', 'label' => 'Stock', 'align' => 'end']],
            'rows' => [['id' => 3, 'name' => 'Lamp', 'stock' => 2, '_link' => '/r/products/3']],
            'link' => '/r/products?filter[stock][lte]=10&filter[status]=active',
        ]], $boundary->toolResult('vector_widget', ['widget' => 'low-stock', 'dashboard' => 'main'], $lowStock));
    }

    public function testATableRowLeavesOutWhatItsOwnLinkKeepsBackAndAnyWidgetLinkingToAKeptResourceIsRefused(): void
    {
        [$boundary] = $this->boundary();
        $table = ['data' => ['type' => 'table', 'columns' => [['key' => 'name'], ['key' => 'email'], ['key' => 'logins']], 'rows' => [['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.com', 'logins' => 4, '_link' => '/r/users/1']], 'link' => null]];

        // users' email opts out; a key that is no field of theirs is the widget's own figure
        self::assertSame(
            ['data' => ['type' => 'table', 'columns' => [['key' => 'name'], ['key' => 'logins']], 'rows' => [['id' => 1, 'name' => 'Ada', 'logins' => 4, '_link' => '/r/users/1']], 'link' => null]],
            $boundary->toolResult('vector_widget', ['widget' => 'x', 'dashboard' => 'main'], $table),
            'the row loses the email, and the table its column',
        );

        $stat = static fn (string $link): array => ['data' => ['type' => 'stat', 'value' => 4, 'link' => $link]];
        self::assertSame(['error' => ModelBoundary::UNAVAILABLE], $boundary->toolResult('vector_widget', ['widget' => 'x', 'dashboard' => 'main'], $stat('/r/profiles?filter[isAdmin]=true')));
        self::assertSame($stat('/r/users?filter[isAdmin]=true'), $boundary->toolResult('vector_widget', ['widget' => 'x', 'dashboard' => 'main'], $stat('/r/users?filter[isAdmin]=true')));
    }

    /**
     * The demo's registry with $meta set on resources (`slug`) and fields
     * (`slug.field`), as `#[Resource(meta: …)]` and `->meta()` would.
     *
     * @param array<string, array<string, mixed>> $meta
     */
    private function registryWith(array $meta): VectorRegistry
    {
        $registry = $this->app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();

        foreach ($meta as $target => $values) {
            [$slug, $name] = [...explode('.', $target, 2), null];
            self::assertIsString($slug);
            self::assertArrayHasKey($slug, $data['resources']);

            if ($name === null) {
                $data['resources'][$slug]['meta'] = [...($data['resources'][$slug]['meta'] ?? []), ...$values];

                continue;
            }

            $found = false;

            foreach ($data['resources'][$slug]['fields'] as $i => $field) {
                if (is_array($field) && ($field['name'] ?? null) === $name) {
                    $data['resources'][$slug]['fields'][$i]['meta'] = [...(is_array($field['meta'] ?? null) ? $field['meta'] : []), ...$values];
                    $found = true;
                }
            }

            self::assertTrue($found, "{$target} is a field");
        }

        return VectorRegistry::fromArray($data);
    }

    /** @return array{ModelBoundary, ResourceService} built in a request scope of the seeded admin's */
    private function boundary(?VectorRegistry $registry = null): array
    {
        $this->scope = $this->app->createRequestScope();
        $this->scope->instance(CurrentUserInterface::class, new DemoActor(1, 'Admin', true));

        if ($registry !== null) {
            $this->scope->instance(VectorRegistry::class, $registry);
        }

        $boundary = $this->scope->get(ModelBoundary::class);
        $resources = $this->scope->get(ResourceService::class);
        self::assertInstanceOf(ModelBoundary::class, $boundary);
        self::assertInstanceOf(ResourceService::class, $resources);

        return [$boundary, $resources];
    }
}
