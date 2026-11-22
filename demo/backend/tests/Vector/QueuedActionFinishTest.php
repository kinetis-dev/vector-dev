<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\QueryBuilder\Query;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;
use Kinetis\Vector\Registry\VectorRegistry;

/**
 * A queued action's chunks are told the job they run in, and its
 * finish() runs once after the last of them: a `download` it answers is
 * the job's file, its message the job's message; `danger` fails the job.
 * The actions are the test's own, added to products in the registry.
 */
final class QueuedActionFinishTest extends VectorTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();

        foreach ([ListsTheJob::class => 'lists-the-job', DeclinesAtTheEnd::class => 'declines-at-the-end'] as $class => $name) {
            $data['resources']['products']['actions'][$name] = [
                'name' => $name, 'class' => $class, 'label' => $name, 'icon' => null, 'confirmation' => null, 'destructive' => false,
                'standalone' => false, 'queued' => true, 'chunkSize' => 2, 'showOnIndex' => true, 'showOnDetail' => false, 'fields' => [],
            ];
        }

        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    public function testEveryChunkKnowsItsJobAndFinishLeavesTheFile(): void
    {
        $ids = $this->productIds(5);
        $queued = $this->client->post('/vector/r/products/actions/lists-the-job', body: ['ids' => $ids], headers: $this->realAdminHeaders())
            ->assertStatus(202)->json();
        self::assertIsArray($queued);
        $jobId = $queued['jobId'];

        $job = $this->client->get("/vector/jobs/{$jobId}", headers: $this->realAdminHeaders())->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.hasFile', true)
            ->assertJsonPath('data.fileName', 'job.txt')
            ->assertJsonPath('data.result.message', "Finished job {$jobId}.")
            ->assertJsonPath('data.result.fileName', 'job.txt')
            ->assertJsonPath('data.processed', 5)
            ->json();
        self::assertIsArray($job);

        // three chunks of at most two, each told its job (or the job fails); finish() once, with no records
        $file = $this->client->get("/vector/jobs/{$jobId}/download", headers: $this->realAdminHeaders())->assertOk()->body();
        self::assertSame("finish {$jobId}: 0\n", $file);
    }

    public function testDangerFromFinishFailsTheJob(): void
    {
        // the sync queue runs the job inside the request, so its failure is this request's too
        $this->client->post('/vector/r/products/actions/declines-at-the-end', body: ['ids' => $this->productIds(1)], headers: $this->realAdminHeaders())
            ->assertStatus(422);
        $jobId = $this->vectorQuery()->table('vector_jobs')->where('name', '=', 'declines-at-the-end')->orderBy('id', 'DESC')->value('id');

        $this->client->get("/vector/jobs/{$jobId}", headers: $this->realAdminHeaders())->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure', 'Nothing to hand over.')
            ->assertJsonPath('data.hasFile', false);
    }

    /** @return list<int> */
    private function productIds(int $count): array
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);
        $rows = (new Query($link))->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit($count)->get();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }
}

/** Writes nothing; declines a chunk that is not told its job, and hands over the job it finished as the file. */
final class ListsTheJob extends VectorAction
{
    #[\Override]
    public function queued(): bool
    {
        return true;
    }

    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        return $request->jobId === null ? ActionResponse::danger('This chunk was not told its job.') : ActionResponse::message("chunk {$request->jobId}");
    }

    #[\Override]
    public function finish(ActionRequest $request): ActionResponse
    {
        return ActionResponse::download("finish {$request->jobId}: {$request->count()}\n", 'job.txt', 'text/plain; charset=utf-8', "Finished job {$request->jobId}.");
    }
}

/** Declines in finish(). */
final class DeclinesAtTheEnd extends VectorAction
{
    #[\Override]
    public function queued(): bool
    {
        return true;
    }

    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        return ActionResponse::message('ok');
    }

    #[\Override]
    public function finish(ActionRequest $request): ActionResponse
    {
        return ActionResponse::danger('Nothing to hand over.');
    }
}
