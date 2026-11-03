<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;

/** A backend that can neither clear nor count, the way SQS cannot clear: the overview says so, and a clear is refused with 409. */
final class QueueClearUnsupportedTest extends QueuesTestCase
{
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(QueueInterface::class, new class implements QueueInterface {
            public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void {}

            public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
            {
                return null;
            }

            public function ack(QueuedJob $job): void {}

            public function release(QueuedJob $job, int $delaySeconds = 0): void {}

            public function fail(QueuedJob $job): void {}

            public function size(string $queue = 'default'): int
            {
                throw new \RuntimeException('Connection to tcp://10.0.0.9:9324 refused');
            }
        });
    }

    public function testTheOverviewReportsNoSizeAndNoClear(): void
    {
        $this->client->get('/vector/queues', headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0', ['name' => 'qa-mail', 'size' => null, 'clearable' => false, 'failed' => 0]);
    }

    public function testAClearIsRefusedWith409(): void
    {
        $this->client->post('/vector/queues/qa-mail/clear', ['confirm' => 'qa-mail'], $this->adminHeaders())->assertStatus(409);
        self::assertSame([], $this->auditFor('qa-mail'));
    }
}
