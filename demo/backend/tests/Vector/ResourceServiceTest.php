<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\DemoActor;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Exception\InvalidListRequestException;
use Kinetis\Vector\Query\ListRequest;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\VectorResource;
use Kinetis\Vector\Write\ValueConverter;

/**
 * The read side of the admin API, asserted value for value against the
 * seeded shop: relation display values, the filter operators, summaries,
 * the trash, per-record permissions, policy refusals, global search and
 * exports, and the service's own limits called in-process. Expected
 * rows, counts and totals are read from the databases the test runs on;
 * the products a test trashes and restores are put back as they were,
 * and the history entries their restore recorded are removed. A queued
 * export leaves its job, as every export does.
 */
final class ResourceServiceTest extends VectorTestCase
{
    private AdjustablePolicy $policy;

    private ?EntityManager $supportManager = null;

    private ScopedCategories $categories;

    private CategoriesPolicy $categoriesPolicy;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new AdjustablePolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);

        if ($this->name() === 'testARelatedTitleMatchesExactlyWhenTheListShowsIt') {
            // categories gain a scope and a policy of their own; order lines search their product
            $this->categories = new ScopedCategories();
            $this->categoriesPolicy = new CategoriesPolicy();
            $app->instance(ScopedCategories::class, $this->categories);
            $app->instance(CategoriesPolicy::class, $this->categoriesPolicy);
            $registry = $app->get(VectorRegistry::class);
            self::assertInstanceOf(VectorRegistry::class, $registry);
            $data = $registry->toArray();
            $data['resources']['categories']['class'] = ScopedCategories::class;
            $data['resources']['categories']['policy'] = CategoriesPolicy::class;
            $data['resources']['order-lines']['searchRelations'] = ['product' => ['column' => 'product_id', 'resource' => 'products']];
            $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
        }
    }

    public function testAPageCarriesEachRelationsDisplayValue(): void
    {
        $h = $this->adminHeaders();

        // belongsTo labels and manyToMany counts, after a field that is no relation
        $page = $this->json('/vector/r/products', ['perPage' => 12, 'sort' => 'id', 'columns' => 'name,category,tags'], $h);
        $products = $this->shop()->table('products')->select('id', 'name', 'category_id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(12)->get();
        self::assertGreaterThan(1, count(array_unique(array_column($products, 'category_id'))), 'the page spans categories');
        $categories = array_column($this->shop()->table('categories')->select('id', 'name')->get(), 'name', 'id');
        $tags = $this->counts('product_tag', 'product_id', array_column($products, 'id'));
        self::assertSame(array_map(static fn (array $p): array => [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'category' => ['id' => (int) $p['category_id'], 'label' => (string) $categories[$p['category_id']]],
            'tags' => ['count' => $tags[$p['id']] ?? 0],
        ], $products), array_map(static fn (array $r): array => array_intersect_key($r, array_flip(['id', 'name', 'category', 'tags'])), $page['data']));
        self::assertSame(['data', 'currentPage', 'perPage', 'total', 'lastPage'], array_keys($page));
        $total = $this->shop()->table('products')->where('deleted_at', '=', null)->count();
        self::assertSame([1, 12, $total, (int) ceil($total / 12)], [$page['currentPage'], $page['perPage'], $page['total'], $page['lastPage']]);

        // hasOne as the related record's id and title, hasMany as a count
        $page = $this->json('/vector/r/customers', ['perPage' => 10, 'sort' => 'id', 'columns' => 'name,profile,orders'], $h);
        $customers = array_map(static fn (array $c): int => (int) $c['id'], $this->shop()->table('customers')->select('id')->orderBy('id', 'ASC')->limit(10)->get());
        $profiles = [];

        foreach ($this->shop()->table('customer_profiles')->select('id', 'customer_id', 'city')->whereIn('customer_id', $customers)->get() as $profile) {
            $profiles[(int) $profile['customer_id']] = ['id' => (int) $profile['id'], 'label' => (string) $profile['city']];
        }

        self::assertGreaterThan(1, count($profiles));
        $orders = $this->counts('orders', 'customer_id', $customers);
        self::assertSame(array_map(static fn (int $id): array => [
            'id' => $id,
            'profile' => $profiles[$id] ?? null,
            'orders' => ['count' => $orders[$id] ?? 0],
        ], $customers), array_map(static fn (array $r): array => ['id' => $r['id'], 'profile' => $r['profile'], 'orders' => $r['orders']], $page['data']));

        // a computed column brings its sources, and a related currency brings its relation with the currency it carries
        $page = $this->json('/vector/r/order-lines', ['perPage' => 3, 'sort' => 'id', 'columns' => 'lineTotal'], $h);
        $lines = $this->shop()->table('order_lines')->select('id', 'order_id', 'quantity', 'unit_price_cents')->orderBy('id', 'ASC')->limit(3)->get();
        $placed = [];

        foreach ($this->shop()->table('orders')->select('id', 'number', 'currency')->whereIn('id', array_column($lines, 'order_id'))->get() as $order) {
            $placed[(int) $order['id']] = ['id' => (int) $order['id'], 'label' => (string) $order['number'], 'currency' => (string) $order['currency']];
        }

        self::assertSame(array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'lineTotal' => (int) $l['quantity'] * (int) $l['unit_price_cents'],
            'quantity' => (int) $l['quantity'],
            'unitPriceCents' => (int) $l['unit_price_cents'],
            'order' => $placed[(int) $l['order_id']],
        ], $lines), $page['data']);

        $page = $this->json('/vector/r/products', ['perPage' => 1, 'sort' => 'id', 'columns' => 'priceCents'], $h);
        self::assertSame(['id', 'priceCents', 'currency', '_trashed', '_version'], array_keys($page['data'][0]), 'a money column brings its currency');

        // the key breaks ties, so a page of an order with many ties is stable
        $page = $this->json('/vector/r/products', ['perPage' => 10, 'page' => 3, 'sort' => '-status', 'columns' => 'status'], $h);
        $expected = $this->shop()->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('status', 'DESC')->orderBy('id', 'ASC')->limit(10)->offset(20)->get();
        self::assertSame(array_map(static fn (array $p): int => (int) $p['id'], $expected), array_column($page['data'], 'id'));

        // a cursor page is the same records under the cursor envelope
        $first = $this->json('/vector/r/products', ['perPage' => 2, 'cursor' => '', 'columns' => 'category'], $h);
        self::assertSame(['data', 'nextCursor', 'hasMore', 'perPage'], array_keys($first));
        self::assertSame([(int) $products[0]['id'], (int) $products[1]['id']], array_column($first['data'], 'id'));
        self::assertSame(['id' => (int) $products[0]['category_id'], 'label' => (string) $categories[$products[0]['category_id']]], $first['data'][0]['category']);
        self::assertSame([(string) $products[1]['id'], true, 2], [$first['nextCursor'], $first['hasMore'], $first['perPage']]);

        // an empty page loads no relation
        $nothing = ['perPage' => 5, 'filter' => ['name' => 'No such product']];
        $plain = $this->countStatements(fn () => $this->json('/vector/r/products', [...$nothing, 'columns' => 'name'], $h));
        self::assertSame($plain, $this->countStatements(fn () => $this->json('/vector/r/products', [...$nothing, 'columns' => 'name,tags'], $h)));

        // a page whose belongsTo values are all null looks no label up
        $roots = $this->shop()->table('categories')->where('parent_id', '=', null)->count();
        self::assertGreaterThan(0, $roots);
        $top = ['perPage' => $roots, 'sort' => 'parent'];
        $plain = $this->countStatements(fn () => $this->json('/vector/r/categories', [...$top, 'columns' => 'name'], $h));
        $page = [];
        self::assertSame($plain, $this->countStatements(function () use ($top, $h, &$page): void {
            $page = $this->json('/vector/r/categories', [...$top, 'columns' => 'name,parent'], $h)['data'];
        }));
        self::assertSame(array_fill(0, $roots, null), array_column($page, 'parent'));
    }

    public function testEachFilterOperatorAndSummaryMatchesTheDatabase(): void
    {
        $h = $this->adminHeaders();
        $live = fn (): Query => $this->shop()->table('products')->where('deleted_at', '=', null);
        $day = substr((string) $live()->whereRaw('released_at IS NOT NULL')->orderBy('id', 'ASC')->value('released_at'), 0, 10);

        $cases = [
            [['status' => ['ne' => 'draft']], $live()->where('status', '!=', 'draft')],
            [['stock' => ['lt' => '10']], $live()->where('stock', '<', 10)],
            [['stock' => ['gt' => '200']], $live()->where('stock', '>', 200)],
            [['stock' => ['between' => '10,20']], $live()->whereBetween('stock', 10, 20)],
            [['name' => ['like' => 'Headphones']], $live()->whereRaw('name LIKE ?', ['%Headphones%'])],
            [['name' => ['nlike' => 'Headphones']], $live()->whereRaw('name NOT LIKE ?', ['%Headphones%'])],
            [['releasedAt' => ['nnull' => '']], $live()->whereRaw('released_at IS NOT NULL')],
            [['releasedAt' => ['ne' => $day]], $live()->whereRaw('(released_at NOT BETWEEN ? AND ? OR released_at IS NULL)', ["{$day} 00:00:00", "{$day} 23:59:59.999999"])],
            [['or' => [['status' => 'draft'], ['stock' => ['lt' => '10']]]], $live()->whereRaw("(status = 'draft' OR stock < 10)")],
        ];

        foreach ($cases as [$filter, $query]) {
            $expected = $query->count();
            self::assertGreaterThan(0, $expected, json_encode($filter, JSON_THROW_ON_ERROR));
            self::assertSame($expected, $this->json('/vector/r/products', ['filter' => $filter, 'perPage' => 1], $h)['total'], json_encode($filter, JSON_THROW_ON_ERROR));
        }

        // a date column compares against the day itself
        $born = (string) $this->shop()->table('customers')->whereRaw('birth_date IS NOT NULL')->orderBy('id', 'ASC')->value('birth_date');
        $sameDay = $this->json('/vector/r/customers', ['filter' => ['birthDate' => $born], 'perPage' => 1], $h);
        self::assertSame($this->shop()->table('customers')->where('birth_date', '=', $born)->count(), $sameDay['total']);

        // an average keeps four decimals, a sum of whole numbers stays whole, nothing matched is null
        $sums = $live()->selectRaw('AVG(price_cents) AS price, SUM(stock) AS stock')->first();
        self::assertIsArray($sums);
        self::assertSame(
            ['priceCents' => round((float) $sums['price'], 4), 'stock' => (int) $sums['stock']],
            $this->json('/vector/r/products', ['perPage' => 1, 'summaries' => '1'], $h)['summaries'],
        );
        // PostgreSQL averages to more places than MySQL; the summary still keeps four
        $support = $this->support()->table('tickets')->where('deleted_at', '=', null)->selectRaw('SUM(compensation_cents) AS compensation, AVG(satisfaction) AS satisfaction')->first();
        self::assertIsArray($support);
        self::assertSame(
            ['compensationCents' => (int) $support['compensation'], 'satisfaction' => round((float) $support['satisfaction'], 4)],
            $this->json('/vector/r/tickets', ['perPage' => 1, 'summaries' => '1'], $h)['summaries'],
        );
        self::assertSame(
            ['priceCents' => null, 'stock' => null],
            $this->json('/vector/r/products', ['perPage' => 1, 'summaries' => '1', 'filter' => ['name' => 'No such product']], $h)['summaries'],
        );
    }

    public function testTheTrashAndEachRecordsPermissions(): void
    {
        $h = $this->adminHeaders();
        $live = $this->shop()->table('products')->select('id', 'sku', 'category_id')->where('deleted_at', '=', null)->orderBy('id', 'DESC')->limit(3)->get();
        self::assertCount(3, $live);
        [$kept, $trashed] = [$live[0], array_slice($live, 1)];
        $ids = array_map(static fn (array $p): int => (int) $p['id'], $trashed);
        // what the restore below writes: the soft-delete and update stamps, the version, and a history entry each
        $before = $this->shop()->table('products')->select('id', 'deleted_at', 'updated_at', 'version')->whereIn('id', $ids)->get();
        $audit = (int) $this->vectorQuery()->table('vector_audit')->selectRaw('MAX(id) AS id')->value('id');
        $events = (int) $this->vectorQuery()->table('vector_action_events')->selectRaw('MAX(id) AS id')->value('id');
        $this->shop()->table('products')->whereIn('id', $ids)->update(['deleted_at' => gmdate('Y-m-d H:i:s')]);

        try {
            $total = $this->shop()->table('products')->count();
            $untrashed = $this->shop()->table('products')->where('deleted_at', '=', null)->count();
            self::assertSame($untrashed, $this->json('/vector/r/products', ['perPage' => 1], $h)['total']);
            self::assertSame($total, $this->json('/vector/r/products', ['perPage' => 1, 'filter' => ['trashed' => 'with']], $h)['total']);
            $only = $this->shop()->table('products')->select('id')->whereRaw('deleted_at IS NOT NULL')->orderBy('id', 'ASC')->get();
            self::assertSame(array_map(static fn (array $p): int => (int) $p['id'], $only), array_column($this->json('/vector/r/products', ['perPage' => 50, 'sort' => 'id', 'filter' => ['trashed' => 'only']], $h)['data'], 'id'));

            $actions = ['publish-products', 'archive-products', 'adjust-stock', 'reprice-products'];
            $shown = $this->json("/vector/r/products/{$kept['id']}", [], $h);
            self::assertSame(['update' => true, 'delete' => true, 'restore' => false, 'forceDelete' => false, 'actions' => $actions], $shown['permissions']);
            $label = (string) $this->shop()->table('categories')->where('id', '=', $kept['category_id'])->value('name');
            self::assertSame(['id' => (int) $kept['category_id'], 'label' => $label], $shown['data']['category']);

            $shown = $this->json("/vector/r/products/{$ids[0]}", [], $h);
            self::assertTrue($shown['data']['_trashed']);
            self::assertSame(['update' => false, 'delete' => false, 'restore' => true, 'forceDelete' => true, 'actions' => $actions], $shown['permissions']);

            // a resource that does not soft-delete, and one whose policy refuses deleting your own account
            $customer = (int) $this->shop()->table('customers')->orderBy('id', 'ASC')->value('id');
            self::assertSame(['update' => true, 'delete' => true, 'restore' => false, 'forceDelete' => false, 'actions' => []], $this->json("/vector/r/customers/{$customer}", [], $h)['permissions']);
            self::assertSame(['update' => true, 'delete' => false, 'restore' => false, 'forceDelete' => false, 'actions' => []], $this->json("/vector/r/users/{$this->adminId()}", [], $this->realAdminHeaders())['permissions']);

            // an integer key on PostgreSQL
            $ticket = (int) $this->support()->table('tickets')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->value('id');
            self::assertSame($ticket, $this->json("/vector/r/tickets/{$ticket}", [], $h)['data']['id']);

            // search leaves the trash out
            self::assertSame([], array_values(array_filter(
                $this->json('/vector/search', ['q' => (string) $trashed[0]['sku']], $h)['results'],
                static fn (array $r): bool => $r['resource'] === 'products',
            )));

            // an identifier is the whole digit string or nothing
            $this->client->get("/vector/r/products/{$kept['id']}abc", headers: $h)->assertNotFound();
            $this->client->get("/vector/r/products/+{$kept['id']}", headers: $h)->assertNotFound();

            // "all matching" restores exactly the trashed records the filter names
            $restored = $this->client->post('/vector/r/products/batch', body: [
                'action' => 'restore',
                'all' => ['filter' => ['trashed' => 'only', 'sku' => ['in' => implode(',', array_column($trashed, 'sku'))]]],
            ], headers: $h)->assertOk()->json();
            self::assertSame(['affected' => 2, 'deleted' => 0], $restored);
            self::assertSame(0, $this->shop()->table('products')->whereIn('id', $ids)->whereRaw('deleted_at IS NOT NULL')->count());
            self::assertSame(2, $this->vectorQuery()->table('vector_audit')->where('id', '>', $audit)->where('event', '=', 'restore')->whereIn('record_id', array_map(strval(...), $ids))->count());
            self::assertSame(1, $this->vectorQuery()->table('vector_action_events')->where('id', '>', $events)->where('resource', '=', 'products')->where('action', '=', 'restore')->count());
        } finally {
            foreach ($before as $row) {
                $this->shop()->table('products')->where('id', '=', $row['id'])->update(['deleted_at' => $row['deleted_at'], 'updated_at' => $row['updated_at'], 'version' => $row['version']]);
            }

            $this->vectorQuery()->table('vector_audit')->where('id', '>', $audit)->where('resource', '=', 'products')->whereIn('record_id', array_map(strval(...), $ids))->delete();
            $this->vectorQuery()->table('vector_action_events')->where('id', '>', $events)->where('resource', '=', 'products')->where('action', '=', 'restore')->delete();
        }
    }

    public function testThePolicyDecidesEachRead(): void
    {
        $h = $this->adminHeaders();
        $id = (int) $this->shop()->table('products')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->value('id');
        $all = ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true, 'export' => true, 'setDefaultView' => true];
        self::assertSame($all, $this->json('/vector/schema/products', [], $h)['permissions']);
        self::assertSame(array_replace($all, ['create' => false]), $this->json('/vector/schema/users', [], $h)['permissions'], 'UserPolicy refuses create');
        $lowStock = $this->shop()->table('products')->where('deleted_at', '=', null)->where('status', '=', 'active')->where('stock', '<=', 10)->count();
        $badges = $this->json('/vector/badges', [], $h)['data'];
        self::assertSame(['products', 'tickets'], array_keys($badges));
        self::assertSame(['count' => $lowStock, 'color' => 'warning', 'label' => 'active products low on stock'], $badges['products']);

        $this->policy->deny = ['export'];
        self::assertSame(array_replace($all, ['export' => false]), $this->json('/vector/schema/products', [], $h)['permissions']);
        $this->client->get('/vector/r/products/export', headers: $h)->assertStatus(403);

        $this->policy->deny = ['view'];
        $this->client->get('/vector/r/products', query: ['perPage' => 1], headers: $h)->assertOk();
        $this->client->get("/vector/r/products/{$id}", headers: $h)->assertStatus(403);

        $this->policy->deny = ['viewAny'];
        $this->client->get('/vector/r/products', query: ['perPage' => 1], headers: $h)->assertStatus(403);
        $this->client->get("/vector/r/products/{$id}", headers: $h)->assertStatus(403);
        $this->client->get('/vector/r/products/export', headers: $h)->assertStatus(403);
        $this->client->post('/vector/r/products/batch', body: ['action' => 'restore', 'all' => ['filter' => ['name' => 'No such product']]], headers: $h)->assertStatus(403);

        // a hidden field leaves the descriptor, and the badge that filters on it
        $this->policy->deny = [];
        $this->policy->hidden = ['stock'];
        self::assertNotContains('stock', array_column($this->json('/vector/schema/products', [], $h)['fields'], 'name'));
        self::assertSame(['tickets'], array_keys($this->json('/vector/badges', [], $h)['data']));
    }

    public function testGlobalSearchAnswersTheFirstMatchesOfEachResource(): void
    {
        $h = $this->adminHeaders();

        // the five newest matches, in any searchable column, with the e-mail as subtitle
        $customers = $this->shop()->table('customers')->select('id', 'name', 'email')
            ->whereRaw("(name LIKE '%Ada%' OR email LIKE '%Ada%' OR phone LIKE '%Ada%' OR website LIKE '%Ada%' OR notes LIKE '%Ada%')")
            ->orderBy('id', 'DESC')->get();
        self::assertGreaterThan(5, count($customers));
        self::assertSame(array_map(static fn (array $c): array => [
            'resource' => 'customers', 'resourceLabel' => 'Customer', 'id' => (int) $c['id'], 'title' => (string) $c['name'], 'subtitle' => (string) $c['email'], 'image' => null,
        ], array_slice($customers, 0, 5)), $this->search('Ada', 'customers'));

        // a match inside the value, the term trimmed; the image field is named only for a record with an image
        $products = $this->shop()->table('products')->select('id', 'sku', 'name', 'image_key')->where('deleted_at', '=', null)
            ->whereRaw("sku LIKE '%KU-0001%'")->orderBy('id', 'DESC')->limit(5)->get();
        $plain = $this->shop()->table('products')->select('id', 'sku', 'name', 'image_key')->where('deleted_at', '=', null)
            ->whereRaw("(image_key IS NULL OR image_key = '')")->orderBy('id', 'ASC')->limit(1)->get();
        self::assertCount(5, $products);
        self::assertCount(1, $plain);
        $result = static fn (array $p): array => [
            'resource' => 'products', 'resourceLabel' => 'Product', 'id' => (int) $p['id'], 'title' => (string) $p['name'], 'subtitle' => (string) $p['sku'],
            'image' => is_string($p['image_key']) && $p['image_key'] !== '' ? 'imageKey' : null,
        ];
        self::assertSame(array_map($result, $products), $this->search('  KU-0001 ', 'products'));
        self::assertSame(array_map($result, $plain), $this->search((string) $plain[0]['sku'], 'products'));

        // a resource without a subtitle answers none
        $order = $this->shop()->table('orders')->select('id', 'number')->orderBy('id', 'DESC')->first();
        self::assertIsArray($order);
        self::assertSame([['resource' => 'orders', 'resourceLabel' => 'Order', 'id' => (int) $order['id'], 'title' => (string) $order['number'], 'subtitle' => null, 'image' => null]], $this->search((string) $order['number'], 'orders'));

        // a resource left out of global search is skipped, and the ones after it are still searched
        $found = $this->json('/vector/search', ['q' => 'admin@vector.local'], $h)['results'];
        self::assertNotContains('profiles', array_column($found, 'resource'));
        self::assertSame([['resource' => 'users', 'resourceLabel' => 'User', 'id' => $this->adminId(), 'title' => 'Vector Admin', 'subtitle' => 'admin@vector.local', 'image' => null]], $this->search('admin@vector.local', 'users'));

        // a term of up to 200 characters is searched, a longer one is not
        $searched = $this->countStatements(fn () => $this->client->get('/vector/search', query: ['q' => str_repeat('é', 200)], headers: $h)->assertOk()->assertJsonPath('results', []));
        $skipped = $this->countStatements(fn () => $this->client->get('/vector/search', query: ['q' => str_repeat('é', 201)], headers: $h)->assertOk()->assertJsonPath('results', []));
        self::assertGreaterThan($skipped, $searched);
    }

    public function testAnExportReadsEveryPageWithinItsLimit(): void
    {
        $h = $this->realAdminHeaders();

        // inline: more rows than one keyset page of 1000
        $lines = $this->shop()->table('order_lines')->count();
        self::assertGreaterThan(1000, $lines);
        $csv = $this->client->get('/vector/r/order-lines/export', query: ['columns' => 'quantity'], headers: $h)->assertOk()->body();
        $rows = array_values(array_filter(explode("\n", trim(ltrim($csv, "\u{FEFF}"))), static fn (string $row): bool => trim($row) !== ''));
        self::assertCount($lines + 1, $rows, 'a header and every line');

        // exactly the inline limit is still inline
        $skus = array_column($this->shop()->table('products')->select('sku')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(100)->get(), 'sku');
        self::assertCount(100, $skus);
        $csv = $this->client->get('/vector/r/products/export', query: ['columns' => 'sku', 'filter' => ['sku' => ['in' => implode(',', $skus)]]], headers: $h)->assertOk()->body();
        self::assertCount(101, array_values(array_filter(explode("\n", trim($csv)), static fn (string $row): bool => trim($row) !== '')));

        // above it, a job exports the whole selection under the queue's own limit
        $queued = $this->client->get('/vector/r/products/export', query: ['columns' => 'sku'], headers: $h)->assertStatus(202)->json();
        self::assertIsArray($queued);
        $products = $this->shop()->table('products')->where('deleted_at', '=', null)->count();
        $this->client->get("/vector/jobs/{$queued['jobId']}", headers: $h)->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.result.rows', $products);

        // a bulk selection above the resource's limit is refused before anything is written
        $refused = $this->client->post('/vector/r/order-lines/batch', body: ['action' => 'restore', 'all' => ['filter' => []]], headers: $h)->assertStatus(403)->json();
        self::assertSame(['error' => 'The selection matches more than 1000 records; at most 1000 can be handled at once. Narrow the filter.'], $refused);
    }

    public function testTheServiceKeepsToTheLimitsAndTotalsItIsGiven(): void
    {
        $skus = array_column($this->shop()->table('products')->select('id', 'sku')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(3)->get(), 'sku', 'id');
        self::assertCount(3, $skus);

        $this->inScope(function (RequestScope $scope) use ($skus): void {
            $service = $scope->get(ResourceService::class);
            $converter = $scope->get(ValueConverter::class);
            self::assertInstanceOf(ResourceService::class, $service);
            self::assertInstanceOf(ValueConverter::class, $converter);
            $products = $service->resource('products');
            $selection = ListRequest::fromSelection(['filter' => ['sku' => ['in' => implode(',', $skus)]]], $products, $converter);

            // a selection of exactly the caller's limit is answered, one more than it is refused
            self::assertSame(array_keys($skus), $service->matchingIds($products, $selection, 3));

            try {
                $service->matchingIds($products, $selection, 2);
                self::fail('A selection above the limit was answered.');
            } catch (ForbiddenOperationException $e) {
                self::assertSame('The selection matches more than 2 records; at most 2 can be handled at once. Narrow the filter.', $e->getMessage());
            }

            // a total the caller already counted is not counted again
            self::assertSame(array_values($skus), array_column(iterator_to_array($service->stream($products, $selection, limit: 2, total: 2), false), 'sku'));
        });

        // an uncounted resource's export counts one row past its limit, never the whole match
        $orders = $this->shop()->table('orders')->count();
        self::assertGreaterThan(3, $orders);
        $this->inScope(function (RequestScope $scope) use ($orders): void {
            $service = $scope->get(ResourceService::class);
            $converter = $scope->get(ValueConverter::class);
            self::assertInstanceOf(ResourceService::class, $service);
            self::assertInstanceOf(ValueConverter::class, $converter);
            $descriptor = $service->resource('orders');
            self::assertTrue($descriptor->withoutCount());
            $all = ListRequest::fromSelection([], $descriptor, $converter);

            self::assertSame(3, $service->exportCount($descriptor, $all, 2));
            self::assertSame($orders, $service->exportCount($descriptor, $all, $orders));
            self::assertSame($orders, $service->exportCount($descriptor, $all));
            self::assertSame($this->shop()->table('customers')->count(), $service->exportCount($service->resource('customers'), ListRequest::fromSelection([], $service->resource('customers'), $converter), 2), 'a counted resource is counted');

            try {
                iterator_to_array($service->stream($descriptor, $all, limit: 2));
                self::fail('An export above the limit was streamed.');
            } catch (InvalidListRequestException $e) {
                self::assertSame('The selection matches more than 2 records; this export serializes at most 2. Narrow the filter.', $e->getMessage());
            }
        });

        // a policy binding that is no policy is refused by name
        $this->inScope(function (RequestScope $scope): void {
            $scope->instance(AllowAllPolicy::class, new \stdClass());
            $service = $scope->get(ResourceService::class);
            self::assertInstanceOf(ResourceService::class, $service);

            try {
                $service->policy($service->registry()->resource('products'));
                self::fail('A binding that is no policy was used as one.');
            } catch (\LogicException $e) {
                self::assertSame([\LogicException::class, AllowAllPolicy::class . ' is not a ResourcePolicyInterface.'], [$e::class, $e->getMessage()]);
            }
        });
    }

    public function testSearchMatchesTheRelatedTitleAndTheFullTextIndexOnEachEngine(): void
    {
        // the shop, on MySQL: every product's name holds its category's name as a word
        $categories = array_column($this->shop()->table('categories')->select('id', 'name')->get(), 'name', 'id');
        $products = $this->shop()->table('products')->select('id', 'name', 'category_id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->get();
        $product = static fn (\Closure $match): array => array_values(array_map(static fn (array $p): int => (int) $p['id'], array_filter($products, $match)));
        $named = static fn (string ...$words): \Closure => static fn (array $p): bool => array_diff(array_map('strtolower', $words), (array) preg_split('/\W+/', strtolower((string) $p['name']))) === [];

        // a word only the category's title holds a match for, inside a longer word: the relation alone matches it
        $laptops = $product(static fn (array $p): bool => $categories[$p['category_id']] === 'Laptops');
        self::assertNotEmpty($laptops);
        self::assertSame($laptops, $this->ids('products', 'Laptop'));

        // the name by whole words, through its FULLTEXT index, never as part of a word as LIKE would
        $rapid = $product($named('Rapid'));
        self::assertGreaterThan(1, count($rapid));
        self::assertSame($rapid, $this->ids('products', 'rapid'));
        self::assertSame([], $this->ids('products', 'apid'));
        self::assertSame($product($named('Rapid', 'Laptops')), $this->ids('products', 'Laptops Rapid'), 'every word is required');
        self::assertNotEmpty($product($named('Rapid', 'Laptops')));
        self::assertLessThan(count($rapid), count($product($named('Rapid', 'Laptops'))));
        self::assertSame($rapid, $this->ids('products', 'Rapid the xy'), 'a stopword and a word shorter than the index keeps are not required');
        self::assertSame($rapid, $this->ids('products', '-Rapid +Rapid* "Rapid'), "the user's operators are not passed on");

        // the support desk, on PostgreSQL: no subject names its team
        $teams = array_column($this->support()->table('support_teams')->select('id', 'name')->get(), 'name', 'id');
        $tickets = $this->support()->table('tickets')->select('id', 'subject', 'team_id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->get();
        $ticket = static fn (\Closure $match): array => array_values(array_map(static fn (array $t): int => (int) $t['id'], array_filter($tickets, $match)));
        $about = static fn (string ...$words): \Closure => static fn (array $t): bool => array_diff($words, (array) preg_split('/\W+/', strtolower((string) $t['subject']))) === [];

        $billing = $ticket(static fn (array $t): bool => $t['team_id'] !== null && $teams[$t['team_id']] === 'Billing');
        self::assertNotEmpty($billing);
        self::assertSame($billing, $this->ids('tickets', 'billing'));

        $damaged = $ticket($about('damaged'));
        self::assertNotEmpty($damaged);
        self::assertSame($damaged, $this->ids('tickets', 'Damaged'));
        self::assertSame([], $this->ids('tickets', 'damage'));
        self::assertSame($ticket($about('arrived', 'damaged')), $this->ids('tickets', 'damaged arrived'), 'every word is required');
        self::assertSame($damaged, $this->ids('tickets', 'damaged -damaged | !damaged'), "the user's operators are not passed on");
        self::assertSame([], $this->ids('tickets', '#&!'), 'a term with no word matches no subject');

        // what this user may not see, search does not match
        $this->policy->hidden = ['name'];
        self::assertSame([], $this->ids('products', 'Laptop'), "the category's hidden title");
        self::assertSame([], $this->ids('products', 'Rapid'), 'the hidden name');
        self::assertCount(10, $this->ids('products', 'SKU-0001'));
        $this->policy->hidden = ['name', 'sku'];
        self::assertSame([], $this->ids('products', 'SKU-0001'), 'a relation whose title is hidden leaves nothing to match');
        self::assertSame([], $this->ids('products', (string) array_search('Laptops', $categories, true)), 'nor the key it fell back to');

        // the full-text index alone: a term with no word still matches nothing
        $this->policy->hidden = ['number', 'customerEmail', 'name'];
        self::assertSame($damaged, $this->ids('tickets', 'damaged'));
        self::assertSame([], $this->ids('tickets', '#&!'));
    }

    /**
     * A belongsTo shows its target's title whatever the target's scope(),
     * trash or list permission (loadRelations() reads it by key alone),
     * and search matches exactly the titles the list shows.
     */
    public function testARelatedTitleMatchesExactlyWhenTheListShowsIt(): void
    {
        $h = $this->adminHeaders();
        $categories = array_column($this->shop()->table('categories')->select('id', 'name')->get(), 'name', 'id');
        $laptops = (int) array_search('Laptops', $categories, true);
        $inLaptops = array_map(static fn (array $p): int => (int) $p['id'], $this->shop()->table('products')->select('id')
            ->where('category_id', '=', $laptops)->where('deleted_at', '=', null)->orderBy('id', 'ASC')->get());
        self::assertNotEmpty($inLaptops);
        $shown = fn (): array => array_values(array_unique(array_column(array_column(
            $this->json('/vector/r/products', ['filter' => ['category' => (string) $laptops], 'perPage' => 200, 'columns' => 'category'], $h)['data'],
            'category',
        ), 'label')));

        // (a) the categories' scope() leaves Laptops out for this user
        $this->categories->without = 'Laptops';
        self::assertNotContains('Laptops', array_column($this->json('/vector/r/categories', ['perPage' => 200, 'columns' => 'name'], $h)['data'], 'name'), 'the scope applies');
        self::assertSame(['Laptops'], $shown(), 'a product still shows its scoped-out category');
        self::assertSame($inLaptops, $this->ids('products', 'Laptop'), 'and search matches it');

        // (c) this user may list products, but not categories
        $this->categories->without = null;
        $this->categoriesPolicy->deny = ['viewAny'];
        $this->client->get('/vector/r/categories', headers: $h)->assertStatus(403);
        self::assertSame(['Laptops'], $shown(), 'a product still shows a category the user may not list');
        self::assertSame($inLaptops, $this->ids('products', 'Laptop'), 'and search matches it');

        // (b) an order line whose product is in the trash
        $product = (int) $this->shop()->table('order_lines')->orderBy('id', 'ASC')->value('product_id');
        $name = (string) $this->shop()->table('products')->where('id', '=', $product)->value('name');
        $named = array_map(static fn (array $p): int => (int) $p['id'], array_filter(
            $this->shop()->table('products')->select('id', 'name')->get(),
            static fn (array $p): bool => mb_stripos((string) $p['name'], $name) !== false,
        ));
        $lines = array_map(static fn (array $l): int => (int) $l['id'], $this->shop()->table('order_lines')->select('id')->whereIn('product_id', array_values($named))->orderBy('id', 'ASC')->get());
        $this->shop()->table('products')->where('id', '=', $product)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        try {
            self::assertNotContains($product, $this->ids('products', $name), 'the product list leaves it out');
            self::assertSame([$name], array_values(array_unique(array_column(array_column(
                $this->json('/vector/r/order-lines', ['filter' => ['product' => (string) $product], 'perPage' => 200, 'columns' => 'product'], $h)['data'],
                'product',
            ), 'label'))), 'a line still shows its trashed product');
            self::assertContains($lines[0] ?? null, $this->ids('order-lines', $name));
            self::assertSame($lines, $this->ids('order-lines', $name), 'and search matches it');
        } finally {
            $this->shop()->table('products')->where('id', '=', $product)->update(['deleted_at' => null]);
        }
    }

    public function testTheSearchBindsEveryTermOnEachEngine(): void
    {
        $this->inScope(function (RequestScope $scope): void {
            $service = $scope->get(ResourceService::class);
            $converter = $scope->get(ValueConverter::class);
            self::assertInstanceOf(ResourceService::class, $service);
            self::assertInstanceOf(ValueConverter::class, $converter);
            $sql = static function (string $slug, string $term) use ($service, $converter): array {
                $resource = $service->resource($slug);
                $query = null;
                $service->list($resource, ListRequest::fromQueryParams(['q' => $term], $resource, $converter), static function (Query $list) use (&$query): void {
                    $query = $list;
                });
                self::assertInstanceOf(Query::class, $query);
                $compiled = $query->toSelectSql();

                return [$compiled->sql, $compiled->params];
            };

            // é typed as e and a combining accent (NFD), and Devanagari's vowel signs and virama, stay inside their words
            $nfd = "e\u{301}te\u{301}";
            $hindi = 'हिन्दी';
            $term = "Rapid The xy Bob O'Brien rapid né Été été {$nfd} {$hindi}";
            [$products, $params] = $sql('products', $term);
            self::assertStringContainsString('(`sku` LIKE ? OR MATCH (`name`) AGAINST (? IN BOOLEAN MODE) OR `category_id` IN (SELECT `id` FROM `categories` WHERE `name` LIKE ?))', $products);
            self::assertSame(["%{$term}%", "+rapid The xy +Bob O +Brien né +été +{$nfd} +{$hindi}", "%{$term}%"], $params, 'a word is measured and folded by characters, not bytes');

            $term = "damaged O'Brien -refund";
            [$tickets, $params] = $sql('tickets', $term);
            self::assertStringContainsString(<<<'SQL'
                ("number" ILIKE ? OR "customer_email" ILIKE ? OR to_tsvector('simple', coalesce("subject", '')) @@ plainto_tsquery('simple', ?) OR "team_id" IN (SELECT "id" FROM "support_teams" WHERE "name" ILIKE ?))
                SQL, $tickets);
            self::assertSame(["%{$term}%", "%{$term}%", $term, "%{$term}%"], $params);

            foreach ([$products, $tickets] as $statement) {
                self::assertStringNotContainsString('Brien', $statement, 'the term is bound, never written into the SQL');
            }
        });
    }

    public function testEveryPathSearchesAlike(): void
    {
        $h = $this->realAdminHeaders();
        $mcp = [...$this->adminHeaders(), 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'];

        foreach ([['products', 'Laptop', 'sku'], ['products', 'Rapid', 'sku'], ['tickets', 'Billing', 'number'], ['tickets', 'damaged', 'number']] as [$slug, $q, $key]) {
            $listed = $this->json("/vector/r/{$slug}", ['q' => $q, 'perPage' => 200, 'sort' => 'id', 'columns' => $key], $h)['data'];
            $ids = array_column($listed, 'id');
            $keys = array_column($listed, $key);
            self::assertNotEmpty($ids, "{$slug} ?q={$q}");

            // cursor pages
            $paged = [];
            $cursor = '';

            do {
                $page = $this->json("/vector/r/{$slug}", ['q' => $q, 'perPage' => 7, 'cursor' => $cursor, 'columns' => $key], $h);
                $paged = [...$paged, ...array_column($page['data'], 'id')];
                $cursor = (string) $page['nextCursor'];
            } while ($page['hasMore']);

            self::assertSame($ids, $paged, "{$slug} ?q={$q}: cursor pages");

            // an inline export, and one the queue writes
            $csv = $this->client->get("/vector/r/{$slug}/export", query: ['q' => $q, 'columns' => $key], headers: $h)->assertOk()->body();
            $rows = array_map(static fn (string $row): mixed => str_getcsv($row, escape: '')[0], array_filter(explode("\n", trim(ltrim($csv, "\u{FEFF}"))), static fn (string $row): bool => trim($row) !== ''));
            self::assertSame($keys, array_slice(array_values($rows), 1), "{$slug} ?q={$q}: inline export");
            $queued = $this->client->get("/vector/r/{$slug}/export", query: ['q' => $q, 'columns' => $key, 'format' => 'xlsx'], headers: $h)->assertStatus(202)->json();
            self::assertIsArray($queued);
            $this->client->get("/vector/jobs/{$queued['jobId']}", headers: $h)->assertOk()
                ->assertJsonPath('data.status', 'done')
                ->assertJsonPath('data.result.rows', count($ids));

            // an `all` selection, as bulk operations and queued actions resolve it
            $this->inScope(static function (RequestScope $scope) use ($slug, $q, $ids): void {
                $service = $scope->get(ResourceService::class);
                $converter = $scope->get(ValueConverter::class);
                self::assertInstanceOf(ResourceService::class, $service);
                self::assertInstanceOf(ValueConverter::class, $converter);
                $resource = $service->resource($slug);
                self::assertSame($ids, $service->matchingIds($resource, ListRequest::fromSelection(['filter' => [], 'q' => $q], $resource, $converter)), "{$slug} ?q={$q}: selection");
            });

            // the MCP tool
            $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'vector_query', 'arguments' => ['resource' => $slug, 'q' => $q, 'sort' => 'id', 'perPage' => 200]]], JSON_THROW_ON_ERROR);
            $result = $this->client->raw('POST', '/mcp', $body, $mcp)->assertOk()->json();
            self::assertIsArray($result);
            self::assertFalse($result['result']['isError'] ?? false);
            self::assertSame($ids, array_column(json_decode($result['result']['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR)['data'], 'id'), "{$slug} ?q={$q}: vector_query");

            // global search: the newest matches
            self::assertSame(array_slice(array_reverse($ids), 0, 5), array_column($this->search($q, $slug), 'id'), "{$slug} ?q={$q}: global search");
        }
    }

    /** @return list<int> the ids a list of $slug searched for $q holds, in key order */
    private function ids(string $slug, string $q): array
    {
        return array_column($this->json("/vector/r/{$slug}", ['q' => $q, 'perPage' => 200, 'sort' => 'id', 'columns' => 'id'], $this->adminHeaders())['data'], 'id');
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function json(string $path, array $query, array $headers): array
    {
        $json = $this->client->get($path, query: $query, headers: $headers)->assertOk()->json();
        self::assertIsArray($json);

        return $json;
    }

    /** @return list<array<string, mixed>> the global search results of one resource */
    private function search(string $term, string $resource): array
    {
        return array_values(array_filter(
            $this->json('/vector/search', ['q' => $term], $this->adminHeaders())['results'],
            static fn (array $r): bool => $r['resource'] === $resource,
        ));
    }

    /**
     * @param list<mixed> $keys
     * @return array<int, int> key => rows of $table holding it in $column
     */
    private function counts(string $table, string $column, array $keys): array
    {
        $counts = [];

        foreach ($this->shop()->table($table)->select($column)->selectRaw('COUNT(*) AS n')->whereIn($column, $keys)->groupBy($column)->get() as $row) {
            $counts[(int) $row[$column]] = (int) $row['n'];
        }

        return $counts;
    }

    /** Runs $work in a request scope of its own, as the seeded admin. */
    private function inScope(\Closure $work): void
    {
        $scope = $this->app->createRequestScope();

        try {
            $scope->instance(CurrentUserInterface::class, new DemoActor($this->adminId(), 'Test', true));
            $work($scope);
        } finally {
            $scope->dispose();
        }
    }

    /** The seeded admin's id, as realAdminHeaders() signs in. */
    private function adminId(): int
    {
        return (int) $this->shop()->table('users')->where('email', '=', 'admin@vector.local')->value('id');
    }

    /** A builder on the support database, through a manager this test opens and closes. */
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

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}

/** The categories resource with a scope a test sets: leaving out the category of that name. */
final class ScopedCategories extends VectorResource
{
    public ?string $without = null;

    public function fields(): array
    {
        return [];
    }

    #[\Override]
    public function scope(Query $query, CurrentUserInterface $user): Query
    {
        return $this->without === null ? $query : $query->where('name', '!=', $this->without);
    }
}

/** The default policy, refusing the abilities and hiding the fields a test names. */
class AdjustablePolicy extends ResourcePolicy
{
    /** @var list<string> */
    public array $deny = [];

    /** @var list<string> */
    public array $hidden = [];

    public function viewAny(CurrentUserInterface $user): bool
    {
        return !in_array('viewAny', $this->deny, true);
    }

    public function view(CurrentUserInterface $user, array $record): bool
    {
        return !in_array('view', $this->deny, true);
    }

    public function export(CurrentUserInterface $user): bool
    {
        return !in_array('export', $this->deny, true);
    }

    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        return !in_array($field, $this->hidden, true);
    }
}

/** The categories' own policy, adjusted apart from the default one. */
final class CategoriesPolicy extends AdjustablePolicy {}
