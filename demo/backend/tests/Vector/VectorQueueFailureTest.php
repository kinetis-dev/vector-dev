<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueryBuilder\Query;

/**
 * A push the backend refuses marks the job row failed. The backend's own
 * message names hosts, ports and sometimes credentials, and the row is
 * what the admin reads: it gets the generic line a failed job gets, and
 * the message goes to the log.
 */
final class VectorQueueFailureTest extends VectorTestCase
{
    private const string SECRET = 'Connection to tcp://10.20.30.40:6379 refused (AUTH s3cr3t-password)';

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        $app->instance(QueueInterface::class, new class (self::SECRET) implements QueueInterface {
            public function __construct(private readonly string $message) {}

            public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
            {
                throw new \RuntimeException($this->message);
            }

            public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
            {
                return null;
            }

            public function ack(QueuedJob $job): void {}

            public function release(QueuedJob $job, int $delaySeconds = 0): void {}

            public function fail(QueuedJob $job): void {}

            public function size(string $queue = 'default'): int
            {
                return 0;
            }
        });
    }

    public function testAFailedPushRecordsNoBackendDetailOnTheJob(): void
    {
        $mysql = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $mysql);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], (new Query($mysql))->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(2)->get());
        $before = (int) $this->vectorQuery()->table('vector_jobs')->max('id');

        $this->client->post('/vector/r/products/actions/reprice-products', body: ['ids' => $ids, 'fields' => ['percent' => 0]], headers: $this->realAdminHeaders())
            ->assertStatus(500);

        $job = $this->vectorQuery()->table('vector_jobs')->select('status', 'failure')->where('id', '>', $before)->orderBy('id', 'DESC')->first();
        self::assertIsArray($job);
        self::assertSame('failed', $job['status']);
        self::assertSame('The job failed unexpectedly; the server log has the details.', $job['failure']);
    }
}
