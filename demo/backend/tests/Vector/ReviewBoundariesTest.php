<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Vector\CategoryResource;
use App\Vector\TagResource;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Validation\Constraints\Email as EmailConstraint;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Fields\Email;
use Kinetis\Vector\Fields\Url;
use Kinetis\Vector\Store\PreferenceStore;
use Kinetis\Vector\Validation\WebUrl;
use Kinetis\Vector\VectorResource;

/**
 * The boundaries a code review found open: a record reachable by id
 * when its list is not, a relation pointed at a record outside the
 * target's scope, a write whose result the scope no longer lists, the
 * impersonator lost on the way into a job, a unique violation blamed on
 * the wrong field, and a few caps.
 */
final class ReviewBoundariesTest extends VectorTestCase
{
    private ListingPolicy $policy;

    private ScopedResource $tags;

    private ScopedResource $categories;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new ListingPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
        // the request container asks for the resource class and checks for a VectorResource: a scoped stand-in answers
        $this->tags = new ScopedResource();
        $this->categories = new ScopedResource();
        $app->instance(TagResource::class, $this->tags);
        $app->instance(CategoryResource::class, $this->categories);
    }

    public function testARecordIsNotReachableByIdWhenItsListIsNot(): void
    {
        $id = $this->firstId('products');
        $this->client->get("/vector/r/products/{$id}", headers: $this->adminHeaders())->assertOk();

        $this->policy->listable = false;

        $this->client->get('/vector/r/products', headers: $this->adminHeaders())->assertStatus(403);
        $this->client->get("/vector/r/products/{$id}", headers: $this->adminHeaders())->assertStatus(403);
        $this->client->get("/vector/r/products/{$id}/audit", headers: $this->adminHeaders())->assertStatus(403);
        $this->client->get("/vector/r/products/{$id}/files/imageKey", headers: $this->adminHeaders())->assertStatus(403);
        $this->client->get("/vector/r/products/{$id}/tags", headers: $this->adminHeaders())->assertStatus(403);
        $this->client->request('PATCH', "/vector/r/products/{$id}", body: ['stock' => 1], headers: $this->adminHeaders())->assertStatus(403);
    }

    public function testARelationValueMustPointInsideTheTargetsScope(): void
    {
        [$inside, $outside] = $this->ids('tags', 2);
        [$category, $otherCategory] = $this->ids('categories', 2);
        $product = $this->firstId('products');
        $before = $this->product($product);

        $this->tags->allowed = [$inside];
        $this->categories->allowed = [$category];

        try {
            // the options a form offers and the values a write accepts agree
            $offered = $this->client->get('/vector/r/products/fields/tags/options', headers: $this->adminHeaders())->assertOk()->json();
            self::assertIsArray($offered);
            self::assertSame([$inside], array_column($offered['data'], 'id'));

            $this->client->request('PATCH', "/vector/r/products/{$product}", body: ['tags' => [$inside, $outside]], headers: $this->adminHeaders())->assertValidationError('tags');
            $this->client->request('PATCH', "/vector/r/products/{$product}", body: ['category' => $otherCategory], headers: $this->adminHeaders())->assertValidationError('category');
            // a record outside the scope answers as one that does not exist: no oracle across it
            $this->client->request('PATCH', "/vector/r/products/{$product}", body: ['category' => 999_999_999], headers: $this->adminHeaders())->assertValidationError('category');

            $this->client->request('PATCH', "/vector/r/products/{$product}", body: ['tags' => [$inside], 'category' => $category], headers: $this->adminHeaders())
                ->assertOk()
                ->assertJsonPath('data.category.id', $category)
                ->assertJsonPath('data.tags.count', 1);
        } finally {
            $this->tags->allowed = null;
            $this->categories->allowed = null;
            $this->client->request('PATCH', "/vector/r/products/{$product}", body: ['tags' => $before['tags'], 'category' => $before['category']], headers: $this->adminHeaders())->assertOk();
        }
    }

    public function testWhatAWriteProducesIsAnsweredAndAuditedWhereverTheScopePutsIt(): void
    {
        $this->tags->allowed = [$this->firstId('tags')]; // the tag about to be created is outside
        $name = 'scoped-' . bin2hex(random_bytes(3));

        try {
            $created = $this->client->post('/vector/r/tags', body: ['name' => $name, 'color' => '#123456'], headers: $this->adminHeaders())
                ->assertCreated()
                ->assertJsonPath('data.name', $name)
                ->json();
            self::assertIsArray($created);
            $id = (string) $created['data']['id'];

            $audit = $this->vectorQuery()->table('vector_audit')->where('resource', '=', 'tags')->where('record_id', '=', $id)->where('event', '=', 'create')->first();
            self::assertIsArray($audit, 'the write is audited although the scope does not list what it produced');

            // reading it back as this user is the scope's decision, as before
            $this->client->get("/vector/r/tags/{$id}", headers: $this->adminHeaders())->assertNotFound();
        } finally {
            $this->tags->allowed = null;
            (new Query($this->link()))->table('tags')->where('name', '=', $name)->delete();
        }
    }

    public function testAJobRunsAsTheImpersonatedAdminAndRemembersTheImpersonator(): void
    {
        $link = $this->link();
        $admin = (new Query($link))->table('users')->select('id')->where('email', '=', 'admin@vector.local')->first();
        self::assertIsArray($admin);
        $second = (int) (new Query($link))->table('users')->insertGetId(['email' => 'job-admin-' . bin2hex(random_bytes(3)) . '@vector.local', 'name' => 'Job Admin', 'password_hash' => 'x', 'is_admin' => 1, 'created_at' => '2026-09-25 00:00:00']);
        $adminHeaders = ['Authorization' => 'Bearer ' . $this->tokenFor((string) $admin['id'])];

        try {
            $started = $this->client->post("/vector/impersonate/{$second}", headers: $adminHeaders)->assertOk()->json();
            self::assertIsArray($started);
            $asSecond = ['Authorization' => 'Bearer ' . $started['accessToken']];

            $ids = $this->ids('products', 2);
            $queued = $this->client->post('/vector/r/products/actions/reprice-products', body: ['ids' => $ids, 'fields' => ['percent' => 0]], headers: $asSecond)
                ->assertStatus(202)
                ->json();
            self::assertIsArray($queued);
            $jobId = (int) $queued['jobId'];

            $this->client->get("/vector/jobs/{$jobId}", headers: $asSecond)
                ->assertOk()
                ->assertJsonPath('data.status', 'done')
                ->assertJsonPath('data.actorId', (string) $second)
                ->assertJsonPath('data.impersonatorId', (string) $admin['id']);

            // the worker's own log rows name the impersonator, as the request's would have
            $event = $this->vectorQuery()->table('vector_action_events')->where('action', '=', 'action:reprice-products')->orderBy('id', 'DESC')->first();
            self::assertIsArray($event);
            self::assertSame((string) $second, $event['actor_id']);
            self::assertSame((string) $admin['id'], $event['impersonator_id']);

            $this->client->delete('/vector/impersonate', headers: $asSecond)->assertOk();
        } finally {
            (new Query($link))->table('users')->where('id', '=', $second)->delete();
        }
    }

    public function testAUniqueViolationNamesTheIndexedFieldNotTheDuplicateValue(): void
    {
        $category = $this->firstId('categories');
        // a SKU that is also the name of another column: the driver's message quotes the value
        $sku = 'name';
        $body = ['name' => 'Unique test', 'status' => 'draft', 'category' => $category, 'priceCents' => 100, 'currency' => 'EUR', 'stock' => 0, 'weightKg' => 1, 'isFeatured' => false];
        (new Query($this->link()))->table('products')->where('sku', '=', $sku)->delete();

        try {
            $this->client->post('/vector/r/products', body: [...$body, 'sku' => $sku, 'slug' => 'unique-test-' . bin2hex(random_bytes(3))], headers: $this->adminHeaders())->assertCreated();
            $this->client->post('/vector/r/products', body: [...$body, 'sku' => $sku, 'slug' => 'unique-test-' . bin2hex(random_bytes(3))], headers: $this->adminHeaders())
                ->assertValidationError('sku');
        } finally {
            (new Query($this->link()))->table('products')->where('sku', '=', $sku)->delete();
        }
    }

    public function testAUrlFieldHoldsAWebAddressAndAnEmailFieldAnAddress(): void
    {
        self::assertSame(WebUrl::class, Url::make('site')->toArray()['rules'][0]['constraint']);
        self::assertSame(EmailConstraint::class, Email::make('contact')->toArray()['rules'][0]['constraint']);
        self::assertSame([[WebUrl::class, []]], array_map(static fn (array $r): array => [$r['constraint'], $r['args']], Url::make('site')->rule(WebUrl::class)->toArray()['rules']), 'a rule of the field\'s own is not doubled');

        $url = new WebUrl();
        self::assertNull($url->validate('https://kinetis.dev/docs?x=1#y'));
        self::assertNull($url->validate('http://localhost:8080'));

        foreach (['javascript:alert(1)', 'javascript://example.com/%0aalert(1)', 'data:text/html,x', 'file:///etc/passwd', 'ftp://example.com', 'example.com', 'http://', 42] as $bad) {
            self::assertNotNull($url->validate($bad), var_export($bad, true));
        }

        $this->client->request('PATCH', '/vector/r/users/' . $this->firstId('users'), body: ['email' => 'not-an-address'], headers: $this->adminHeaders())->assertValidationError('email');
    }

    public function testAWidgetWithoutRangesTakesNone(): void
    {
        $schema = $this->client->get('/vector/schema/products', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        $widget = array_values(array_filter($schema['widgets'], static fn (array $w): bool => $w['ranges'] === []))[0]['name'] ?? null;
        self::assertIsString($widget);

        $this->client->get("/vector/r/products/widgets/{$widget}", headers: $this->adminHeaders())->assertOk()->assertJsonPath('data.range', 'all');
        $this->client->get("/vector/r/products/widgets/{$widget}", query: ['range' => 'all'], headers: $this->adminHeaders())->assertOk();
        $this->client->get("/vector/r/products/widgets/{$widget}", query: ['range' => 'anything-' . bin2hex(random_bytes(2))], headers: $this->adminHeaders())->assertStatus(422);
    }

    public function testAnAccountHoldsABoundedNumberOfPreferences(): void
    {
        $many = [];

        for ($i = 0; $i <= PreferenceStore::MAX_KEYS; $i++) {
            $many["cap.{$i}"] = true;
        }

        $this->client->request('PUT', '/vector/preferences', body: $many, headers: $this->adminHeaders())->assertStatus(422);
        self::assertSame([], $this->vectorQuery()->table('vector_preferences')->where('owner_id', '=', '1')->where('key', 'LIKE', 'cap.%')->get(), 'nothing of a refused request is stored');
    }

    /** @return array{tags: list<int>, category: int|null} */
    private function product(int $id): array
    {
        $record = $this->client->get("/vector/r/products/{$id}", headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($record);
        $tags = $this->client->get("/vector/r/products/{$id}/tags", headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($tags);

        $category = $record['data']['category']['id'] ?? null;

        return ['tags' => array_values(array_map(static fn (array $t): int => (int) $t['id'], $tags['data'])), 'category' => is_int($category) ? $category : null];
    }

    private function firstId(string $table): int
    {
        return $this->ids($table, 1)[0];
    }

    /** @return list<int> */
    private function ids(string $table, int $count): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], (new Query($this->link()))->table($table)->select('id')->orderBy('id', 'ASC')->limit($count)->get());
    }

    private function link(): MysqlLink
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return $link;
    }
}

/** Everything allowed, except listing when the test switches it off. */
final class ListingPolicy extends ResourcePolicy
{
    public bool $listable = true;

    public function viewAny(CurrentUserInterface $user): bool
    {
        return $this->listable;
    }
}

/** A stand-in resource whose scope lists only the ids the test allows; null lists everything. */
final class ScopedResource extends VectorResource
{
    /** @var list<int>|null */
    public ?array $allowed = null;

    public function scope(Query $query, CurrentUserInterface $user): Query
    {
        return $this->allowed === null ? $query : $query->whereIn('id', $this->allowed);
    }
}
