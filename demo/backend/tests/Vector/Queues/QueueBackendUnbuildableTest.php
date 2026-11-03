<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Queue\QueueInterface;

/** A queue backend whose factory throws — a broker it cannot reach while connecting: the overview lists every queue with no size and no clear, rather than a 500. */
final class QueueBackendUnbuildableTest extends QueuesTestCase
{
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->bind(QueueInterface::class, static function (): never {
            throw new \RuntimeException('Connection to tcp://10.0.0.9:6379 refused');
        });
    }

    public function testTheOverviewReportsNoSizeAndNoClear(): void
    {
        $this->client->get('/vector/queues', headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0', ['name' => 'qa-mail', 'size' => null, 'clearable' => false, 'failed' => 0]);
    }
}
