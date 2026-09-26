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
 * The relation panels' endpoints: `POST …/{id}/{relation}/attach` and
 * `…/detach` change one many-to-many relation of a record, each record
 * added or removed authorized by the policy, logged and audited.
 */
final class RelationMembershipTest extends VectorTestCase
{
    private MembershipPolicy $policy;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new MembershipPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
    }

    public function testAttachAndDetachChangeOneRelationAndAreAudited(): void
    {
        $h = $this->realAdminHeaders();
        [$productId, $current, $extra] = $this->productWithFreeTag();

        try {
            $this->client->post("/vector/r/products/{$productId}/tags/attach", body: ['ids' => [$extra]], headers: $h)
                ->assertOk()->assertJsonPath('data.tags.count', count($current) + 1);
            self::assertContains($extra, $this->tagsOf($productId));

            // attaching what is already there changes nothing
            $this->client->post("/vector/r/products/{$productId}/tags/attach", body: ['ids' => [$extra]], headers: $h)
                ->assertOk()->assertJsonPath('data.tags.count', count($current) + 1);

            $this->client->post("/vector/r/products/{$productId}/tags/detach", body: ['ids' => [$extra]], headers: $h)
                ->assertOk()->assertJsonPath('data.tags.count', count($current));
            self::assertNotContains($extra, $this->tagsOf($productId));

            $trail = $this->client->get("/vector/r/products/{$productId}/audit", headers: $h)->assertOk()->json();
            self::assertIsArray($trail);
            self::assertSame(['detach', 'attach'], array_slice(array_column($trail['data'], 'event'), 0, 2));
            self::assertContains((string) $extra, $trail['data'][1]['changes']['tags']['to']);
        } finally {
            $this->shop()->table('product_tag')->where('product_id', '=', $productId)->where('tag_id', '=', $extra)->delete();
        }
    }

    public function testThePolicyDecidesEachAttachAndDetach(): void
    {
        $h = $this->realAdminHeaders();
        [$productId, , $extra] = $this->productWithFreeTag();
        $this->policy->allowAttach = false;

        try {
            $this->client->post("/vector/r/products/{$productId}/tags/attach", body: ['ids' => [$extra]], headers: $h)->assertStatus(403);
            self::assertNotContains($extra, $this->tagsOf($productId));
        } finally {
            $this->policy->allowAttach = true;
        }
    }

    public function testOnlyAnEditableManyToManyAcceptsMembershipChanges(): void
    {
        $h = $this->realAdminHeaders();
        [$productId] = $this->productWithFreeTag();

        $this->client->post("/vector/r/products/{$productId}/category/attach", body: ['ids' => [1]], headers: $h)->assertValidationError('category');
        $this->client->post("/vector/r/products/{$productId}/tags/attach", body: ['ids' => 'x'], headers: $h)->assertValidationError('ids');
        $this->client->post("/vector/r/products/{$productId}/tags/attach", body: ['ids' => []], headers: $h)->assertValidationError('ids');
        $this->client->post("/vector/r/products/{$productId}/tags/attach", body: ['ids' => [999999]], headers: $h)->assertValidationError('ids');
        $this->client->post('/vector/r/products/999999/tags/attach', body: ['ids' => [1]], headers: $h)->assertStatus(404);
    }

    public function testAHasManyRelationNamesTheChildFieldThatPointsBack(): void
    {
        $schema = $this->client->get('/vector/schema/tickets', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        $replies = array_column($schema['fields'], null, 'name')['replies'];
        self::assertSame('ticket-replies', $replies['relation']['resource']);
        self::assertSame('ticket', $replies['relation']['inverse']);
    }

    /** @return array{0: int, 1: list<int>, 2: int} a product, its tags, and a tag it does not have */
    private function productWithFreeTag(): array
    {
        $productId = (int) $this->shop()->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->value('id');
        $current = $this->tagsOf($productId);
        $all = array_map('intval', array_column($this->shop()->table('tags')->select('id')->orderBy('id', 'ASC')->get(), 'id'));

        return [$productId, $current, array_values(array_diff($all, $current))[0]];
    }

    /** @return list<int> */
    private function tagsOf(int $productId): array
    {
        return array_map('intval', array_column($this->shop()->table('product_tag')->select('tag_id')->where('product_id', '=', $productId)->get(), 'tag_id'));
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}

/** Allows everything, except attaching when the test switches it off. */
final class MembershipPolicy extends ResourcePolicy
{
    public bool $allowAttach = true;

    public function attach(CurrentUserInterface $user, array $record, string $relation, array $related): bool
    {
        return $this->allowAttach;
    }
}
