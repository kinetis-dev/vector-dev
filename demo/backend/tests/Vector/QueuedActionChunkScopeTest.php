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
 * Each chunk of a queued action, and its finish(), runs on a request
 * scope of its own, as the admin who started the job: whatever a
 * request-scoped service remembered during one chunk is not what the
 * next one sees. The action is the test's own, added to products.
 */
final class QueuedActionChunkScopeTest extends VectorTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();
        $data['resources']['products']['actions']['remembers-a-chunk'] = [
            'name' => 'remembers-a-chunk', 'class' => RemembersAChunk::class, 'label' => 'remembers-a-chunk', 'icon' => null, 'confirmation' => null, 'destructive' => false,
            'standalone' => false, 'queued' => true, 'chunkSize' => 2, 'showOnIndex' => true, 'showOnDetail' => false, 'fields' => [],
        ];
        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    public function testACacheFilledDuringOneChunkIsNotSeenInTheNext(): void
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], (new Query($link))->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(5)->get());

        $queued = $this->client->post('/vector/r/products/actions/remembers-a-chunk', body: ['ids' => $ids], headers: $this->realAdminHeaders())
            ->assertStatus(202)->json();
        self::assertIsArray($queued);

        // three chunks of at most two and finish(): each would decline on finding an earlier one's memo
        $this->client->get("/vector/jobs/{$queued['jobId']}", headers: $this->realAdminHeaders())->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.processed', 5)
            ->assertJsonPath('data.result.message', 'finish saw no chunk\'s memo');
    }
}

/** A request-scoped memo: the container builds one per scope and hands the same one out within it. */
final class ChunkMemo
{
    public ?string $filledBy = null;
}

/** Fills the scope's memo in each chunk, and declines when it finds one an earlier chunk filled. */
final class RemembersAChunk extends VectorAction
{
    public function __construct(private readonly ChunkMemo $memo) {}

    #[\Override]
    public function queued(): bool
    {
        return true;
    }

    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        if ($this->memo->filledBy !== null) {
            return ActionResponse::danger("This chunk saw the memo {$this->memo->filledBy} filled.");
        }

        $this->memo->filledBy = 'chunk of ' . $request->count();

        return ActionResponse::message('chunk');
    }

    #[\Override]
    public function finish(ActionRequest $request): ActionResponse
    {
        return $this->memo->filledBy === null
            ? ActionResponse::message('finish saw no chunk\'s memo')
            : ActionResponse::danger("finish saw the memo {$this->memo->filledBy} filled.");
    }
}
