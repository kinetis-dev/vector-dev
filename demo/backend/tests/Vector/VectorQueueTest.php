<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Jobs\RunActionJob;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Jobs\VectorQueue;

/**
 * Where Vector's jobs go. The demo runs them on the application's queue,
 * which is Redis (VectorRedisQueueTest); this class switches to a queue
 * connection of Vector's own — the SQL backend on the default database,
 * built by Vector from VECTOR_QUEUE_CONNECTION and QUEUE_CONNECTION rather
 * than taken from the container — under a queue name no running worker
 * watches, so the jobs it pushes stay in the table for it to read and
 * remove. QUEUE_CONNECTION is pinned to sql here, since the default
 * connection's type is that key. The application's own queue is a
 * recorder that runs nothing.
 */
final class VectorQueueTest extends VectorTestCase
{
    private const string QUEUE = 'vector-test';

    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'VECTOR_QUEUE' => self::QUEUE,
            'VECTOR_QUEUE_CONNECTION' => 'default',
            'QUEUE_CONNECTION' => 'sql',
            'QUEUE_CONNECTION_NAME' => 'default',
        ];
    }

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        $app->instance(QueueInterface::class, new RecordingQueue());
    }

    public function testAQueuedActionGoesToTheNamedConnectionUnderVectorsQueueName(): void
    {
        $this->clear();
        $queue = $this->app->get(VectorQueue::class);
        self::assertInstanceOf(VectorQueue::class, $queue);
        self::assertSame(self::QUEUE, $queue->queue);
        self::assertSame('default', $queue->connection);
        self::assertSame('sql', $queue->type());

        $mysql = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $mysql);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], (new Query($mysql))->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(2)->get());

        $this->client->post('/vector/r/products/actions/reprice-products', body: ['ids' => $ids, 'fields' => ['percent' => 0]], headers: $this->realAdminHeaders())
            ->assertStatus(202)
            ->assertJsonPath('type', 'job');
        $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->assertJsonPath('capabilities.queue', true);

        $rows = $this->queueTable()->table('kinetis_queue_jobs')->select('class', 'queue')->where('queue', '=', self::QUEUE)->get();
        self::assertCount(1, $rows, "the job is in the SQL backend Vector built, on Vector's queue name");
        self::assertSame(RunActionJob::class, $rows[0]['class']);

        $appQueue = $this->app->get(QueueInterface::class);
        self::assertInstanceOf(RecordingQueue::class, $appQueue);
        self::assertSame([], $appQueue->pushed, "nothing reached the application's own queue");

        $this->clear();
    }

    public function testAMisconfiguredConnectionIsRefusedWithTheMissingKey(): void
    {
        $app = $this->app;
        self::assertInstanceOf(AppScope::class, $app);

        $missing = VectorQueue::fromConfig($app, new Config(['VECTOR_QUEUE_CONNECTION' => 'nowhere']));
        self::assertSame('VECTOR_QUEUE_CONNECTION names the queue connection "nowhere", but QUEUE_NOWHERE_CONNECTION is not set.', $missing->unavailableReason());
        self::assertFalse($missing->available());

        // the backend itself is kinetis/queue's to build, and its refusal is the answer
        foreach ([
            'kafka' => 'QUEUE_ADMIN_CONNECTION must be "redis", "sql", "sqs", or "rabbitmq".',
            'sqs' => 'Cannot use QUEUE_ADMIN_CONNECTION="sqs": install "kinetis/queue-sqs" to enable it.',
        ] as $type => $message) {
            $queue = VectorQueue::fromConfig($app, new Config(['VECTOR_QUEUE_CONNECTION' => 'admin', 'QUEUE_ADMIN_CONNECTION' => $type]));
            self::assertTrue($queue->available(), 'availability reads configuration only and opens nothing');

            try {
                $queue->push(new RunActionJob(0, 'products', 'reprice-products', [], [], '1'));
                self::fail("a {$type} connection was pushed to");
            } catch (ForbiddenOperationException $e) {
                self::assertSame($message, $e->getMessage());
                self::assertSame(403, $e->httpStatus());
            }
        }

        try {
            VectorQueue::fromConfig($app, new Config(['VECTOR_QUEUE_CONNECTION' => 'Admin_Jobs']));
            self::fail('a malformed connection name was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid connection name "Admin_Jobs" from VECTOR_QUEUE_CONNECTION', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        VectorQueue::fromConfig($app, new Config(['VECTOR_QUEUE' => 'not a name']));
    }

    public function testWithoutANamedConnectionVectorUsesTheApplicationsQueueUnderItsOwnName(): void
    {
        $app = $this->app;
        self::assertInstanceOf(AppScope::class, $app);
        $queue = VectorQueue::fromConfig($app, new Config(['VECTOR_QUEUE' => self::QUEUE]));
        self::assertNull($queue->connection);
        self::assertNull($queue->unavailableReason());

        $queue->push(new RunActionJob(0, 'products', 'reprice-products', [], [], '1'));
        $appQueue = $this->app->get(QueueInterface::class);
        self::assertInstanceOf(RecordingQueue::class, $appQueue);
        self::assertSame([['job' => RunActionJob::class, 'queue' => self::QUEUE]], $appQueue->pushed);
    }

    /** A builder on the default database, where the SQL backend keeps its table. */
    private function queueTable(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }

    private function clear(): void
    {
        $this->queueTable()->table('kinetis_queue_jobs')->where('queue', '=', self::QUEUE)->delete();
    }
}
