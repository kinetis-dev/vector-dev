<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Orm\EntityManager;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Conditions;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Badge;
use Kinetis\Vector\Testing\InteractsWithVector;

/**
 * `or` and `and` groups in the list grammar: `filter[or][i]` holds
 * alternatives, any one of which may hold, and `filter[and][i]` combines
 * several filter objects — on both dialects, everywhere a filter goes.
 */
final class FilterGroupsTest extends VectorTestCase
{
    use InteractsWithVector;

    private ?EntityManager $supportManager = null;

    public function testAlternativesAreOrdAndGroupsAnded(): void
    {
        $products = $this->vector($this->adminHeaders())->resource('products');

        // archived, or low on stock
        $either = ['or' => [['status' => ['eq' => 'archived']], ['stock' => ['lte' => '5']]]];
        $expected = $this->shop()->table('products')->where('deleted_at', '=', null)
            ->whereGroup(static fn (Conditions $c) => $c->where('status', '=', 'archived')->orWhere('stock', '<=', 5))->count();
        $products->list(filter: $either, perPage: 100)->assertTotal($expected)
            ->assertEveryRecord(static fn (array $p): bool => $p['status'] === 'archived' || $p['stock'] <= 5);

        // (archived or low stock) and (featured or priced over 500)
        $both = ['and' => [$either, ['or' => [['isFeatured' => ['eq' => 'true']], ['priceCents' => ['gt' => '50000']]]]]];
        $expected = $this->shop()->table('products')->where('deleted_at', '=', null)
            ->whereGroup(static fn (Conditions $c) => $c->where('status', '=', 'archived')->orWhere('stock', '<=', 5))
            ->whereGroup(static fn (Conditions $c) => $c->where('is_featured', '=', true)->orWhere('price_cents', '>', 50000))->count();
        $products->list(filter: $both, perPage: 100)->assertTotal($expected)
            ->assertEveryRecord(static fn (array $p): bool => ($p['status'] === 'archived' || $p['stock'] <= 5) && ($p['isFeatured'] || $p['priceCents'] > 50000));

        // a condition beside a group must hold as well; an alternative may itself hold a group
        $nested = ['status' => ['ne' => 'draft'], 'or' => [['stock' => ['lte' => '5']], ['or' => [['isFeatured' => ['eq' => 'true']], ['priceCents' => ['gt' => '90000']]]]]];
        $products->list(filter: $nested, perPage: 100)
            ->assertEveryRecord(static fn (array $p): bool => $p['status'] !== 'draft' && ($p['stock'] <= 5 || $p['isFeatured'] || $p['priceCents'] > 90000));
    }

    public function testGroupsWorkOnPostgresqlToo(): void
    {
        $filter = ['or' => [['status' => ['eq' => 'open']], ['priority' => ['eq' => 'urgent']]]];
        $expected = $this->support()->table('tickets')->where('deleted_at', '=', null)
            ->whereGroup(static fn (Conditions $c) => $c->where('status', '=', 'open')->orWhere('priority', '=', 'urgent'))->count();

        $this->vector($this->adminHeaders())->resource('tickets')->list(filter: $filter, perPage: 100)->assertTotal($expected);
    }

    public function testTheGrammarIsBounded(): void
    {
        $h = $this->adminHeaders();
        $list = fn (array $filter) => $this->client->get('/vector/r/products', query: ['filter' => $filter], headers: $h);

        $deep = ['stock' => ['gt' => '0']];
        for ($i = 0; $i < 4; $i++) {
            $deep = ['or' => [$deep, ['stock' => ['lt' => '0']]]];
        }
        $list($deep)->assertStatus(422);
        $list(['or' => [['trashed' => 'with']]])->assertStatus(422);
        $list(['or' => ['x']])->assertStatus(422);
        $list(['or' => array_map(static fn (int $i): array => ['stock' => ['gt' => (string) $i], 'priceCents' => ['gt' => '0'], 'weightKg' => ['gt' => '0']], range(1, 17))])
            ->assertStatus(422);
        $list(['or' => [['nothing' => ['eq' => 'x']], ['stock' => ['gt' => '0']]]])->assertStatus(422);
    }

    public function testASavedViewKeepsItsGroups(): void
    {
        $h = $this->adminHeaders();
        $filter = ['or' => [['status' => ['eq' => 'archived']], ['stock' => ['lte' => '5']]]];
        $view = $this->client->post('/vector/views/products', body: ['name' => 'Either ' . bin2hex(random_bytes(2)), 'state' => ['filter' => $filter]], headers: $h)->assertStatus(201)->json();
        self::assertIsArray($view);
        self::assertSame($filter, $view['data']['state']['filter']);
        $this->client->delete("/vector/views/products/{$view['data']['id']}", headers: $h)->assertStatus(204);

        $this->client->post('/vector/views/products', body: ['name' => 'Bad', 'state' => ['filter' => ['or' => [['nothing' => ['eq' => '1']]]]]], headers: $h)->assertStatus(422);
        // an empty group only a JSON body can carry
        $this->client->post('/vector/views/products', body: ['name' => 'Empty', 'state' => ['filter' => ['or' => []]]], headers: $h)->assertStatus(422);

        // a badge's filter keeps its groups, values as text
        self::assertSame(
            ['or' => [['status' => ['eq' => 'open']], ['stock' => ['lte' => '5']]]],
            Badge::count(['or' => [['status' => 'open'], ['stock' => ['lte' => 5]]]])->toArray()['filter'],
        );
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
