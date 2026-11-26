<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\VectorResource;

/**
 * vector_aggregate, and vector_query's `fields`. Each test adds its own
 * customers, orders (MySQL) and tickets (PostgreSQL), numbered with a
 * stamp of its own, and aggregates them through a filter on that stamp:
 * the totals are known, whatever the seed holds. The orders sit on both
 * sides of a month's, a week's and a day's edge, and one customer has
 * an order in another currency.
 *
 * | # | customer | status    | total   | currency | placed at (UTC)            |
 * |---|----------|-----------|---------|----------|----------------------------|
 * | 1 | A        | paid      |  100.00 | EUR      | 2026-01-31 23:59:59.999999 |
 * | 2 | A        | shipped   |  150.00 | EUR      | 2026-02-01 00:00:00        |
 * | 3 | A        | paid      |  250.00 | EUR      | 2026-02-15 12:00:00        |
 * | 4 | A        | pending   | 1000.00 | USD      | 2026-03-01 00:00:00        |
 * | 5 | B        | paid      |  400.00 | EUR      | 2026-03-10 08:00:00        |
 * | 6 | B        | shipped   |  500.00 | EUR      | 2026-04-02 09:00:00        |
 * | 7 | C        | cancelled |  100.00 | EUR      | 2026-04-30 23:59:59        |
 */
final class McpAggregateTest extends VectorTestCase
{
    private AggregatePolicy $policy;

    private AggregateScopedOrders $orders;

    private string $stamp = '';

    /** @var array<string, int> A, B, C => customer id */
    private array $customers = [];

    private ?EntityManager $supportManager = null;

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new AggregatePolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
        $this->orders = new AggregateScopedOrders();
        $app->instance(AggregateScopedOrders::class, $this->orders);

        if ($this->name() === 'testAScopedResourceAggregatesOnlyTheRowsItsListShows') {
            $registry = $app->get(VectorRegistry::class);
            self::assertInstanceOf(VectorRegistry::class, $registry);
            $data = $registry->toArray();
            $data['resources']['orders']['class'] = AggregateScopedOrders::class;
            $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
        }

        if (isset(self::FORM_ONLY[$this->name()])) {
            $registry = $app->get(VectorRegistry::class);
            self::assertInstanceOf(VectorRegistry::class, $registry);
            $app->instance(VectorRegistry::class, VectorRegistry::fromArray(self::formOnly($registry->toArray(), 'orders', self::FORM_ONLY[$this->name()])));
        }
    }

    /** test => the orders field it takes off both the list and the record view, leaving it on the forms */
    private const array FORM_ONLY = [
        'testAFieldNoReadPathShowsIsRefusedAsAGroupKey' => 'placedAt',
        'testAFieldNoReadPathShowsIsRefusedAsAMinimumOrMaximum' => 'placedAt',
        'testAFieldNoReadPathShowsCannotBeSortedOn' => 'placedAt',
        'testAMoneyMetricWhoseCurrencyNoReadPathShowsIsRefused' => 'currency',
    ];

    /**
     * $data with $field of $slug on neither the list nor the record view:
     * a form-only field, still in the descriptor.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function formOnly(array $data, string $slug, string $field): array
    {
        $resource = $data['resources'][$slug];
        $drop = static fn (array $names): array => array_values(array_diff($names, [$field]));
        $resource['table_view']['columns'] = $drop($resource['table_view']['columns']);
        $resource['table_view']['extra'] = $drop($resource['table_view']['extra']);
        $resource['table_view']['defaultSort'] = array_values(array_filter($resource['table_view']['defaultSort'], static fn (array $sort): bool => $sort['field'] !== $field));
        $resource['show_view']['fields'] = $drop($resource['show_view']['fields']);
        $resource['show_view']['extra'] = $drop($resource['show_view']['extra']);

        foreach ($resource['fields'] as $i => $compiled) {
            if (is_array($compiled) && ($compiled['name'] ?? null) === $field) {
                $resource['fields'][$i]['views'] = array_values(array_diff($compiled['views'], ['index', 'show']));
            }
        }

        $data['resources'][$slug] = $resource;

        return $data;
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->stamp = 'AGG-' . bin2hex(random_bytes(3));

        foreach (['A' => 'Alpha', 'B' => 'Bravo', 'C' => 'Charlie'] as $letter => $name) {
            $id = $this->shop()->table('customers')->insertGetId([
                'email' => strtolower("{$this->stamp}-{$letter}@example.test"),
                'name' => "{$name} {$this->stamp}",
                'created_at' => '2026-01-01 00:00:00.000000',
            ]);
            $this->customers[$letter] = (int) $id;
        }

        $orders = [
            ['A', 'paid', 10000, 'EUR', '2026-01-31 23:59:59.999999'],
            ['A', 'shipped', 15000, 'EUR', '2026-02-01 00:00:00.000000'],
            ['A', 'paid', 25000, 'EUR', '2026-02-15 12:00:00.000000'],
            ['A', 'pending', 100000, 'USD', '2026-03-01 00:00:00.000000'],
            ['B', 'paid', 40000, 'EUR', '2026-03-10 08:00:00.000000'],
            ['B', 'shipped', 50000, 'EUR', '2026-04-02 09:00:00.000000'],
            ['C', 'cancelled', 10000, 'EUR', '2026-04-30 23:59:59.000000'],
        ];

        foreach ($orders as $i => [$customer, $status, $total, $currency, $placed]) {
            $this->shop()->table('orders')->insert([
                'number' => "{$this->stamp}-" . ($i + 1),
                'customer_id' => $this->customers[$customer],
                'status' => $status,
                'total_cents' => $total,
                'currency' => $currency,
                'placed_at' => $placed,
                'created_at' => $placed,
                'updated_at' => $placed,
            ]);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->shop()->table('orders')->where('number', 'LIKE', "{$this->stamp}-%")->delete();
        $this->shop()->table('customers')->where('email', 'LIKE', strtolower("{$this->stamp}-%"))->delete();
        $this->support()->table('tickets')->where('number', 'LIKE', "{$this->stamp}-%")->delete();
        $this->supportManager = null;
        parent::tearDown();
    }

    public function testTheTopSpendersAreTheLargestSumsPerCustomerAndCurrencyWithTheirNames(): void
    {
        $top = $this->aggregate('orders', [
            'groupBy' => [['field' => 'customer']],
            'metrics' => [['fn' => 'sum', 'field' => 'totalCents'], ['fn' => 'count']],
            'sort' => '-sum_totalCents',
            'limit' => 2,
        ]);

        // A's dollars are never added to A's euros: the currency is a group key of its own
        self::assertSame([
            ['key' => ['customer' => ['id' => $this->customers['A'], 'label' => "Alpha {$this->stamp}"], 'currency' => 'USD'], 'metrics' => ['sum_totalCents' => 100000, 'count' => 1]],
            ['key' => ['customer' => ['id' => $this->customers['B'], 'label' => "Bravo {$this->stamp}"], 'currency' => 'EUR'], 'metrics' => ['sum_totalCents' => 90000, 'count' => 2]],
        ], $top['groups']);
        self::assertSame(4, $top['groupCount']);
        self::assertTrue($top['truncated']);
        self::assertSame('UTC', $top['timezone']);
        self::assertSame(['sum_totalCents' => ['unit' => 'minor', 'currencyGroup' => 'currency']], $top['money']);

        // a filter narrows what is aggregated, as it narrows the list
        $euros = $this->aggregate('orders', [
            'filter' => ['number' => ['like' => $this->stamp], 'currency' => 'EUR'],
            'groupBy' => [['field' => 'customer']],
            'metrics' => [['fn' => 'sum', 'field' => 'totalCents']],
        ], filtered: false);
        self::assertSame(
            [[$this->customers['B'], 90000], [$this->customers['A'], 50000], [$this->customers['C'], 10000]],
            array_map(static fn (array $g): array => [$g['key']['customer']['id'], $g['metrics']['sum_totalCents']], $euros['groups']),
        );
        self::assertSame([3, false], [$euros['groupCount'], $euros['truncated']]);
    }

    public function testTheSeededShopsTopTenCustomersAreWhatTheDatabaseAddsUp(): void
    {
        // every seeded order, none of this test's
        $expected = $this->shop()->table('orders')
            ->selectRaw('customer_id, currency, SUM(total_cents) AS spent')
            ->whereRaw('number NOT LIKE ?', ["{$this->stamp}-%"])
            ->groupBy('customer_id', 'currency')
            ->orderByRaw('spent DESC, customer_id ASC, currency ASC')
            ->limit(10)
            ->get();
        $groups = $this->shop()->table('orders')->whereRaw('number NOT LIKE ?', ["{$this->stamp}-%"])->selectRaw('COUNT(DISTINCT customer_id, currency) AS n')->value('n');

        $top = $this->aggregate('orders', [
            'filter' => ['number' => ['nlike' => $this->stamp]],
            'groupBy' => [['field' => 'customer']],
            'metrics' => [['fn' => 'sum', 'field' => 'totalCents']],
            'limit' => 10,
        ], filtered: false);

        self::assertSame(
            array_map(static fn (array $row): array => [(int) $row['customer_id'], (string) $row['currency'], (int) $row['spent']], $expected),
            array_map(static fn (array $g): array => [$g['key']['customer']['id'], $g['key']['currency'], $g['metrics']['sum_totalCents']], $top['groups']),
        );
        self::assertSame((int) $groups, $top['groupCount']);
        $name = $this->shop()->table('customers')->where('id', '=', (int) $expected[0]['customer_id'])->value('name');
        self::assertSame($name, $top['groups'][0]['key']['customer']['label']);
    }

    public function testADateGroupsByTheBucketItsInstantFallsInUtc(): void
    {
        $buckets = fn (string $bucket): array => array_map(
            static fn (array $g): array => [$g['key']['placedAt'], $g['metrics']['count']],
            $this->aggregate('orders', ['groupBy' => [['field' => 'placedAt', 'bucket' => $bucket]], 'metrics' => [['fn' => 'count']], 'sort' => 'placedAt'])['groups'],
        );

        // the last microsecond of January is January's; midnight of the first is the next month's
        self::assertSame([['2026-01-01', 1], ['2026-02-01', 2], ['2026-03-01', 2], ['2026-04-01', 2]], $buckets('month'));
        self::assertSame([
            ['2026-01-31', 1], ['2026-02-01', 1], ['2026-02-15', 1], ['2026-03-01', 1], ['2026-03-10', 1], ['2026-04-02', 1], ['2026-04-30', 1],
        ], $buckets('day'));
        // weeks start on Monday: Saturday the 31st and Sunday the 1st share the week of the 26th
        self::assertSame([['2026-01-26', 2], ['2026-02-09', 1], ['2026-02-23', 1], ['2026-03-09', 1], ['2026-03-30', 1], ['2026-04-27', 1]], $buckets('week'));
        self::assertSame([['2026-01-01', 5], ['2026-04-01', 2]], $buckets('quarter'));
        self::assertSame([['2026-01-01', 7]], $buckets('year'));
    }

    public function testTwoGroupKeysGroupByBoth(): void
    {
        $groups = $this->aggregate('orders', [
            'groupBy' => [['field' => 'status'], ['field' => 'placedAt', 'bucket' => 'month']],
            'metrics' => [['fn' => 'count']],
            'sort' => 'placedAt',
        ])['groups'];

        // by month, then by status within a month
        self::assertSame([
            ['paid', '2026-01-01', 1],
            ['paid', '2026-02-01', 1],
            ['shipped', '2026-02-01', 1],
            ['paid', '2026-03-01', 1],
            ['pending', '2026-03-01', 1],
            ['cancelled', '2026-04-01', 1],
            ['shipped', '2026-04-01', 1],
        ], array_map(static fn (array $g): array => [$g['key']['status'], $g['key']['placedAt'], $g['metrics']['count']], $groups));
    }

    public function testCountAverageMinimumAndMaximumAreTheDatabasesOwn(): void
    {
        $all = $this->aggregate('orders', [
            'filter' => ['number' => ['like' => $this->stamp], 'currency' => 'EUR'],
            'metrics' => [
                ['fn' => 'count'],
                ['fn' => 'avg', 'field' => 'totalCents'],
                ['fn' => 'min', 'field' => 'totalCents'],
                ['fn' => 'max', 'field' => 'totalCents'],
                ['fn' => 'min', 'field' => 'placedAt'],
                ['fn' => 'max', 'field' => 'placedAt'],
            ],
        ], filtered: false);

        // no groupBy: one group, but the euros are still a currency of their own
        self::assertCount(1, $all['groups']);
        self::assertSame(['currency' => 'EUR'], $all['groups'][0]['key']);
        $metrics = $all['groups'][0]['metrics'];
        self::assertSame(6, $metrics['count']);
        self::assertEquals(25000.0, $metrics['avg_totalCents']);
        self::assertSame([10000, 50000], [$metrics['min_totalCents'], $metrics['max_totalCents']]);
        self::assertSame(['2026-01-31T23:59:59.999Z', '2026-04-30T23:59:59.000Z'], [$metrics['min_placedAt'], $metrics['max_placedAt']]);

        // nothing matches: a count of zero and no amount
        $none = $this->aggregate('orders', ['filter' => ['number' => ['like' => "{$this->stamp}-none"]], 'metrics' => [['fn' => 'count'], ['fn' => 'max', 'field' => 'placedAt']]], filtered: false);
        self::assertSame([['key' => [], 'metrics' => ['count' => 0, 'max_placedAt' => null]]], $none['groups']);
    }

    public function testASoftDeletingResourceAggregatesNoTrashedRecord(): void
    {
        $count = fn (): int => $this->aggregate('products', ['metrics' => [['fn' => 'count']]], filtered: false)['groups'][0]['metrics']['count'];
        $live = $this->shop()->table('products')->where('deleted_at', '=', null)->count();
        self::assertSame($live, $count());

        $id = (int) $this->shop()->table('products')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->value('id');
        $this->shop()->table('products')->where('id', '=', $id)->update(['deleted_at' => '2026-01-01 00:00:00.000000']);

        try {
            self::assertSame($live - 1, $count(), 'a product put in the trash leaves the count');
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['deleted_at' => null]);
        }
    }

    public function testAScopedResourceAggregatesOnlyTheRowsItsListShows(): void
    {
        $this->orders->customer = $this->customers['B'];

        $groups = $this->aggregate('orders', ['groupBy' => [['field' => 'customer']], 'metrics' => [['fn' => 'count']]])['groups'];

        self::assertSame([[$this->customers['B'], 2]], array_map(static fn (array $g): array => [$g['key']['customer']['id'], $g['metrics']['count']], $groups));
    }

    public function testAnAccountThatMayNotListTheResourceGetsAToolError(): void
    {
        $this->policy->denyViewAny = true;

        $result = $this->call('vector_aggregate', ['resource' => 'orders', 'metrics' => [['fn' => 'count']]]);

        self::assertTrue($result['isError'] ?? false);
        self::assertStringNotContainsString('"groups"', $result['content'][0]['text']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusedAggregates(): array
    {
        return [
            'an unknown field' => [['resource' => 'orders', 'groupBy' => [['field' => 'nope']], 'metrics' => [['fn' => 'count']]], '"nope" is not a field of orders'],
            'a computed field' => [['resource' => 'order-lines', 'metrics' => [['fn' => 'sum', 'field' => 'lineTotal']]], '"lineTotal" is computed'],
            'a computed group' => [['resource' => 'order-lines', 'groupBy' => [['field' => 'lineTotal']], 'metrics' => [['fn' => 'count']]], '"lineTotal" is computed'],
            'a rich-text field' => [['resource' => 'products', 'groupBy' => [['field' => 'description']], 'metrics' => [['fn' => 'count']]], '"description" is a rich-text field'],
            'a key-value field' => [['resource' => 'products', 'groupBy' => [['field' => 'attributes']], 'metrics' => [['fn' => 'count']]], '"attributes" is a key-value field'],
            'a multiple select' => [['resource' => 'products', 'groupBy' => [['field' => 'channels']], 'metrics' => [['fn' => 'count']]], '"channels" is a multiple select, a list of values'],
            'a repeater field' => [['resource' => 'orders', 'groupBy' => [['field' => 'lines']], 'metrics' => [['fn' => 'count']]], '"lines" is a repeater, a list of rows, which cannot be grouped or aggregated'],
            'a bucket on a choice' => [['resource' => 'orders', 'groupBy' => [['field' => 'status', 'bucket' => 'month']], 'metrics' => [['fn' => 'count']]], 'bucket applies only to'],
            'a date without a bucket' => [['resource' => 'orders', 'groupBy' => [['field' => 'placedAt']], 'metrics' => [['fn' => 'count']]], 'group it by a bucket'],
            'an unknown bucket' => [['resource' => 'orders', 'groupBy' => [['field' => 'placedAt', 'bucket' => 'hour']], 'metrics' => [['fn' => 'count']]], 'bucket'],
            'a sum of a choice' => [['resource' => 'orders', 'metrics' => [['fn' => 'sum', 'field' => 'status']]], 'sum takes a number, money field'],
            'a field for count' => [['resource' => 'orders', 'metrics' => [['fn' => 'count', 'field' => 'totalCents']]], 'count counts records and takes no field'],
            'a related currency' => [['resource' => 'order-lines', 'metrics' => [['fn' => 'sum', 'field' => 'unitPriceCents']]], 'priced in a related record\'s currency'],
            'no metric' => [['resource' => 'orders', 'metrics' => []], 'metrics must be a list of 1 to 6'],
            'four groups' => [['resource' => 'orders', 'groupBy' => [['field' => 'status'], ['field' => 'customer'], ['field' => 'currency'], ['field' => 'number']], 'metrics' => [['fn' => 'count']]], 'groupBy must be a list of 0 to 3'],
            'a sort on nothing asked' => [['resource' => 'orders', 'metrics' => [['fn' => 'count']], 'sort' => '-sum_totalCents'], 'sort must name one metric key or group field'],
            'a limit past 100' => [['resource' => 'orders', 'metrics' => [['fn' => 'count']], 'limit' => 101], 'limit'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedAggregates')]
    public function testARefusedAggregateIsAToolErrorTheCallerCanCorrectFrom(array $arguments, string $message): void
    {
        $result = $this->call('vector_aggregate', $arguments);

        self::assertTrue($result['isError'] ?? false, 'answered: ' . ($result['content'][0]['text'] ?? ''));
        self::assertStringContainsString($message, $result['content'][0]['text']);
    }

    public function testAFieldHiddenFromTheAccountCannotBeAggregatedOrProjected(): void
    {
        $this->policy->hidden = ['totalCents', 'phone'];

        $refused = [
            ['vector_aggregate', ['resource' => 'orders', 'metrics' => [['fn' => 'sum', 'field' => 'totalCents']]]],
            ['vector_aggregate', ['resource' => 'orders', 'groupBy' => [['field' => 'status']], 'metrics' => [['fn' => 'max', 'field' => 'totalCents']]]],
            ['vector_aggregate', ['resource' => 'customers', 'groupBy' => [['field' => 'phone']], 'metrics' => [['fn' => 'count']]]],
            ['vector_query', ['resource' => 'orders', 'fields' => ['number', 'totalCents']]],
        ];

        foreach ($refused as [$tool, $arguments]) {
            $result = $this->call($tool, $arguments);
            self::assertTrue($result['isError'] ?? false, "{$tool} answered: " . ($result['content'][0]['text'] ?? ''));
        }

        // what the account sees is still theirs to aggregate
        $count = $this->aggregate('orders', ['groupBy' => [['field' => 'status']], 'metrics' => [['fn' => 'count']], 'sort' => 'status']);
        self::assertSame(['cancelled', 'paid', 'pending', 'shipped'], array_column(array_column($count['groups'], 'key'), 'status'));
    }

    public function testAFieldNoReadPathShowsIsRefusedAsAGroupKey(): void
    {
        // placedAt is on the forms only: the list cannot read it, so neither can an aggregate
        $listed = $this->call('vector_query', ['resource' => 'orders', 'fields' => ['placedAt']]);
        self::assertTrue($listed['isError'] ?? false, 'vector_query answered: ' . ($listed['content'][0]['text'] ?? ''));

        $this->assertRefused(['resource' => 'orders', 'groupBy' => [['field' => 'placedAt', 'bucket' => 'day']], 'metrics' => [['fn' => 'count']]], '"placedAt" is not a listable field of orders');
    }

    public function testAFieldNoReadPathShowsIsRefusedAsAMinimumOrMaximum(): void
    {
        foreach (['min', 'max'] as $fn) {
            $this->assertRefused(['resource' => 'orders', 'metrics' => [['fn' => $fn, 'field' => 'placedAt']]], '"placedAt" is not a listable field of orders');
        }
    }

    public function testAFieldNoReadPathShowsCannotBeSortedOn(): void
    {
        // a sort names a group key or a metric, and neither may be the unread field
        $this->assertRefused(['resource' => 'orders', 'groupBy' => [['field' => 'placedAt', 'bucket' => 'year']], 'metrics' => [['fn' => 'count']], 'sort' => '-placedAt'], '"placedAt" is not a listable field of orders');
        $this->assertRefused(['resource' => 'orders', 'metrics' => [['fn' => 'count'], ['fn' => 'max', 'field' => 'placedAt']], 'sort' => '-max_placedAt'], '"placedAt" is not a listable field of orders');
    }

    public function testAMoneyMetricWhoseCurrencyNoReadPathShowsIsRefused(): void
    {
        // the implicit currency group would print the currency no list shows
        $this->assertRefused(['resource' => 'orders', 'metrics' => [['fn' => 'sum', 'field' => 'totalCents']]], '"totalCents" cannot be aggregated by this account: its currency is not available');
        $this->assertRefused(['resource' => 'orders', 'groupBy' => [['field' => 'currency']], 'metrics' => [['fn' => 'count']]], '"currency" is not a listable field of orders');

        // counting the records still works: it reads no amount
        self::assertSame(7, $this->aggregate('orders', ['metrics' => [['fn' => 'count']]])['groups'][0]['metrics']['count']);
    }

    public function testABelongsToKeyWhoseTitleIsHiddenIsLabelledByItsId(): void
    {
        $this->policy->hidden = ['name'];

        $groups = $this->aggregate('orders', ['groupBy' => [['field' => 'customer']], 'metrics' => [['fn' => 'count']], 'sort' => 'customer'])['groups'];

        self::assertSame(
            array_map(static fn (int $id): array => ['id' => $id, 'label' => (string) $id], array_values($this->customers)),
            array_column(array_column($groups, 'key'), 'customer'),
        );
    }

    public function testTrashedWithAndOnlyCountWhatTheListCounts(): void
    {
        $id = (int) $this->shop()->table('products')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->value('id');
        $this->shop()->table('products')->where('id', '=', $id)->update(['deleted_at' => '2026-01-01 00:00:00.000000']);

        try {
            foreach (['with', 'only', 'without'] as $trashed) {
                $filter = ['trashed' => $trashed];
                $count = $this->aggregate('products', ['filter' => $filter, 'metrics' => [['fn' => 'count']]])['groups'][0]['metrics']['count'];
                $list = $this->tool('vector_query', ['resource' => 'products', 'filter' => $filter, 'perPage' => 1]);
                self::assertSame($list['total'], $count, "trashed: {$trashed}");
            }

            self::assertGreaterThanOrEqual(1, $this->aggregate('products', ['filter' => ['trashed' => 'only'], 'metrics' => [['fn' => 'count']]])['groups'][0]['metrics']['count']);
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['deleted_at' => null]);
        }
    }

    public function testQNarrowsTheAggregatedRowsAsItNarrowsTheList(): void
    {
        $all = $this->aggregate('products', ['metrics' => [['fn' => 'count']]], filtered: false)['groups'][0]['metrics']['count'];
        $searched = $this->aggregate('products', ['q' => 'SKU-0001', 'metrics' => [['fn' => 'count']]], filtered: false)['groups'][0]['metrics']['count'];
        $list = $this->tool('vector_query', ['resource' => 'products', 'q' => 'SKU-0001', 'perPage' => 1]);

        self::assertSame($list['total'], $searched);
        self::assertLessThan($all, $searched);

        // on this test's orders too: one order's number
        $one = $this->aggregate('orders', ['q' => "{$this->stamp}-3", 'groupBy' => [['field' => 'status']], 'metrics' => [['fn' => 'count']]]);
        self::assertSame([['key' => ['status' => 'paid'], 'metrics' => ['count' => 1]]], $one['groups']);
    }

    public function testATruncatedAnswerCountsEveryGroup(): void
    {
        // seven status-and-month groups, three returned
        $page = $this->aggregate('orders', [
            'groupBy' => [['field' => 'status'], ['field' => 'placedAt', 'bucket' => 'month']],
            'metrics' => [['fn' => 'count']],
            'sort' => 'placedAt',
            'limit' => 3,
        ]);

        self::assertCount(3, $page['groups']);
        self::assertSame([7, true], [$page['groupCount'], $page['truncated']]);

        $whole = $this->aggregate('orders', ['groupBy' => [['field' => 'status'], ['field' => 'placedAt', 'bucket' => 'month']], 'metrics' => [['fn' => 'count']], 'limit' => 7]);
        self::assertSame([7, false], [$whole['groupCount'], $whole['truncated']]);
    }

    public function testTicketsOnPostgresBucketByQuarterAndYearAndCountTruncatedGroups(): void
    {
        $this->tickets([
            [100, 'EUR', '2025-12-31 23:59:59.999999'],
            [200, 'EUR', '2026-03-31 23:59:59.999999'],
            [300, 'EUR', '2026-04-01 00:00:00'],
            [400, 'EUR', '2026-06-30 12:00:00'],
        ]);

        $buckets = fn (string $bucket, int $limit = 25): array => $this->aggregate('tickets', [
            'groupBy' => [['field' => 'createdAt', 'bucket' => $bucket]],
            'metrics' => [['fn' => 'count']],
            'sort' => 'createdAt',
            'limit' => $limit,
        ]);
        $keys = static fn (array $answer): array => array_map(static fn (array $g): array => [$g['key']['createdAt'], $g['metrics']['count']], $answer['groups']);

        // the last microsecond of a quarter is that quarter's, midnight of the next the next one's
        self::assertSame([['2025-10-01', 1], ['2026-01-01', 1], ['2026-04-01', 2]], $keys($buckets('quarter')));
        self::assertSame([['2025-01-01', 1], ['2026-01-01', 3]], $keys($buckets('year')));

        // four months, two returned: the groups are counted on PostgreSQL too
        $months = $buckets('month', 2);
        self::assertSame([['2025-12-01', 1], ['2026-03-01', 1]], $keys($months));
        self::assertSame([4, true], [$months['groupCount'], $months['truncated']]);
    }

    public function testTicketsOnPostgresKeepTheirCurrenciesApartAndBucketInUtc(): void
    {
        $this->tickets([[1000, 'EUR', '2026-01-31 23:59:59.999999'], [2500, 'EUR', '2026-02-01 00:00:00'], [700, 'USD', '2026-02-20 10:00:00']]);

        $groups = $this->aggregate('tickets', [
            'groupBy' => [['field' => 'createdAt', 'bucket' => 'month']],
            'metrics' => [['fn' => 'sum', 'field' => 'compensationCents'], ['fn' => 'count']],
            'sort' => 'createdAt',
        ])['groups'];

        self::assertSame([
            ['key' => ['createdAt' => '2026-01-01', 'currency' => 'EUR'], 'metrics' => ['sum_compensationCents' => 1000, 'count' => 1]],
            ['key' => ['createdAt' => '2026-02-01', 'currency' => 'EUR'], 'metrics' => ['sum_compensationCents' => 2500, 'count' => 1]],
            ['key' => ['createdAt' => '2026-02-01', 'currency' => 'USD'], 'metrics' => ['sum_compensationCents' => 700, 'count' => 1]],
        ], $groups);

        $weeks = $this->aggregate('tickets', ['groupBy' => [['field' => 'createdAt', 'bucket' => 'week']], 'metrics' => [['fn' => 'count']], 'sort' => 'createdAt'])['groups'];
        self::assertSame([['2026-01-26', 2], ['2026-02-16', 1]], array_map(static fn (array $g): array => [$g['key']['createdAt'], $g['metrics']['count']], $weeks));
    }

    public function testAProjectionCarriesOnlyTheNamedFieldsAndItsAbsenceChangesNothing(): void
    {
        $filter = ['number' => ['like' => $this->stamp]];
        $projected = $this->tool('vector_query', ['resource' => 'orders', 'filter' => $filter, 'sort' => 'number', 'fields' => ['number', 'totalCents']]);

        self::assertCount(7, $projected['data']);
        foreach ($projected['data'] as $row) {
            self::assertSame(['id', 'number', 'totalCents', '_version'], array_keys($row), 'the currency a money field brings is not asked for');
        }
        self::assertSame([10000, 15000, 25000, 100000, 40000, 50000, 10000], array_column($projected['data'], 'totalCents'));

        // a computed field's sources are read to compute it, and left out
        $line = $this->tool('vector_query', ['resource' => 'order-lines', 'perPage' => 1, 'fields' => ['lineTotal']])['data'][0];
        self::assertSame(['id', 'lineTotal'], array_values(array_diff(array_keys($line), ['_version'])));

        // without fields: exactly the admin's own list of the same request
        $whole = $this->tool('vector_query', ['resource' => 'orders', 'filter' => $filter, 'sort' => 'number', 'perPage' => 5]);
        $list = $this->client->get('/vector/r/orders', query: ['filter' => $filter, 'sort' => 'number', 'perPage' => 5], headers: $this->adminHeaders())->assertOk()->json();
        self::assertSame($list, $whole);
        self::assertArrayHasKey('customer', $whole['data'][0]);

        // a field the resource does not publish is refused
        $unknown = $this->call('vector_query', ['resource' => 'orders', 'fields' => ['number', 'nope']]);
        self::assertTrue($unknown['isError'] ?? false);
        self::assertStringContainsString('"nope"', $unknown['content'][0]['text']);
    }

    /**
     * Tickets numbered with this test's stamp, in the first support team.
     *
     * @param list<array{int, string, string}> $rows compensation cents, currency, created at (UTC)
     */
    private function tickets(array $rows): void
    {
        $team = (int) $this->support()->table('support_teams')->select('id')->orderBy('id', 'ASC')->value('id');

        foreach ($rows as $i => [$cents, $currency, $at]) {
            $this->support()->table('tickets')->insert([
                'number' => "{$this->stamp}-" . ($i + 1),
                'subject' => 'Aggregate',
                'body' => '<p>x</p>',
                'status' => 'open',
                'priority' => 'normal',
                'channel' => 'email',
                'customer_email' => 'agg@example.test',
                'team_id' => $team,
                'compensation_cents' => $cents,
                'currency' => $currency,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    /** @param array<string, mixed> $arguments */
    private function assertRefused(array $arguments, string $message): void
    {
        $result = $this->call('vector_aggregate', $arguments);

        self::assertTrue($result['isError'] ?? false, 'answered: ' . ($result['content'][0]['text'] ?? ''));
        self::assertStringContainsString($message, $result['content'][0]['text']);
    }

    /**
     * vector_aggregate's answer over this test's orders (filter on its
     * stamp) unless $filtered is false or the arguments bring a filter.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function aggregate(string $resource, array $arguments, bool $filtered = true): array
    {
        if ($filtered && !isset($arguments['filter'])) {
            $arguments['filter'] = ['number' => ['like' => $this->stamp]];
        }

        return $this->tool('vector_aggregate', ['resource' => $resource, ...$arguments]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function tool(string $tool, array $arguments): array
    {
        $result = $this->call($tool, $arguments);
        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');
        $decoded = json_decode($result['content'][0]['text'], true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments): array
    {
        $headers = [...$this->adminHeaders(), 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'];
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], JSON_THROW_ON_ERROR);
        $response = $this->client->raw('POST', '/mcp', $body, $headers)->assertOk()->json();
        self::assertIsArray($response);
        self::assertArrayHasKey('result', $response, json_encode($response, JSON_THROW_ON_ERROR));

        return $response['result'];
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
}

/** Orders of one customer only, when the test names one. */
final class AggregateScopedOrders extends VectorResource
{
    public ?int $customer = null;

    #[\Override]
    public function fields(): array
    {
        return [];
    }

    #[\Override]
    public function scope(Query $query, CurrentUserInterface $user): Query
    {
        return $this->customer === null ? $query : $query->where('customer_id', '=', $this->customer);
    }
}

/** The default policy, hiding the fields a test names and refusing the list when it says so. */
final class AggregatePolicy extends ResourcePolicy
{
    /** @var list<string> */
    public array $hidden = [];

    public bool $denyViewAny = false;

    #[\Override]
    public function viewAny(CurrentUserInterface $user): bool
    {
        return !$this->denyViewAny;
    }

    #[\Override]
    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        return !in_array($field, $this->hidden, true);
    }
}
