<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Jobs\RunActionJob;
use Kinetis\Vector\Store\JobStore;

/**
 * The boundaries a code review found open: a relation's choice list
 * follows the target's own rules, an export is authorized before it is
 * queued, a job runs once, a force delete on a hard-deleting resource
 * deletes, and nothing the admin API answers is kept by a cache. The
 * default policy is replaced by one that refuses exports; the
 * application's queue records pushes and runs nothing.
 */
final class AccessBoundariesTest extends VectorTestCase
{
    private RecordingQueue $queue;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        $this->queue = new RecordingQueue();
        $app->instance(QueueInterface::class, $this->queue);
        $app->instance(AllowAllPolicy::class, new NoExportPolicy());
    }

    public function testEveryAnswerOfTheAdminApiIsMarkedNoStore(): void
    {
        $this->client->get('/vector/me', headers: $this->adminHeaders())->assertOk()->assertHeader('Cache-Control', 'no-store');
        $this->client->get('/vector/r/tags', query: ['perPage' => 1], headers: $this->adminHeaders())->assertOk()->assertHeader('Cache-Control', 'no-store');
        $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->assertHeader('Cache-Control', 'private, no-cache');
        $this->client->get('/vector/schema', headers: $this->editorHeaders())->assertStatus(403)->assertHeader('Cache-Control', 'no-store');
    }

    public function testAnOptionListOffersNoTrashedRecordButStillLabelsOne(): void
    {
        $page = $this->client->get('/vector/r/products', query: ['perPage' => 1, 'filter' => ['status' => 'active']], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($page);
        $product = $page['data'][0];
        $id = (int) $product['id'];

        $offered = static fn (array $response): array => array_map(intval(...), array_column($response['data'], 'id'));
        $before = $this->client->get('/vector/r/order-lines/fields/product/options', query: ['q' => $product['name']], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($before);
        self::assertContains($id, $offered($before));

        $this->client->delete("/vector/r/products/{$id}", headers: $this->adminHeaders())->assertStatus(204); // into the trash

        try {
            $after = $this->client->get('/vector/r/order-lines/fields/product/options', query: ['q' => $product['name']], headers: $this->adminHeaders())->assertOk()->json();
            self::assertIsArray($after);
            self::assertNotContains($id, $offered($after), 'a trashed record is not offered as a choice');

            $labelled = $this->client->get('/vector/r/order-lines/fields/product/options', query: ['ids' => (string) $id], headers: $this->adminHeaders())->assertOk()->json();
            self::assertIsArray($labelled);
            self::assertContains($id, $offered($labelled), 'a choice already stored keeps its label');
        } finally {
            $this->client->post("/vector/r/products/{$id}/restore", headers: $this->adminHeaders())->assertOk();
        }
    }

    public function testAnExportTheUserMayNotMakeQueuesNothing(): void
    {
        $jobsBefore = $this->vectorQuery()->table('vector_jobs')->count();

        $this->client->get('/vector/r/products/export', query: ['format' => 'csv'], headers: $this->adminHeaders())->assertStatus(403);

        self::assertSame($jobsBefore, $this->vectorQuery()->table('vector_jobs')->count(), 'no job row for a refused export');
        self::assertSame([], $this->queue->pushed);
    }

    public function testAVectorJobIsPushedForOneAttemptAndClaimsItsRowOnce(): void
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], (new Query($link))->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(2)->get());

        $queued = $this->client->post('/vector/r/products/actions/reprice-products', body: ['ids' => $ids, 'fields' => ['percent' => 0]], headers: $this->realAdminHeaders())
            ->assertStatus(202)
            ->assertJsonPath('type', 'job')
            ->json();
        self::assertIsArray($queued);
        self::assertCount(1, $this->queue->pushed);
        self::assertSame(RunActionJob::class, $this->queue->pushed[0]['job']);
        self::assertSame(1, $this->queue->pushed[0]['maxAttempts'], 'a failure is final, whatever the worker default');

        $jobs = $this->app->get(JobStore::class);
        self::assertInstanceOf(JobStore::class, $jobs);
        $jobId = (int) $queued['jobId'];
        self::assertTrue($jobs->start($jobId), 'the first worker claims the row');
        self::assertFalse($jobs->start($jobId), 'a redelivery finds it claimed');
        $jobs->fail($jobId, 'test');
        self::assertFalse($jobs->start($jobId), 'a failed row is never rerun');
        self::assertSame('failed', $jobs->find($jobId)['status'] ?? null);
    }

    public function testForceOnAHardDeletingResourceSimplyDeletes(): void
    {
        $tag = $this->client->post('/vector/r/tags', body: ['name' => 'force-' . bin2hex(random_bytes(3)), 'color' => '#00ff00'], headers: $this->adminHeaders())->assertCreated()->json();
        self::assertIsArray($tag);
        $id = $tag['data']['id'];

        $this->client->request('DELETE', "/vector/r/tags/{$id}?force=1", headers: $this->adminHeaders())->assertStatus(204);
        $this->client->get("/vector/r/tags/{$id}", headers: $this->adminHeaders())->assertNotFound();
    }
}

/** The default policy, except that nobody may export. */
final class NoExportPolicy extends ResourcePolicy
{
    public function export(CurrentUserInterface $user): AuthorizationResponse
    {
        return AuthorizationResponse::deny('No exports in this test.');
    }
}
