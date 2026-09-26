<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueRedis\RedisQueue;
use Kinetis\QueueRedis\RedisQueueFactory;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Jobs\RunActionJob;
use Kinetis\Vector\Jobs\VectorQueue;

/**
 * Vector's jobs on the demo's Redis. The application's queue is the real
 * Redis backend backend/.env configures (QUEUE_CONNECTION=redis on the
 * named Redis connection "jobs"), not a test double, and Vector pushes
 * to it under a queue name no running worker watches, so each test reads
 * back what it pushed and removes it.
 *
 * The second test gives Vector a Redis backend of its own through
 * VECTOR_QUEUE_CONNECTION, on another Redis database, which shows the
 * connection's own REDIS_<NAME>_* keys are the ones honoured.
 */
final class VectorRedisQueueTest extends VectorTestCase
{
    private const string QUEUE = 'vector-test';

    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'VECTOR_QUEUE' => self::QUEUE];
    }

    /** No double: the application's own QueueInterface is the Redis backend. */
    protected function registerTestDoubles(AppScope $app, Config $config): void {}

    public function testTheDemoRunsVectorsJobsOnTheApplicationsRedisQueue(): void
    {
        $appQueue = $this->app->get(QueueInterface::class);
        self::assertInstanceOf(RedisQueue::class, $appQueue, 'the application queue is Redis');
        $appQueue->clear(self::QUEUE);

        $queue = $this->app->get(VectorQueue::class);
        self::assertInstanceOf(VectorQueue::class, $queue);
        self::assertNull($queue->connection, "Vector follows the application's queue");

        $this->client->post('/vector/r/products/actions/reprice-products', body: ['ids' => $this->productIds(), 'fields' => ['percent' => 0]], headers: $this->realAdminHeaders())
            ->assertStatus(202)
            ->assertJsonPath('type', 'job');
        $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->assertJsonPath('capabilities.queue', true);

        self::assertSame(1, $appQueue->size(self::QUEUE), "the job is in Redis under Vector's queue name");
        $job = $appQueue->pop(0, [self::QUEUE]);
        self::assertNotNull($job);
        self::assertSame(RunActionJob::class, $job->class);
        $appQueue->ack($job);
        self::assertSame(0, $appQueue->size(self::QUEUE));
    }

    public function testVectorBuildsItsOwnRedisBackendFromANamedConnection(): void
    {
        $app = $this->app;
        self::assertInstanceOf(AppScope::class, $app);
        $config = new Config([
            'VECTOR_QUEUE' => self::QUEUE,
            'VECTOR_QUEUE_CONNECTION' => 'vectorjobs',
            'QUEUE_VECTORJOBS_CONNECTION' => 'redis',
            'REDIS_VECTORJOBS_HOST' => 'redis',
            'REDIS_VECTORJOBS_DATABASE' => '1',
        ]);

        $queue = VectorQueue::fromConfig($app, $config);
        self::assertNull($queue->unavailableReason());
        self::assertSame('redis', $queue->type());

        $own = RedisQueueFactory::fromConfig($config, 'vectorjobs');
        $appQueue = $this->app->get(QueueInterface::class);
        self::assertInstanceOf(RedisQueue::class, $appQueue);

        try {
            $own->clear(self::QUEUE);
            $appQueue->clear(self::QUEUE);

            $queue->push(new RunActionJob(0, 'products', 'reprice-products', [], [], '1'));

            self::assertSame(1, $own->size(self::QUEUE), 'the job is on Redis database 1, where REDIS_VECTORJOBS_DATABASE points');
            self::assertSame(0, $appQueue->size(self::QUEUE), "nothing reached the application's queue on database 0");
        } finally {
            $own->clear(self::QUEUE);
            $own->dispose();
        }
    }

    /** @return list<int> */
    private function productIds(): array
    {
        $mysql = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $mysql);

        return array_map(static fn (array $r): int => (int) $r['id'], (new Query($mysql))->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(2)->get());
    }
}
