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
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;
use PHPUnit\Framework\AssertionFailedError;

/**
 * How a list pages. Orders are withoutCount(): an offset page carries
 * `total` and `lastPage` null and a `hasMore` read from one row past the
 * page, in one statement — no COUNT — under the same policy, scope and
 * trash predicate as a counted page. Order lines are cursorPaging(),
 * which only the admin reads: their REST pages are as every resource's.
 * Every other resource's page is as it was. Expected rows are read from
 * the seeded shop; the one product a test trashes is put back.
 */
final class ListPagingTest extends VectorTestCase
{
    use InteractsWithVector;

    private PagingPolicy $policy;

    private ScopedOrders $orders;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new PagingPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
        $this->orders = new ScopedOrders();
        $app->instance(ScopedOrders::class, $this->orders);

        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();

        if ($this->name() === 'testTheScopeRestrictsAnUncountedPage') {
            // orders gain a scope: the uncounted page reads through it
            $data['resources']['orders']['class'] = ScopedOrders::class;
        }

        if ($this->name() === 'testTheTrashStaysOutOfAnUncountedPage') {
            // products, soft-deleted, are not counted either
            $data['resources']['products']['table_view']['withoutCount'] = true;
        }

        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    public function testAnUncountedPageSaysWhetherAnotherFollowsWithoutACount(): void
    {
        [$customer, $ids] = $this->busiestCustomer();
        $k = count($ids);
        self::assertGreaterThanOrEqual(3, $k, 'a customer with a few orders');
        $page = fn (int $perPage, int $page): array => $this->page('/vector/r/orders', ['filter' => ['customer' => (string) $customer], 'perPage' => $perPage, 'page' => $page, 'columns' => 'number']);

        // exactly one page's worth: a full last page, and nothing follows
        $full = $page($k, 1);
        self::assertSame(['data', 'currentPage', 'perPage', 'total', 'lastPage', 'hasMore'], array_keys($full));
        self::assertSame([1, $k, null, null, false], [$full['currentPage'], $full['perPage'], $full['total'], $full['lastPage'], $full['hasMore']]);
        self::assertSame($ids, array_column($full['data'], 'id'));

        // one fewer per page: a full page with one after it, then a partial last page
        $first = $page($k - 1, 1);
        self::assertTrue($first['hasMore']);
        self::assertSame(array_slice($ids, 0, $k - 1), array_column($first['data'], 'id'), 'the row past the page is read, not returned');
        $last = $page($k - 1, 2);
        self::assertFalse($last['hasMore']);
        self::assertSame([2, null, null], [$last['currentPage'], $last['total'], $last['lastPage']]);
        self::assertSame([$ids[$k - 1]], array_column($last['data'], 'id'));

        // past the end
        $past = $page($k - 1, 3);
        self::assertSame([[], false], [$past['data'], $past['hasMore']]);

        // across every order, in the resource's default order (-placedAt, then the key)
        $all = $this->shop()->table('orders')->select('id')->orderBy('placed_at', 'DESC')->orderBy('id', 'ASC')->get();
        $second = $this->page('/vector/r/orders', ['perPage' => 7, 'page' => 2, 'columns' => 'number']);
        self::assertSame(array_map(intval(...), array_column(array_slice($all, 7, 7), 'id')), array_column($second['data'], 'id'));
        self::assertSame(count($all) > 14, $second['hasMore']);
    }

    public function testAnUncountedPageIsOneStatementAndItsSummariesOneMore(): void
    {
        $page = fn (array $query): int => $this->countStatements(function () use ($query): void {
            $this->client->get('/vector/r/orders', query: $query, headers: $this->adminHeaders())->assertOk();
        });

        // the page alone — a counted list of the same shape is the COUNT and the page
        self::assertSame(1, $page(['perPage' => 5, 'columns' => 'number']));
        self::assertSame(2, $this->countStatements(function (): void {
            $this->client->get('/vector/r/customers', query: ['perPage' => 5, 'columns' => 'name'], headers: $this->adminHeaders())->assertOk();
        }));

        // summaries are their own aggregate over the whole match, as on a counted list
        self::assertSame(2, $page(['perPage' => 5, 'columns' => 'number', 'summaries' => '1']));
        $summed = $this->page('/vector/r/orders', ['perPage' => 5, 'columns' => 'number', 'summaries' => '1']);
        self::assertSame(['data', 'currentPage', 'perPage', 'total', 'lastPage', 'hasMore', 'summaries'], array_keys($summed));
        self::assertSame(['totalCents' => (int) $this->shop()->table('orders')->sum('total_cents')], $summed['summaries']);
    }

    public function testACountedPageIsAsItWas(): void
    {
        $customers = $this->shop()->table('customers')->count();
        $page = $this->page('/vector/r/customers', ['perPage' => 10, 'page' => 2]);
        self::assertSame(['data', 'currentPage', 'perPage', 'total', 'lastPage'], array_keys($page));
        self::assertSame([2, 10, $customers, (int) ceil($customers / 10)], [$page['currentPage'], $page['perPage'], $page['total'], $page['lastPage']]);

        $summed = $this->page('/vector/r/products', ['perPage' => 1, 'summaries' => '1']);
        self::assertSame(['data', 'currentPage', 'perPage', 'total', 'lastPage', 'summaries'], array_keys($summed));

        // a cursorPaging resource's REST pages are every resource's: by page, counted, and by cursor
        $lines = $this->shop()->table('order_lines')->count();
        $byPage = $this->page('/vector/r/order-lines', ['perPage' => 3, 'page' => 2, 'columns' => 'quantity']);
        self::assertSame(['data', 'currentPage', 'perPage', 'total', 'lastPage'], array_keys($byPage));
        self::assertSame($lines, $byPage['total']);
        $byCursor = $this->page('/vector/r/order-lines', ['perPage' => 3, 'cursor' => '', 'columns' => 'quantity']);
        self::assertSame(['data', 'nextCursor', 'hasMore', 'perPage'], array_keys($byCursor));
        self::assertTrue($byCursor['hasMore']);
    }

    public function testThePolicyRefusesAnUncountedList(): void
    {
        $this->policy->deny = ['viewAny'];

        $this->client->get('/vector/r/orders', query: ['perPage' => 5], headers: $this->adminHeaders())->assertStatus(403);
    }

    public function testTheScopeRestrictsAnUncountedPage(): void
    {
        [$customer, $ids] = $this->busiestCustomer();
        $k = count($ids);
        $this->orders->customer = $customer;

        // the scope alone: every order of the customer and no other, and the row past the page is within it too
        $whole = $this->page('/vector/r/orders', ['perPage' => $k, 'columns' => 'number']);
        self::assertSame($ids, array_column($whole['data'], 'id'));
        self::assertFalse($whole['hasMore']);
        self::assertTrue($this->page('/vector/r/orders', ['perPage' => $k - 1, 'columns' => 'number'])['hasMore']);
    }

    public function testARelatedListOfAnUncountedTargetIsRestrictedToItsOwner(): void
    {
        [$customer, $ids] = $this->busiestCustomer();
        $k = count($ids);

        $first = $this->page("/vector/r/customers/{$customer}/orders", ['perPage' => $k - 1, 'columns' => 'number']);
        self::assertSame([null, null, true], [$first['total'], $first['lastPage'], $first['hasMore']]);
        $last = $this->page("/vector/r/customers/{$customer}/orders", ['perPage' => $k - 1, 'page' => 2, 'columns' => 'number']);
        self::assertFalse($last['hasMore']);
        self::assertEqualsCanonicalizing($ids, [...array_column($first['data'], 'id'), ...array_column($last['data'], 'id')]);
    }

    public function testTheTrashStaysOutOfAnUncountedPage(): void
    {
        $live = fn (): array => array_map(intval(...), array_column($this->shop()->table('products')->select('id')->where('deleted_at', '=', null)->get(), 'id'));
        $trashed = $live()[0];
        $this->shop()->table('products')->where('id', '=', $trashed)->update(['deleted_at' => '2026-01-01 00:00:00']);

        try {
            $page = $this->page('/vector/r/products', ['perPage' => 200, 'columns' => 'sku']);
            self::assertSame([null, false], [$page['total'], $page['hasMore']]);
            self::assertEqualsCanonicalizing($live(), array_column($page['data'], 'id'));
            self::assertNotContains($trashed, array_column($page['data'], 'id'));

            $only = $this->page('/vector/r/products', ['perPage' => 200, 'columns' => 'sku', 'filter' => ['trashed' => 'only']]);
            self::assertContains($trashed, array_column($only['data'], 'id'));
        } finally {
            $this->shop()->table('products')->where('id', '=', $trashed)->update(['deleted_at' => null]);
        }
    }

    public function testTheDescriptorPublishesHowEachListPages(): void
    {
        $resources = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->json()['resources'];
        self::assertIsArray($resources);
        $flags = static fn (string $slug): array => [$resources[$slug]['table']['withoutCount'], $resources[$slug]['table']['cursorPaging']];

        self::assertSame([true, false], $flags('orders'));
        self::assertSame([false, true], $flags('order-lines'));
        self::assertSame([false, false], $flags('customers'));

        // an agent learns that vector_query's pages of orders carry no total
        $describe = fn (string $resource): array => $this->mcpTool('vector_describe', ['resource' => $resource]);
        self::assertTrue($describe('orders')['withoutCount']);
        self::assertArrayNotHasKey('withoutCount', $describe('customers'));
        $query = $this->mcpTool('vector_query', ['resource' => 'orders', 'perPage' => 2]);
        self::assertSame([null, null, true], [$query['total'], $query['lastPage'], $query['hasMore']]);
    }

    public function testTheTestingKitAssertsWhetherAnotherPageFollows(): void
    {
        [$customer, $ids] = $this->busiestCustomer();
        $k = count($ids);
        $orders = $this->vector($this->adminHeaders())->resource('orders');
        $mine = ['customer' => (string) $customer];

        $orders->list(filter: $mine, perPage: $k - 1)->assertOk()->assertHasMore();
        $orders->list(filter: $mine, perPage: $k - 1, page: 2)->assertOk()->assertHasMore(false);
        $orders->list(filter: $mine, perPage: $k)->assertOk()->assertHasMore(false);

        // an uncounted page has no total to assert, and says so rather than comparing with null
        try {
            $orders->list(filter: $mine, perPage: $k)->assertTotal($k);
            self::fail('assertTotal() passed on a page that is not counted');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('The list is not counted', $failure->getMessage());
        }

        // a counted page's total is asserted as before
        $this->vector($this->adminHeaders())->resource('customers')->list(perPage: 1)->assertTotal($this->shop()->table('customers')->count());

        // a cursor page carries hasMore too
        $lines = $this->vector($this->adminHeaders())->resource('order-lines');
        $count = $this->shop()->table('order_lines')->count();
        $lines->list(perPage: 2, extra: ['cursor' => '', 'columns' => 'quantity'])->assertOk()->assertHasMore();
        self::assertGreaterThan(2, $count);
        $order = (int) $this->shop()->table('order_lines')->select('order_id')->limit(1)->get()[0]['order_id'];
        $lines->list(filter: ['order' => (string) $order], perPage: 100, extra: ['cursor' => '', 'columns' => 'quantity'])->assertOk()->assertHasMore(false);
    }

    public function testTheOpenApiDocumentDescribesAnUncountedPage(): void
    {
        $document = $this->client->get('/vector/openapi.json', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($document);
        $offset = static fn (string $slug): array => $document['paths']["/vector/r/{$slug}"]['get']['responses']['200']['content']['application/json']['schema']['oneOf'][0];

        $orders = $offset('orders');
        self::assertSame(['type' => 'null'], $orders['properties']['total']);
        self::assertSame(['type' => 'null'], $orders['properties']['lastPage']);
        self::assertSame(['type' => 'boolean'], $orders['properties']['hasMore']);
        self::assertSame(['data', 'currentPage', 'perPage', 'total', 'lastPage', 'hasMore'], $orders['required']);

        // a collection's page under /{id}/{relation} is not in the document, so no relation path describes a page at all
        self::assertArrayNotHasKey('/vector/r/customers/{id}/orders', $document['paths']);
        self::assertArrayNotHasKey('/vector/r/customers/{id}/{relation}', $document['paths']);

        $customers = $offset('customers');
        self::assertSame(['type' => 'integer'], $customers['properties']['total']);
        self::assertArrayNotHasKey('hasMore', $customers['properties']);
        self::assertSame(['data', 'currentPage', 'perPage', 'total', 'lastPage'], $customers['required']);
    }

    /**
     * The customer with the most orders, and those orders' ids in the
     * resource's default order.
     *
     * @return array{0: int, 1: list<int>}
     */
    private function busiestCustomer(): array
    {
        $counts = array_count_values(array_map(intval(...), array_column($this->shop()->table('orders')->select('customer_id')->get(), 'customer_id')));
        arsort($counts);
        $customer = (int) array_key_first($counts);
        $ids = array_map(intval(...), array_column($this->shop()->table('orders')->select('id')->where('customer_id', '=', $customer)->orderBy('placed_at', 'DESC')->orderBy('id', 'ASC')->get(), 'id'));

        return [$customer, $ids];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function page(string $path, array $query): array
    {
        $json = $this->client->get($path, query: $query, headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($json);

        return $json;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function mcpTool(string $tool, array $arguments): array
    {
        $headers = [...$this->adminHeaders(), 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'];
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], JSON_THROW_ON_ERROR);
        $response = $this->client->raw('POST', '/mcp', $body, $headers)->assertOk()->json();
        self::assertIsArray($response);
        $result = $response['result'] ?? self::fail(json_encode($response, JSON_THROW_ON_ERROR));
        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');
        $decoded = json_decode($result['content'][0]['text'], true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}

/** Orders of one customer only, when a test names one. */
final class ScopedOrders extends VectorResource
{
    public ?int $customer = null;

    public function fields(): array
    {
        return [];
    }

    public function scope(Query $query, CurrentUserInterface $user): Query
    {
        return $this->customer === null ? $query : $query->where('customer_id', '=', $this->customer);
    }
}

/** The default policy, refusing the abilities a test names. */
final class PagingPolicy extends ResourcePolicy
{
    /** @var list<string> */
    public array $deny = [];

    public function viewAny(CurrentUserInterface $user): bool
    {
        return !in_array('viewAny', $this->deny, true);
    }
}
