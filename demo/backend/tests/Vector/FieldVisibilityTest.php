<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;

/**
 * Per-field visibility and many-to-many membership policies. The default
 * policy of every resource without its own is replaced by one that hides
 * `phone` and `email` and refuses to detach a product's tags, so the
 * customers resource shows what a hidden field means end to end: gone
 * from the descriptor, the records, the audit trail and the export;
 * refused as a filter, a sort, a column or a written value; and, being
 * required by the entity, it makes creation impossible for this user.
 */
final class FieldVisibilityTest extends VectorTestCase
{
    private RestrictingPolicy $policy;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new RestrictingPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
    }

    public function testAHiddenFieldLeavesTheDescriptorAndMakesARequiredOneUncreatable(): void
    {
        $schema = $this->client->get('/vector/schema/customers', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        $names = array_column($schema['fields'], 'name');
        self::assertNotContains('phone', $names);
        self::assertNotContains('email', $names);
        self::assertContains('name', $names);
        self::assertNotContains('phone', $schema['table']['columns']);
        self::assertNotContains('email', $schema['table']['search']);
        self::assertFalse($schema['permissions']['create'], 'the entity requires email, which this account cannot see');

        $this->client->post('/vector/r/customers', body: ['name' => 'Nobody'], headers: $this->adminHeaders())->assertStatus(403);
    }

    public function testAHiddenFieldIsNeverReadFilteredSortedOrWritten(): void
    {
        $h = $this->adminHeaders();
        $page = $this->client->get('/vector/r/customers', query: ['perPage' => 5], headers: $h)->assertOk()->json();
        self::assertIsArray($page);
        self::assertNotEmpty($page['data']);

        foreach ($page['data'] as $record) {
            self::assertArrayNotHasKey('phone', $record);
            self::assertArrayNotHasKey('email', $record);
        }

        $id = (int) $page['data'][0]['id'];
        $shown = $this->client->get("/vector/r/customers/{$id}", headers: $h)->assertOk()->json();
        self::assertIsArray($shown);
        self::assertArrayNotHasKey('phone', $shown['data']);

        $this->client->get('/vector/r/customers', query: ['filter' => ['phone' => ['null' => '']]], headers: $h)->assertStatus(422);
        $this->client->get('/vector/r/customers', query: ['sort' => 'email'], headers: $h)->assertStatus(422);
        $this->client->get('/vector/r/customers', query: ['columns' => 'id,phone'], headers: $h)->assertStatus(422);

        // search no longer reaches the hidden column: an address matches nothing
        $email = (string) $this->shop()->table('customers')->select('email')->where('id', '=', $id)->value('email');
        $found = $this->client->get('/vector/r/customers', query: ['q' => $email], headers: $h)->assertOk()->json();
        self::assertIsArray($found);
        self::assertSame(0, $found['total']);

        $this->client->patch("/vector/r/customers/{$id}", body: ['phone' => '+1 555 0100'], headers: $h)->assertStatus(422);

        $csv = $this->client->get('/vector/r/customers/export', query: ['format' => 'csv'], headers: $h)->assertOk()->body();
        $header = strtok(ltrim($csv, "\u{FEFF}"), "\r\n");
        self::assertIsString($header);
        self::assertStringNotContainsStringIgnoringCase('phone', $header);
        self::assertStringNotContainsStringIgnoringCase('email', $header);
    }

    public function testTheAuditTrailKeepsOnlyVisibleFields(): void
    {
        $id = (int) $this->shop()->table('customers')->select('id')->orderBy('id', 'ASC')->value('id');
        $auditId = $this->vectorQuery()->table('vector_audit')->insertGetId([
            'resource' => 'customers', 'record_id' => (string) $id, 'event' => 'update', 'actor_id' => '1', 'impersonator_id' => null,
            'changes' => json_encode(['phone' => ['from' => 'a', 'to' => 'b'], 'name' => ['from' => 'x', 'to' => 'y']], JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s.000000'),
        ]);

        try {
            $trail = $this->client->get("/vector/r/customers/{$id}/audit", headers: $this->adminHeaders())->assertOk()->json();
            self::assertIsArray($trail);
            $entry = $trail['data'][0];
            self::assertArrayHasKey('name', $entry['changes']);
            self::assertArrayNotHasKey('phone', $entry['changes']);
        } finally {
            $this->vectorQuery()->table('vector_audit')->where('id', '=', $auditId)->delete();
        }
    }

    public function testManyToManyWritesAskTheAttachAndDetachPolicies(): void
    {
        $h = $this->realAdminHeaders();
        $productId = (int) $this->shop()->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->value('id');
        $tags = $this->client->get("/vector/r/products/{$productId}/tags", query: ['perPage' => 50], headers: $h)->assertOk()->json();
        self::assertIsArray($tags);
        $current = array_map(static fn (array $t): int => (int) $t['id'], $tags['data']);
        $all = array_map('intval', array_column($this->shop()->table('tags')->select('id')->orderBy('id', 'ASC')->get(), 'id'));
        $extra = array_values(array_diff($all, $current))[0];

        try {
            // adding is an attach, which this policy allows
            $this->client->patch("/vector/r/products/{$productId}", body: ['tags' => [...$current, $extra]], headers: $h)->assertOk();

            // removing is a detach, which it refuses: nothing changes
            $this->client->patch("/vector/r/products/{$productId}", body: ['tags' => $current], headers: $h)->assertStatus(403);
            $after = $this->client->get("/vector/r/products/{$productId}/tags", query: ['perPage' => 50], headers: $h)->assertOk()->json();
            self::assertIsArray($after);
            self::assertContains($extra, array_map(static fn (array $t): int => (int) $t['id'], $after['data']));
        } finally {
            $this->policy->allowDetach = true;
            $this->client->patch("/vector/r/products/{$productId}", body: ['tags' => $current], headers: $h)->assertOk();
        }
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}

/** Hides two contact fields and refuses detaching a product's tags. */
final class RestrictingPolicy extends ResourcePolicy
{
    /** Lets the test undo its own attach. */
    public bool $allowDetach = false;

    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        return !in_array($field, ['phone', 'email'], true);
    }

    public function detach(CurrentUserInterface $user, array $record, string $relation, array $related): bool
    {
        return $relation !== 'tags' || $this->allowDetach;
    }
}
